<?php
/**
 * Dünner cURL-Wrapper.
 *
 * Gibt bewusst NIE eine Exception zurück, sondern immer ein Array mit
 * status/body/error. So kann jeder Provider selbst entscheiden, ob ein
 * Fehlschlag fatal ist oder ob degradiert weitergearbeitet wird.
 *
 * Nicht final: für Tests darf eine Ableitung getJson/postJson überschreiben
 * und ohne echtes Netzwerk arbeiten. Zur Laufzeit gibt es dennoch nur diese
 * eine Ausprägung.
 */
class Http
{
    /**
     * TLS-1.2-Cipher-Reihenfolge eines Chrome-Browsers.
     *
     * HINTERGRUND: Die DB setzt Akamai Bot Manager ein, der den TLS-ClientHello
     * auswertet (JA3-Fingerprint). Mit der Standard-Cipher-Reihenfolge von
     * cURL/OpenSSL antwortet bahn.de mit 403 "OPS_BLOCKED" - unabhängig von
     * IP, User-Agent und allen anderen Headern. Setzt man diese Reihenfolge,
     * geht dieselbe Anfrage durch.
     *
     * Nachgemessen: ohne Cipher-Liste 403, mit Cipher-Liste 200 (5/5 Versuche),
     * und zwar sogar ganz ohne User-Agent.
     */
    private const TLS12_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';

    /** TLS 1.3 wird in cURL über eine eigene Option gesetzt. */
    private const TLS13_CIPHERS = 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256';

    private int $timeout;
    private bool $browserTls;

    /**
     * @param bool $browserTls Browser-Cipher-Reihenfolge verwenden. Für die
     *                         DB zwingend, für andere Hosts unschädlich.
     */
    public function __construct(int $timeout = 25, bool $browserTls = false)
    {
        $this->timeout    = $timeout;
        $this->browserTls = $browserTls;
    }

    /** Liefert eine Kopie dieses Clients mit Browser-TLS-Profil. */
    public function withBrowserTls(): self
    {
        return new self($this->timeout, true);
    }

    /**
     * Aufzeichnen und Vorab-Laden - für Abfragen, die mehrere Quellen
     * NACHEINANDER fragen, obwohl keine auf die andere wartet.
     *
     * WOZU: Die Ortssuche fragt DB, ÖBB und MVG. Nacheinander addierten sich
     * deren Antwortzeiten zu einer Sekunde, bevor beim Tippen von "Mün" der
     * erste Vorschlag erschien. Statt jeden Anbieter in "Anfrage bauen" und
     * "Antwort lesen" zu zerlegen, läuft die Abfrage zweimal:
     *
     *   1. record(): die Anbieter laufen, request() schreibt nur auf, was es
     *      schicken würde, und meldet einen Fehler zurück.
     *   2. prefetch(): alles Aufgeschriebene geht GLEICHZEITIG raus.
     *   3. Die Anbieter laufen noch einmal; request() findet jede Antwort
     *      schon vor und gibt sie sofort zurück.
     *
     * Fragt ein Anbieter in Schritt 3 etwas, das nicht aufgezeichnet war
     * (eine Folgeanfrage), geht diese ganz normal raus. Langsamer, nie falsch.
     *
     * @var array<string,array{0:Http,1:string,2:string,3:array<string,string>,4:?string}>|null
     */
    private static ?array $recorded = null;
    /** @var array<string,array> Antworten aus prefetch(), je einmal abholbar */
    private static array $prefetched = [];

    private static function specKey(string $method, string $url, ?string $body): string
    {
        return $method . ' ' . $url . ' ' . hash('sha256', (string) $body);
    }

    /**
     * Führt `$fn` aus und liefert die Anfragen, die dabei gestellt würden.
     *
     * @return array<string,array{0:Http,1:string,2:string,3:array<string,string>,4:?string}>
     */
    public static function record(callable $fn): array
    {
        self::$recorded = [];
        try {
            $fn();
            return self::$recorded;
        } finally {
            self::$recorded = null;
        }
    }

