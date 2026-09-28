<?php
/**
 * Die Live-Verfolgung auf dem Server - für Benachrichtigungen bei
 * gesperrtem Bildschirm.
 *
 * WARUM AUF DEM SERVER: Die Seite im Telefon prüft die Echtzeit nur, solange
 * sie läuft. Mit dunklem Bildschirm friert das Betriebssystem sie ein, und
 * auf dem iPhone sofort. Wer "Benachrichtigen" antippt, gibt die verfolgte
 * Fahrt deshalb hierher ab; ein Cronjob ruft einmal pro Minute tick() auf,
 * und der schickt per Web Push, was es zu sagen gibt.
 *
 * WAS GEMELDET WIRD:
 *   Erinnerungen, zeitgesteuert:
 *     Abfahrt  - 10 Min vor dem ersten Zug (Nahverkehr 5)
 *     Umstieg  - 5 Min vor der Ankunft am Umsteigebahnhof (Nahverkehr 2),
 *                mit Gleis, Anschlusszug und der Zeit zum Umsteigen
 *     Ankunft  - ebenso vor dem Ziel
 *   Änderungen, sobald sie auftauchen:
 *     Verspätung ab 5 Min, in Fünferstufen
 *     Gleiswechsel am Einstieg
 *     Ausfall eines Zuges
 *     Anschluss knapp (unter 2 Min) oder weg
 *
 * Fernverkehr bekommt mehr Vorlauf als die S-Bahn: bis Koffer und Jacke
 * zusammen sind und man an der Tür steht, vergehen im ICE ein paar Minuten;
 * in der S-Bahn steht man einfach auf.
 *
 * Jede Meldung trägt einen Schlüssel und eine Stufe; verschickt wird nur,
 * was neu ist oder eine höhere Stufe erreicht - dieselbe Regel wie in
 * live.js. Was beim Einschalten schon so ist, zählt als bekannt (man hat es
 * gerade auf dem Bildschirm gesehen).
 */
final class PushWatch
{
    /** Vorlauf der Erinnerungen in Minuten: [Fernverkehr, Nahverkehr]. */
    public const LEAD_START    = [10, 5];
    public const LEAD_ALIGHT   = [5, 2];

    /** Wie im Frontend (live.js): darunter wird ein Umstieg knapp. */
    public const SAFE_TRANSFER_MIN = 2;
    public const DELAY_STEP_MIN = 5;

    /** Höchstens so viele Anmeldungen - der Cache-Ordner ist kein Datengrab. */
    public const MAX_SUBSCRIPTIONS = 500;

    /** Gattungen, die als Fernverkehr gelten (mehr Vorlauf). */
    private const LONG = ['ICE', 'ICN', 'IC', 'EC', 'ECE', 'RJ', 'RJX', 'NJ', 'EN', 'TGV', 'FLX', 'WB', 'IR',
        'D', 'EST', 'THA', 'ES', 'FR', 'IRE', 'EUR'];

