<?php
/**
 * Welches Fahrzeug fährt auf welchem Zug — gelernt aus der Wagenreihung.
 *
 * DAS PROBLEM: Die Fahrplandaten nennen Gattung und Zugnummer, nie die
 * Baureihe. Ob der ICE 599 ein ICE 4 (BR 412) oder ein ICE 3neo (BR 408)
 * ist, steht nirgends im Fahrplan. Die Wagenreihung weiß es — aber nur für
 * deutschen Fernverkehr, nur am Reisetag, und CoachSequence fragt aus
 * Rücksicht auf bahn.expert höchstens drei Züge je Verbindung ab.
 *
 * DIE ANTWORT: merken. Jede beobachtete Baureihe landet hier unter ihrer
 * Zugnummer. Beim nächsten Mal — morgen, nächste Woche, oder für den
 * vierten Abschnitt, für den das Abfrage-Budget nicht mehr reichte — steht
 * sie ohne eine einzige weitere Anfrage bereit.
 *
 * Das ist derselbe Gedanke wie bei der Pünktlichkeitsstatistik: die App
 * wird mit der Nutzung besser, ohne dass jemand Daten einkaufen muss.
 *
 * WIE VERLÄSSLICH IST DAS? Umläufe sind stabil, aber nicht in Stein
 * gemeißelt: derselbe ICE 599 kann heute ein ICE 4 und in vier Wochen ein
 * ICE 3neo sein. Deshalb
 *
 *   - zählt nur die JÜNGSTE Beobachtung,
 *   - verfällt sie nach MAX_AGE_DAYS,
 *   - und das Ergebnis trägt `learned`, damit die Anzeige "aus früheren
 *     Fahrten" von "gerade nachgesehen" unterscheiden kann.
 *
 * SPEICHERFORM: eine JSON-Datei je Gattung im Cache-Verzeichnis. Kein
 * Datenbankserver, damit es auf jedem Shared Hosting läuft.
 */
final class Fleet
{
    /** Ältere Beobachtungen als das gelten nicht mehr. */
    private const MAX_AGE_DAYS = 90;

    /**
     * Bis zu diesem Alter reicht das Gelernte, ohne noch einmal nachzufragen.
     * Danach wird wieder nachgeschlagen - Umläufe wechseln zum Fahrplan-
     * wechsel, und zwei Wochen sind ein guter Kompromiss zwischen Frische
     * und Rücksicht auf die Quelle.
     */
    public const TRUST_DAYS = 14;

    /** Mehr Zugnummern je Gattung behalten wir nicht. */
    private const MAX_ENTRIES = 4000;

