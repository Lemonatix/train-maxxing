<?php
/**
 * Web Push ohne Bibliothek: Benachrichtigungen, die auch bei gesperrtem
 * Bildschirm ankommen.
 *
 * WIE ES GEHT: Der Browser meldet sich beim Push-Dienst seines Herstellers an
 * (Google FCM, Apple, Mozilla) und gibt der App eine Adresse ("endpoint")
 * und zwei Schlüssel. Wer an diese Adresse eine verschlüsselte Nachricht
 * schickt, weckt das Telefon - auch wenn die Seite längst geschlossen ist.
 * Der Service Worker (sw.js) zeigt sie dann an.
 *
 * ZWEI STANDARDS, beide hier von Hand, weil auf einem Webspace kein
 * Composer läuft:
 *   VAPID (RFC 8292)   - der Server weist sich mit einem eigenen
 *                        Schlüsselpaar aus (ES256-signiertes JWT). Den
 *                        öffentlichen Teil bekommt der Browser beim Anmelden.
 *   aes128gcm (RFC 8291/8188) - die Nachricht wird für genau dieses Gerät
 *                        verschlüsselt: ECDH mit dessen Schlüssel, HKDF,
 *                        AES-128-GCM. Der Push-Dienst sieht nur Rauschen.
 *
 * Schlüssel: config.local.php, 'push' => ['public' => …, 'private_pem' => …].
 * Erzeugt werden sie mit `php bin/make_push_keys.php`.
 */
