<?php
/**
 * Push an die Android-App über Firebase Cloud Messaging (HTTP v1).
 *
 * WARUM: Die App läuft in einem WebView, und dort gibt es kein Web Push.
 * Die App meldet sich mit einem FCM-Token an; gespeichert wird es wie eine
 * Web-Push-Anmeldung mit dem Endpunkt "fcm:<token>", und WebPush::send()
 * reicht solche hierher weiter. Alles andere (PushWatch, Takt) bleibt gleich.
 *
 * EINRICHTEN: In der Firebase-Konsole → Projekteinstellungen → Dienstkonten
 * → "Neuen privaten Schlüssel generieren". Die JSON-Datei NICHT ins Repo,
 * sondern auf den Server, und in config.local.php:
 *   'push' => [..., 'fcm_service_account' => __DIR__ . '/fcm-service-account.json'],
 */
final class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private Http $http;
    private ?array $konto;
    private string $cacheDir;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cacheDir = (string) ($cfg['cache_dir'] ?? sys_get_temp_dir());
        $quelle = $cfg['fcm_service_account'] ?? null;
        $json = is_array($quelle) ? $quelle
            : (is_string($quelle) && is_file($quelle) ? json_decode((string) file_get_contents($quelle), true) : null);
        $this->konto = is_array($json) && isset($json['client_email'], $json['private_key'], $json['project_id']) ? $json : null;
    }

    public function isConfigured(): bool
    {
        return $this->konto !== null && function_exists('openssl_sign');
    }

    /** Ist das ein FCM-Token in der Form, die die App schickt? */
    public static function validToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_:\-\.]{20,4096}$/', $token);
    }

    /**
     * @param array{title?:string,body?:string,tag?:string,url?:string} $message
     * @return array{ok:bool,status:int,gone:bool}
     */
    public function send(string $token, array $message, int $ttl = 900): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'gone' => false];
        }
        $zugang = $this->accessToken();
        if ($zugang === null) {
            return ['ok' => false, 'status' => 0, 'gone' => false];
        }
        $tag = (string) ($message['tag'] ?? 'train-maxxing');
        $nachricht = [
            'message' => [
                'token'        => $token,
                'notification' => [
                    'title' => (string) ($message['title'] ?? 'train-maxxing'),
                    'body'  => (string) ($message['body'] ?? ''),
                ],
                'data'    => ['url' => (string) ($message['url'] ?? './'), 'tag' => $tag],
                'android' => [
                    'priority'     => 'high',
                    'ttl'          => max(0, $ttl) . 's',
                    // Dieselbe Kennung ersetzt die vorige Meldung, statt sie zu stapeln.
                    'notification' => ['tag' => $tag, 'channel_id' => 'reise'],
                ],
            ],
        ];
        $res = $this->http->request(
            'POST',
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) $this->konto['project_id']) . '/messages:send',
            ['Authorization' => 'Bearer ' . $zugang, 'Content-Type' => 'application/json'],
            (string) json_encode($nachricht, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        // 404 UNREGISTERED: App gelöscht oder Token erneuert.
        $weg = $res['status'] === 404 || str_contains((string) ($res['body'] ?? ''), 'UNREGISTERED');
        return ['ok' => $res['ok'], 'status' => $res['status'], 'gone' => $weg];
    }

    /** OAuth-Zugang aus dem Dienstkonto, knapp eine Stunde gemerkt. */
    private function accessToken(): ?string
    {
        $datei = $this->cacheDir . '/fcm-token-' . substr(sha1((string) $this->konto['client_email']), 0, 12) . '.json';
        $alt = is_file($datei) ? json_decode((string) @file_get_contents($datei), true) : null;
        if (is_array($alt) && ($alt['exp'] ?? 0) > time() + 120) {
            return (string) $alt['token'];
        }
        $jetzt = time();
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $kopf = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $inhalt = $b64((string) json_encode([
            'iss'   => $this->konto['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $this->konto['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $jetzt,
            'exp'   => $jetzt + 3600,
        ]));
        $sig = '';
        if (!openssl_sign($kopf . '.' . $inhalt, $sig, (string) $this->konto['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $res = $this->http->request(
            'POST',
            (string) ($this->konto['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $kopf . '.' . $inhalt . '.' . $b64($sig),
            ])
        );
        $json = json_decode((string) ($res['body'] ?? ''), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            error_log('[train-maxxing] FCM-Zugang fehlgeschlagen: HTTP ' . $res['status']);
            return null;
        }
        @file_put_contents($datei, json_encode(['token' => $token, 'exp' => $jetzt + (int) ($json['expires_in'] ?? 3600)]), LOCK_EX);
        return $token;
    }
}