    private string $dir;

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, '/') . '/push';
    }

    public function isAvailable(): bool
    {
        return (is_dir($this->dir) || @mkdir($this->dir, 0775, true)) && is_writable($this->dir);
    }

    private function file(string $endpoint): string
    {
        return $this->dir . '/' . hash('sha256', $endpoint) . '.json';
    }

    public function count(): int
    {
        return count(glob($this->dir . '/*.json') ?: []);
    }

    /** Wann lief der Cronjob zuletzt? Für check.php. */
    public function lastTick(): ?int
    {
        $t = @file_get_contents($this->dir . '/.lasttick');
        return $t === false ? null : (int) $t;
    }

    /**
     * Eine Fahrt zur Überwachung ablegen - oder ersetzen, wenn dasselbe Gerät
     * schon eine hat (neue Verbindung übernommen, Rückfahrt …).
     *
     * @return bool true, wenn das Gerät neu ist
     */
    public function save(array $sub, array $journey): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $datei = $this->file((string) $sub['endpoint']);
        $neu = !is_file($datei);
        $alt = $neu ? [] : (json_decode((string) @file_get_contents($datei), true) ?: []);
        $gleicheFahrt = ($alt['journey']['id'] ?? null) === ($journey['id'] ?? null);
        $daten = [
            'sub'      => ['endpoint' => (string) $sub['endpoint'], 'keys' => [
                'p256dh' => (string) ($sub['keys']['p256dh'] ?? ''),
                'auth'   => (string) ($sub['keys']['auth'] ?? ''),
            ]],
            'journey'  => $journey,
            'created'  => $alt['created'] ?? time(),
            // Dieselbe Fahrt noch einmal angemeldet (Seite neu geladen): was
            // schon verschickt ist, nicht noch einmal schicken.
            'sent'     => $gleicheFahrt ? ($alt['sent'] ?? []) : [],
            'baseline' => $gleicheFahrt ? ($alt['baseline'] ?? false) : false,
        ];
        file_put_contents($datei, json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $neu;
    }

    public function remove(string $endpoint): bool
    {
        $datei = $this->file($endpoint);
        return is_file($datei) && @unlink($datei);
    }

    /**
     * Einmal alle Anmeldungen durchgehen. Vom Cronjob, jede Minute.
     *
     * @param int $interval Abstand der Aufrufe in Sekunden - bei einem
     *                      Fünf-Minuten-Cron kommen Erinnerungen entsprechend
     *                      früher, statt zu spät.
     * @param callable(array):array $realtime Ist-Lage eines Abschnitts, siehe pushRealtime()
     * @param callable(array,array):array $send  verschickt eine Nachricht, liefert {ok, gone}
     * @return array<string,int|bool>
     */
    public function tick(callable $realtime, callable $send, int $now, int $interval = 60): array
    {
        if (!$this->isAvailable()) {
            return ['error' => true];
        }
        $lock = fopen($this->dir . '/.tick.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['busy' => true];
        }
        @file_put_contents($this->dir . '/.lasttick', (string) $now);

        $frueh = max(0, $interval - 60);
        $stat = ['subscriptions' => 0, 'sent' => 0, 'removed' => 0];
        foreach (glob($this->dir . '/*.json') ?: [] as $datei) {
            $s = json_decode((string) @file_get_contents($datei), true);
            if (!is_array($s) || !isset($s['sub'], $s['journey'])) {
                @unlink($datei);
                continue;
            }
            $stat['subscriptions']++;

            $legs = self::normalize($s['journey']);
            $ende = $legs === [] ? 0 : ($legs[count($legs) - 1]['arrReal'] ?? $legs[count($legs) - 1]['arr']);
            // Angekommen (plus Luft) oder uralt: aufräumen.
            if ($legs === [] || $now > $ende + 1800 || $now - (int) ($s['created'] ?? $now) > 3 * 86400) {
                @unlink($datei);
                $stat['removed']++;
                continue;
            }

            // Echtzeit nur für die Abschnitte, um die es gerade geht: ab
            // anderthalb Stunden vor der Abfahrt bis zur Ankunft.
            foreach ($legs as $i => $l) {
                if ($now >= $l['dep'] - 5400 && $now <= ($l['arrReal'] ?? $l['arr']) + 300) {
                    $legs[$i] = array_merge($l, array_filter(
                        (array) $realtime($s['journey']['legs'][$l['idx']]),
                        static fn($v) => $v !== null
                    ));
                }
            }

            $meldungen = self::events($legs, $now, $frueh);
            $gesendet = $s['sent'] ?? [];
            if (empty($s['baseline'])) {
                // Erster Durchlauf: Änderungen, die schon bestehen, gelten
                // als gesehen. Erinnerungen bleiben - die sind zeitgesteuert.
                foreach ($meldungen as $m) {
                    if (!$m['reminder']) {
                        $gesendet[$m['key']] = max($gesendet[$m['key']] ?? 0, $m['level']);
                    }
                }
                $s['baseline'] = true;
            }
            foreach ($meldungen as $m) {
                if (($gesendet[$m['key']] ?? 0) >= $m['level']) {
                    continue;
                }
                $r = $send($s['sub'], [
                    'title' => $m['title'], 'body' => $m['body'], 'tag' => $m['tag'], 'url' => './',
                ]);
                if ($r['gone']) {
                    @unlink($datei);
                    $stat['removed']++;
                    continue 2;
                }
                if ($r['ok']) {
                    $gesendet[$m['key']] = $m['level'];
                    $stat['sent']++;
                }
            }
            $s['sent'] = $gesendet;
            file_put_contents($datei, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return $stat;
    }

    /**
     * Die Zugabschnitte einer Verbindung in der Form, mit der events()
     * rechnet. Fußwege hängen als Gehzeit am Zug davor.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $journey): array
    {
        $out = [];
        foreach ($journey['legs'] ?? [] as $idx => $leg) {
            if (($leg['mode'] ?? '') === 'walk') {
                if ($out !== [] && !empty($leg['changesPlace'])) {
                    $out[count($out) - 1]['walkAfter'] += (int) ($leg['durationMin'] ?? 0);
                }
                continue;
            }
            if (($leg['mode'] ?? '') !== 'train') {
                continue;
            }
            $dep = strtotime((string) ($leg['departure'] ?? ''));
            $arr = strtotime((string) ($leg['arrival'] ?? ''));
            if ($dep === false || $arr === false) {
                continue;
            }
            $cat = strtoupper(trim((string) ($leg['category'] ?? '')));
            $real = static fn($k) => ($t = strtotime((string) ($leg[$k] ?? ''))) === false ? null : $t;
            $out[] = [
                'idx'         => $idx,
                'label'       => self::label($leg),
                'long'        => in_array($cat, self::LONG, true),
                'direction'   => trim((string) ($leg['direction'] ?? '')),
                'fromName'    => (string) ($leg['from']['name'] ?? ''),
                'toName'      => (string) ($leg['to']['name'] ?? ''),
                'dep'         => $dep,
                'arr'         => $arr,
                'depReal'     => $real('departureReal'),
                'arrReal'     => $real('arrivalReal'),
                'platPlanned' => self::platform($leg['from']['platform'] ?? null),
                'plat'        => null,
                'arrPlat'     => self::platform($leg['to']['platform'] ?? null),
                'cancelled'   => !empty($leg['cancelled']),
                'walkAfter'   => 0,
            ];
        }
        return $out;
    }

    /**
     * Was ist gerade zu melden? Rein rechnerisch, ohne Netz - so testbar.
     *
     * @param int $frueh Sekunden, um die Erinnerungen früher fallen dürfen
     *                   (bei einem Cron seltener als jede Minute)
     * @return array<int,array{key:string,level:int,title:string,body:string,tag:string,reminder:bool}>
     */
    public static function events(array $legs, int $now, int $frueh = 0): array
    {
        $out = [];
        $n = count($legs);
        $uhr = static fn(int $t): string => (new DateTimeImmutable('@' . $t))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
        $minuten = static fn(int $t): int => max(0, (int) round(($t - $now) / 60));
        $gl = static fn(?string $p): string => $p !== null && $p !== '' ? ' · Gl. ' . $p : '';
        $add = static function (string $key, int $level, string $title, string $body, bool $reminder) use (&$out): void {
            $out[] = ['key' => $key, 'level' => $level, 'title' => $title, 'body' => $body,
                // Eine Sorte ersetzt die vorige auf dem Sperrbildschirm.
                'tag' => 'omnirail-' . explode('|', $key)[0] . (str_contains($key, '|') ? '-' . explode('|', $key)[1] : ''),
                'reminder' => $reminder];
        };

        foreach ($legs as $i => $l) {
            $ab = $l['depReal'] ?? $l['dep'];
            $an = $l['arrReal'] ?? $l['arr'];
            $plat = $l['plat'] ?? $l['platPlanned'];
            $vorlauf = static fn(array $lead) => ($l['long'] ? $lead[0] : $lead[1]) * 60;

            // --- Erinnerungen --------------------------------------------
            if ($i === 0 && !$l['cancelled'] && $now >= $ab - $vorlauf(self::LEAD_START) - $frueh && $now < $ab) {
                $add('start', 1, 'Abfahrt in ' . $minuten($ab) . ' Min',
                    $l['label'] . ($l['direction'] !== '' ? ' → ' . $l['direction'] : '')
                    . ' · ' . $uhr($ab) . $gl($plat) . ' · ' . $l['fromName'], true);
            }
            if ($now >= $an - $vorlauf(self::LEAD_ALIGHT) - $frueh && $now < $an + 120 && !$l['cancelled']) {
                $wann = $minuten($an) > 0 ? 'in ' . $minuten($an) . ' Min' : 'jetzt';
                if ($i < $n - 1) {
                    $weiter = $legs[$i + 1];
                    $weiterAb = $weiter['depReal'] ?? $weiter['dep'];
                    $zeit = (int) floor(($weiterAb - $an) / 60) - $l['walkAfter'];
                    $body = 'Ankunft ' . $uhr($an) . $gl($l['arrPlat'])
                        . ($l['walkAfter'] > 0 ? ' · ' . $l['walkAfter'] . ' Min zu Fuß nach ' . $weiter['fromName'] : '')
                        . ' → ' . $weiter['label'] . ($weiter['direction'] !== '' ? ' nach ' . $weiter['direction'] : '')
                        . ' um ' . $uhr($weiterAb) . $gl($weiter['plat'] ?? $weiter['platPlanned'])
                        . ($weiter['cancelled'] ? ' — fällt aus, in der App nach Alternativen schauen'
                            : ' (' . max(0, $zeit) . ' Min zum Umsteigen)');
                    $add('umstieg|' . $i, 1, 'Umstieg ' . $wann . ': ' . $l['toName'], $body, true);
                } else {
                    $add('ziel', 1, 'Ankunft ' . $wann . ': ' . $l['toName'], $uhr($an) . $gl($l['arrPlat']), true);
                }
            }

            // --- Änderungen (nur, was noch vor einem liegt) ----------------
            if ($now >= $an) {
                continue;
            }
            if ($l['cancelled']) {
                $add('ausfall|' . $i, 3, $l['label'] . ' fällt aus',
                    'Zwischen ' . $l['fromName'] . ' und ' . $l['toName'] . '. In der App nach Alternativen schauen.', false);
                continue;
            }
            $verspaetung = $l['arrReal'] !== null ? (int) round(($l['arrReal'] - $l['arr']) / 60)
                : ($l['depReal'] !== null ? (int) round(($l['depReal'] - $l['dep']) / 60) : 0);
            if ($verspaetung >= self::DELAY_STEP_MIN) {
                $add('delay|' . $i, intdiv($verspaetung, self::DELAY_STEP_MIN) * self::DELAY_STEP_MIN,
                    $l['label'] . ': +' . $verspaetung . ' Min',
                    'Ankunft ' . $l['toName'] . ' jetzt ' . $uhr($an) . '.', false);
            }
            if ($now < $ab && $l['plat'] !== null && $l['platPlanned'] !== null && $l['plat'] !== $l['platPlanned']) {
                $add('gleis|' . $i . '|' . $l['plat'], 1, 'Gleiswechsel: ' . $l['label'],
                    'In ' . $l['fromName'] . ' jetzt Gleis ' . $l['plat'] . ' statt ' . $l['platPlanned'] . '.', false);
            }
            if ($i < $n - 1) {
                $weiter = $legs[$i + 1];
                $weiterAb = $weiter['depReal'] ?? $weiter['dep'];
                if ($now < $weiterAb && !$weiter['cancelled']) {
                    $luecke = (int) floor(($weiterAb - $an) / 60) - $l['walkAfter'];
                    if ($luecke < self::SAFE_TRANSFER_MIN) {
                        $weg = $luecke < 0;
                        $add('risk|' . $i, $weg ? 2 : 1, $weg ? 'Anschluss weg' : 'Anschluss wird knapp',
                            $weiter['label'] . ' in ' . $weiter['fromName'] . ' fährt ' . $uhr($weiterAb)
                            . ($weg ? ' — ' . abs($luecke) . ' Min vor deiner Ankunft. In der App nach Alternativen schauen.'
                                : ' — nur ' . max(0, $luecke) . ' Min zum Umsteigen.'), false);
                    }
                }
            }
        }
        return $out;
    }

    /** "ICE 522", "S8", "Tram 19", "U6" - wie trainLabel() im Frontend. */
    public static function label(array $leg): string
    {
        $cat = trim((string) ($leg['category'] ?? ''));
        $line = trim((string) ($leg['line'] ?? ''));
        $num = trim((string) ($leg['trainNumber'] ?? ''));
        if ($line !== '' && !ctype_digit($line)) {
            return $line;                                   // "S8", "RE5", "U6"
        }
        if ($line !== '' && $cat !== '') {
            return $cat . ' ' . $line;                      // "Tram 19", "Bus 68"
        }
        if ($cat !== '' && $num !== '') {
            return $cat . ' ' . $num;                       // "ICE 522"
        }
        return trim((string) ($leg['name'] ?? '')) ?: ($cat !== '' ? $cat : 'Zug');
    }

    private static function platform($p): ?string
    {
        $p = trim((string) $p);
        return $p === '' ? null : $p;
    }

    /**
     * Die Ist-Lage eines Abschnitts aus einem Zuglauf im App-Format: Abfahrt
     * am Einstieg, Ankunft am Ausstieg, Gleise, Ausfall.
     *
     * @return array{depReal:?int,arrReal:?int,plat:?string,arrPlat:?string,cancelled:bool}|array{}
     */
    public static function fromRun(array $leg, array $run): array
    {
        $stops = $run['stops'] ?? [];
        $finde = static function (array $ort, int $ab) use ($stops): ?int {
            foreach ($stops as $k => $s) {
                if ($k < $ab) {
                    continue;
                }
                if ((string) ($ort['id'] ?? '') !== '' && (string) ($s['id'] ?? '') === (string) $ort['id']) {
                    return $k;
                }
            }
            foreach ($stops as $k => $s) {
                if ($k >= $ab && (string) ($ort['name'] ?? '') !== '' && ($s['name'] ?? '') === $ort['name']) {
                    return $k;
                }
            }
            return null;
        };
        $a = $finde($leg['from'] ?? [], 0);
        $b = $finde($leg['to'] ?? [], $a ?? 0);
        if ($a === null && $b === null) {
            return [];
        }
        $zeit = static fn($iso) => ($t = strtotime((string) $iso)) === false ? null : $t;
        $ein = $a !== null ? $stops[$a] : [];
        $aus = $b !== null ? $stops[$b] : [];
        return [
            'depReal'   => $zeit($ein['departureReal'] ?? null),
            'arrReal'   => $zeit($aus['arrivalReal'] ?? null),
            'plat'      => self::platform($ein['platform'] ?? null),
            'arrPlat'   => self::platform($aus['platform'] ?? null),
            'cancelled' => !empty($run['cancelled']) || !empty($ein['cancelled']) || !empty($aus['cancelled']),
        ];
    }
}