    private string $dir;
    private array $geladen = [];
    private array $schmutzig = [];

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, '/');
    }

    public function isAvailable(): bool
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    /**
     * Eine beobachtete Baureihe merken.
     *
     * @param string $series     "412"
     * @param string $seriesName "ICE 4 (BR412)"
     */
    public function record(string $category, string $number, string $series, string $seriesName): void
    {
        $cat = self::normCat($category);
        $num = trim($number);
        if ($cat === '' || $num === '' || $series === '') {
            return;
        }

        $daten = $this->load($cat);
        $daten[$num] = ['s' => $series, 'n' => $seriesName, 't' => time()];
        $this->geladen[$cat]   = $daten;
        $this->schmutzig[$cat] = true;
    }

    /**
     * Was wir über diesen Zug wissen — oder null.
     *
     * @return array{series:string,seriesName:string,age:int}|null
     */
    public function lookup(string $category, string $number): ?array
    {
        $cat = self::normCat($category);
        $num = trim($number);
        if ($cat === '' || $num === '') {
            return null;
        }

        $eintrag = $this->load($cat)[$num] ?? null;
        if (!is_array($eintrag) || ($eintrag['s'] ?? '') === '') {
            return null;
        }

        $alter = (int) floor((time() - (int) ($eintrag['t'] ?? 0)) / 86400);
        if ($alter > self::MAX_AGE_DAYS) {
            return null;
        }

        return [
            'series'     => (string) $eintrag['s'],
            'seriesName' => (string) ($eintrag['n'] ?? ('BR ' . $eintrag['s'])),
            'age'        => $alter,
        ];
    }

    /**
     * Füllt die Abschnitte mit dem, was wir schon wissen.
     *
     * LÄUFT VOR DER WAGENREIHUNG, nicht danach. Das ist der Punkt: was hier
     * steht, muss nicht noch einmal abgefragt werden. Bei sechs Verbindungen
     * mit je zwei Zügen spart das gut ein Dutzend Anfragen an einen privat
     * betriebenen Dienst - und ebenso viele Sekunden Ladezeit.
     */
    public function fill(array $journey): array
    {
        foreach (($journey['legs'] ?? []) as $i => $leg) {
            if (($leg['mode'] ?? '') !== 'train' || ($leg['series'] ?? '') !== '') {
                continue;
            }
            $treffer = $this->lookup(
                (string) ($leg['category'] ?? ''),
                (string) ($leg['trainNumber'] ?? '')
            );
            if ($treffer === null) {
                continue;
            }
            $journey['legs'][$i]['series']        = $treffer['series'];
            $journey['legs'][$i]['seriesName']    = $treffer['seriesName'];
            // Woher es kommt, gehört dazugesagt: gelernt ist nicht gemessen.
            $journey['legs'][$i]['seriesLearned'] = $treffer['age'];
        }

        return $journey;
    }

    /** Merkt sich, was die Wagenreihung frisch geliefert hat. */
    public function learn(array $journey): void
    {
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['mode'] ?? '') !== 'train' || ($leg['series'] ?? '') === '') {
                continue;
            }
            // Nur echte Beobachtungen, nicht das eigene Echo.
            if (isset($leg['seriesLearned'])) {
                continue;
            }
            $this->record(
                (string) ($leg['category'] ?? ''),
                (string) ($leg['trainNumber'] ?? ''),
                (string) $leg['series'],
                (string) ($leg['seriesName'] ?? '')
            );
        }
    }

    /** Schreibt aus, was sich geändert hat. */
    public function flush(): void
    {
        foreach (array_keys($this->schmutzig) as $cat) {
            $daten = $this->geladen[$cat] ?? [];

            // Verfallenes wegräumen und, falls es zu viel wird, die
            // ältesten Einträge fallen lassen.
            $grenze = time() - self::MAX_AGE_DAYS * 86400;
            $daten  = array_filter($daten, static fn($e) => (int) ($e['t'] ?? 0) >= $grenze);
            if (count($daten) > self::MAX_ENTRIES) {
                uasort($daten, static fn($a, $b) => ($b['t'] ?? 0) <=> ($a['t'] ?? 0));
                $daten = array_slice($daten, 0, self::MAX_ENTRIES, true);
            }

            $pfad = $this->pfad($cat);
            $json = json_encode($daten, JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                // Atomar schreiben: zwei gleichzeitige Anfragen dürfen sich
                // die Datei nicht gegenseitig zerreißen.
                $tmp = $pfad . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    @rename($tmp, $pfad);
                } else {
                    @unlink($tmp);
                }
            }
        }
        $this->schmutzig = [];
    }

    private function load(string $cat): array
    {
        if (isset($this->geladen[$cat])) {
            return $this->geladen[$cat];
        }
        $roh = @file_get_contents($this->pfad($cat));
        $daten = $roh === false ? [] : json_decode($roh, true);
        $this->geladen[$cat] = is_array($daten) ? $daten : [];
        return $this->geladen[$cat];
    }

    private function pfad(string $cat): string
    {
        return $this->dir . '/fleet-' . $cat . '.json';
    }

    /** Nur was als Dateiname taugt - die Gattung kommt aus fremden Daten. */
    private static function normCat(string $cat): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($cat))) ?? '';
    }
}
