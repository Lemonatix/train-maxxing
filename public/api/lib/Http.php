<?php
/**
 * Duenner cURL-Wrapper.
 *
 * Gibt bewusst NIE eine Exception zurueck, sondern immer ein Array mit
 * status/body/error. So kann jeder Provider selbst entscheiden, ob ein
 * Fehlschlag fatal ist oder ob degradiert weitergearbeitet wird.
 *
 * Nicht final: fuer Tests darf eine Ableitung getJson/postJson ueberschreiben
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
     * cURL/OpenSSL antwortet bahn.de mit 403 "OPS_BLOCKED" - unabhaengig von
     * IP, User-Agent und allen anderen Headern. Setzt man diese Reihenfolge,
     * geht dieselbe Anfrage durch.
     *
     * Nachgemessen: ohne Cipher-Liste 403, mit Cipher-Liste 200 (5/5 Versuche),
     * und zwar sogar ganz ohne User-Agent.
     */
    private const TLS12_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';

    /** TLS 1.3 wird in cURL ueber eine eigene Option gesetzt. */
    private const TLS13_CIPHERS = 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256';

    private int $timeout;
    private bool $browserTls;

    /**
     * @param bool $browserTls Browser-Cipher-Reihenfolge verwenden. Fuer die
     *                         DB zwingend, fuer andere Hosts unschaedlich.
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
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return $this->fail('cURL ist auf diesem Server nicht verfuegbar.');
        }

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
            // TLS-1.2-Suiten: von OpenSSL/cURL ueberall unterstuetzt.
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

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return $this->fail($err !== '' ? $err : 'Unbekannter Netzwerkfehler', $status);
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

    /** @param array<string,string> $headers */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers + ['Accept' => 'application/json']);
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