    /**
     * Aufgezeichnete Anfragen gleichzeitig ausführen - siehe record().
     *
     * @param float $deadline Sekunden; danach wird auf Nachzügler nicht mehr
     *                        gewartet, sobald das Fertige reicht. 0 = auf alle
     *                        warten. Die Nachzügler bekommen einen Fehler ("zu
     *                        langsam"), der Anbieter meldet sich dann wie bei
     *                        jedem anderen Ausfall.
     * @param ?callable $enough bekommt die fertigen Antworten und sagt, ob sie
     *                        reichen. Ohne: sobald eine gut zurück ist.
     * @return bool ob alle Anfragen fertig wurden
     */
    public static function prefetch(array $specs, float $deadline = 0, ?callable $enough = null): bool
    {
        if ($specs === [] || !function_exists('curl_multi_init')) {
            return true;
        }
        $start = microtime(true);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($specs as $key => [$client, $method, $url, $headers, $body]) {
            $ch = $client->handle($method, $url, $headers, $body);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = [$ch, $url];
        }
        $fertig = [];
        $gut = false;
        do {
            $status = curl_multi_exec($multi, $laufen);
            while (($info = curl_multi_info_read($multi)) !== false) {
                foreach ($handles as $key => [$ch, $url]) {
                    if ($ch === $info['handle']) {
                        $raw = curl_multi_getcontent($ch);
                        curl_multi_remove_handle($multi, $ch);
                        $res = self::finish($ch, $url, $info['result'] === CURLE_OK && $raw !== null ? $raw : false);
                        self::$prefetched[$key] = $res;
                        $fertig[$key] = true;
                        $gut = $gut || $res['ok'];
                    }
                }
            }
            if ($laufen && $deadline > 0 && $gut && microtime(true) - $start > $deadline
                && ($enough === null || $enough(array_intersect_key(self::$prefetched, $fertig)))) {
                break;
            }
            if ($laufen) {
                curl_multi_select($multi, 0.1);
            }
        } while ($laufen && $status === CURLM_OK);

        foreach ($handles as $key => [$ch, $url]) {
            if (!isset($fertig[$key])) {
                curl_multi_remove_handle($multi, $ch);
                Health::note($url, false, 'zu langsam');
                self::$prefetched[$key] = ['ok' => false, 'status' => 0, 'body' => '',
                    'error' => 'zu langsam', 'json' => null];
            }
        }
        curl_multi_close($multi);
        return count($fertig) === count($handles);
    }

    /** Ein fertig eingestelltes cURL-Handle für eine Anfrage. */
    private function handle(string $method, string $url, array $headers, ?string $body): CurlHandle
    {
        $ch = curl_init();
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '', // gzip/deflate automatisch
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($this->browserTls) {
            // TLS-1.2-Suiten: von OpenSSL/cURL überall unterstützt.
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, self::TLS12_CIPHERS);
            // TLS 1.3 braucht cURL >= 7.61 mit OpenSSL >= 1.1.1. Fehlt die
            // Konstante, bleibt es bei der Standardreihenfolge - dann greift
            // im Zweifel wieder das Blocking, aber nichts bricht.
            if (defined('CURLOPT_TLS13_CIPHERS')) {
                curl_setopt($ch, CURLOPT_TLS13_CIPHERS, self::TLS13_CIPHERS);
            }
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        return $ch;
    }