final class WebPush
{
    /** SubjectPublicKeyInfo-Kopf für einen unkomprimierten P-256-Punkt. */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Push-Dienste, an die der Server schicken darf. Die Adresse kommt vom
     * Browser - ohne diese Liste wäre der Server ein offenes Relais, das an
     * jede beliebige URL POSTet.
     */
    private const DIENSTE = [
        '/(^|\.)fcm\.googleapis\.com$/',
        '/(^|\.)push\.apple\.com$/',
        '/(^|\.)push\.services\.mozilla\.com$/',
        '/(^|\.)notify\.windows\.com$/',
    ];

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['public'] ?? '')) !== ''
            && trim((string) ($this->cfg['private_pem'] ?? '')) !== ''
            && function_exists('openssl_pkey_derive');
    }

    public function publicKey(): string
    {
        return (string) ($this->cfg['public'] ?? '');
    }

    /** Ist das eine Adresse, an die wir schicken dürfen? */
    public static function allowedEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || !isset($p['host'])) {
            return false;
        }
        foreach (self::DIENSTE as $muster) {
            if (preg_match($muster, strtolower($p['host'])) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Eine Nachricht an ein Gerät schicken.
     *
     * @param array{endpoint:string,keys:array{p256dh:string,auth:string}} $sub
     * @param array<string,mixed> $message wird als JSON verschickt (title, body, tag, url)
     * @return array{ok:bool,status:int,gone:bool}
     */
    public function send(array $sub, array $message, int $ttl = 900, string $urgency = 'high'): array
    {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if (!self::allowedEndpoint($endpoint)) {
            return ['ok' => false, 'status' => 0, 'gone' => true];
        }
        $body = self::encrypt(
            (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::ub64((string) ($sub['keys']['p256dh'] ?? '')),
            self::ub64((string) ($sub['keys']['auth'] ?? ''))
        );
        $p = parse_url($endpoint);
        $jwt = self::vapidJwt(
            $p['scheme'] . '://' . $p['host'],
            (string) ($this->cfg['subject'] ?? 'https://github.com/'),
            (string) $this->cfg['private_pem'],
            time() + 12 * 3600
        );
        $headers = [
            'Authorization'    => 'vapid t=' . $jwt . ', k=' . $this->publicKey(),
            'Content-Encoding' => 'aes128gcm',
            'Content-Type'     => 'application/octet-stream',
            'TTL'              => (string) $ttl,
            'Urgency'          => $urgency,
        ];
        $res = $this->http->request('POST', $endpoint, $headers, $body);
        // 404/410: das Gerät hat sich abgemeldet oder die App gelöscht.
        return ['ok' => $res['ok'], 'status' => $res['status'], 'gone' => in_array($res['status'], [404, 410], true)];
    }

    // -----------------------------------------------------------------
    // Verschlüsselung (RFC 8291, Inhaltskodierung aes128gcm nach RFC 8188)
    // -----------------------------------------------------------------

    /**
     * @param string $uaPublic   65 Byte, unkomprimierter Punkt des Geräts (p256dh)
     * @param string $authSecret 16 Byte (auth)
     * @param ?array{salt:string,key:mixed} $fest nur für Tests: Salz und Absenderschlüssel vorgeben
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?array $fest = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) {
            throw new InvalidArgumentException('Ungültige Schlüssel des Geräts');
        }
        $asKey = $fest['key'] ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($asKey === false) {
            throw new RuntimeException('OpenSSL kann keinen EC-Schlüssel erzeugen');
        }
        $asPublic = self::point($asKey);
        $geteilt = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $asKey);
        if ($geteilt === false) {
            throw new RuntimeException('ECDH fehlgeschlagen');
        }
        $salz = $fest['salt'] ?? random_bytes(16);

        // IKM = HKDF(salt=auth, ikm=ecdh, info="WebPush: info\0" || ua || as)
        $ikm = hash_hkdf('sha256', $geteilt, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salz);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salz);

        // Ein einziger Datensatz: Inhalt, dann 0x02 als "letzter Datensatz".
        $tag = '';
        $chiffre = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($chiffre === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen');
        }
        // Kopf: salt(16) | rs(4) | idlen(1) | keyid(65)
        return $salz . pack('N', 4096) . chr(65) . $asPublic . $chiffre . $tag;
    }

    /** Gegenstück zu encrypt() - für die Tests. */
    public static function decrypt(string $body, $uaPrivate, string $authSecret): ?string
    {
        $salz = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $rest = substr($body, 21 + $idLen);
        $uaPublic = self::point($uaPrivate);
        $geteilt = openssl_pkey_derive(self::publicKeyFromPoint($asPublic), $uaPrivate);
        if ($geteilt === false) {
            return null;
        }
        $ikm = hash_hkdf('sha256', $geteilt, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salz);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salz);
        $klar = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
        return $klar === false ? null : rtrim($klar, "\x00\x02");
    }

    // -----------------------------------------------------------------
    // VAPID
    // -----------------------------------------------------------------

    /** ES256-signiertes JWT für den Push-Dienst. */
    public static function vapidJwt(string $audience, string $subject, string $privatePem, int $exp): string
    {
        $kopf = self::b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $inhalt = self::b64u((string) json_encode(['aud' => $audience, 'exp' => $exp, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $daten = $kopf . '.' . $inhalt;
        $der = '';
        if (!openssl_sign($daten, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID-Signatur fehlgeschlagen');
        }
        return $daten . '.' . self::b64u(self::derToRaw($der));
    }

    /**
     * Ein neues VAPID-Schlüsselpaar.
     *
     * @return array{public:string,private_pem:string}
     */
    public static function generateKeys(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $pem = '';
        openssl_pkey_export($k, $pem);
        return ['public' => self::b64u(self::point($k)), 'private_pem' => $pem];
    }

    // -----------------------------------------------------------------
    // Kleinkram
    // -----------------------------------------------------------------

    /** Der öffentliche Punkt eines EC-Schlüssels, unkomprimiert (65 Byte). */
    private static function point($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? [];
        return "\x04" . str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKeyFromPoint(string $point)
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $point), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new InvalidArgumentException('Kein gültiger P-256-Punkt');
        }
        return $k;
    }

    /** DER-Signatur (SEQUENCE { r, s }) -> r || s mit je 32 Byte. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos += ord($der[1]) & 0x7f;
        }
        $teile = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $zahl = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $teile[] = str_pad(ltrim($zahl, "\0"), 32, "\0", STR_PAD_LEFT);
        }
        return $teile[0] . $teile[1];
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function ub64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