    /** Antwort eines ausgeführten Handles in die übliche Form bringen. */
    private static function finish(CurlHandle $ch, string $url, string|bool $raw): array
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);

        // Jeden Aufruf nach draußen verbuchen - siehe Health.php. Der Haken
        // sitzt hier und nicht an den Aufrufstellen, weil er sonst genau die
        // Provider verpasst, die sich ihren Client selbst bauen.
        Health::note($url, $raw !== false && $status >= 200 && $status < 400,
            $err !== '' ? $err : ('HTTP ' . $status));

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'body' => '',
                'error' => $err !== '' ? $err : 'Unbekannter Netzwerkfehler', 'json' => null];
        }

        $json = null;
        $decoded = json_decode((string) $raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = $decoded;
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $raw,
            'error'  => null,
            'json'   => $json,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return $this->fail('cURL ist auf diesem Server nicht verfügbar.');
        }

        $key = self::specKey($method, $url, $body);
        if (self::$recorded !== null) {
            self::$recorded[$key] = [$this, $method, $url, $headers, $body];
            return $this->fail('aufgezeichnet');
        }
        if (isset(self::$prefetched[$key])) {
            $res = self::$prefetched[$key];
            unset(self::$prefetched[$key]);
            return $res;
        }

        $ch  = $this->handle($method, $url, $headers, $body);
        $raw = curl_exec($ch);
        // Kein curl_close(): seit PHP 8.0 wirkungslos, seit 8.5 gibt es dafür
        // eine Deprecation-Warnung. Die landete mitten in der JSON-Antwort und
        // machte damit JEDEN API-Aufruf auf PHP 8.5 unbrauchbar. Das Handle
        // räumt der Garbage Collector auf.
        return self::finish($ch, $url, $raw);
    }

    /** @param array<string,string> $headers */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers + ['Accept' => 'application/json']);
    }

    /**
     * Mehrere GET-Anfragen GLEICHZEITIG.
     *
     * WOZU: Die Wagenreihung braucht eine Anfrage je Zug. Nacheinander sind
     * zwölf Züge zwölf Round-Trips - nachgemessen neunzehn Sekunden, und die
     * Trefferliste wartete so lange auf eine Angabe, die nur ein Zusatz ist.
     * Parallel kostet dasselbe knapp zwei.
     *
     * Die Rückgabe behält die Schlüssel der Eingabe, damit die Zuordnung
     * nicht über die Reihenfolge laufen muss - bei curl_multi kommen die
     * Antworten in beliebiger Reihenfolge zurück.
     *
     * @param array<string|int,string> $urls    Schlüssel => URL
     * @param array<string,string>     $headers für alle Anfragen dieselben
     * @return array<string|int,array{ok:bool,status:int,body:string,error:?string,json:?array}>
     */
    public function getJsonAll(array $urls, array $headers = [], int $parallel = 8): array
    {
        if ($urls === []) {
            return [];
        }
        if (!function_exists('curl_multi_init')) {
            // Ohne curl_multi eben nacheinander - langsam, aber richtig.
            $out = [];
            foreach ($urls as $k => $url) {
                $out[$k] = $this->getJson($url, $headers);
            }
            return $out;
        }

        $hdr = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        $out    = [];
        $stapel = array_chunk($urls, max(1, $parallel), true);

        foreach ($stapel as $teil) {
            $multi   = curl_multi_init();
            $handles = [];

            foreach ($teil as $k => $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HTTPHEADER     => $hdr,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                // Dasselbe Browser-Profil wie in request() - ohne blockt
                // bahn.de auch die gleichzeitigen Anfragen (Wagenreihung).
                if ($this->browserTls) {
                    curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, self::TLS12_CIPHERS);
                    if (defined('CURLOPT_TLS13_CIPHERS')) {
                        curl_setopt($ch, CURLOPT_TLS13_CIPHERS, self::TLS13_CIPHERS);
                    }
                }
                curl_multi_add_handle($multi, $ch);
                $handles[$k] = $ch;
            }

            do {
                $status = curl_multi_exec($multi, $laufen);
                if ($laufen) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($laufen && $status === CURLM_OK);

            foreach ($handles as $k => $ch) {
                $raw    = curl_multi_getcontent($ch);
                $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err    = curl_error($ch);
                curl_multi_remove_handle($multi, $ch);

                Health::note((string) $teil[$k], $raw !== null && $raw !== false
                    && $code >= 200 && $code < 300, $err !== '' ? $err : ('HTTP ' . $code));

                if ($raw === null || $raw === false || $code < 200 || $code >= 300) {
                    $out[$k] = $this->fail($err !== '' ? $err : 'HTTP ' . $code, $code);
                    continue;
                }
                $decoded = json_decode((string) $raw, true);
                $out[$k] = [
                    'ok'     => true,
                    'status' => $code,
                    'body'   => (string) $raw,
                    'error'  => null,
                    'json'   => is_array($decoded) ? $decoded : null,
                ];
            }
            curl_multi_close($multi);
        }

        return $out;
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->request('POST', $url, $headers + [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Accept'       => 'application/json',
        ], $encoded === false ? '{}' : $encoded);
    }

    /** @return array{ok:bool,status:int,body:string,error:string,json:null} */
    private function fail(string $message, int $status = 0): array
    {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $message, 'json' => null];
    }
}
