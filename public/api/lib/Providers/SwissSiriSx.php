<?php
/**
 * Geplante Bauarbeiten auf Schweizer Bahnstrecken - aus den Ereignis-
 * meldungen des öffentlichen Verkehrs (SIRI-SX, opentransportdata.swiss).
 *
 * WOHER: Der Datensatz "komplett" (la/siri-sx) enthält alle veröffentlichten
 * Ereignisse der Schweizer Transportunternehmen, geplante wie ungeplante -
 * rund tausend, davon gut sechshundert Bauarbeiten. Fast alle betreffen
 * Busse und Trams; übrig bleiben ein paar Dutzend Bahnstrecken, z. B.
 * Bern - Thun (RE1, S-Bahn) oder Basel - Laufen (IR56).
 *
 * GROSS: entpackt rund 140 MB XML. Deshalb auf die Platte statt in den
 * Speicher, Element für Element gelesen (XMLReader), und das Ergebnis drei
 * Stunden gemerkt. Shared Hosting mit 128 MB Speicher reicht so.
 *
 * KOORDINATEN gibt es in den Meldungen nicht, nur Haltestellen-Kennungen
 * ("ch:1:sloid:7000"). Die Zahl ist der hintere Teil der Bahnhofsnummer:
 * 7000 -> 8507000 (Bern). Die Lage kommt dann von der ÖBB-Auskunft, einmal
 * je Bahnhof gemerkt.
 *
 * EIGENER TOKEN: Bei opentransportdata.swiss hat jedes API einen eigenen
 * Schlüssel. Der für "SIRI-SX" (komplett) steht in config.local.php unter
 * providers.swiss.sirisx_planned_token.
 */
final class SwissSiriSx
{
    private const ENDPOINT = 'https://api.opentransportdata.swiss/la/siri-sx';
    private const NS = 'http://www.siri.org.uk/siri';
    /** Bahnlinien im Sinne der Karte - Busse und Trams bleiben weg. */
    private const BAHN = '/^(IC|IR|ICE|EC|EN|ECE|RE|R|S|SN|RJX|TGV|PE|NJ|GPX)\s?\d*$/';

    private Http $http;
    private Cache $cache;
    private array $cfg;
    private ?OebbHafas $oebb;

    public function __construct(Http $http, Cache $cache, array $cfg, ?OebbHafas $oebb = null)
    {
        $this->http  = $http;
        $this->cache = $cache;
        $this->cfg   = $cfg;
        $this->oebb  = $oebb;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['sirisx_planned_token'] ?? '')) !== '';
    }

    /**
     * Bahn-Bauarbeiten der nächsten $days Tage, im Format von
     * OebbHafas::works().
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function works(int $days = 30): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SIRI-SX: kein Token', 'data' => []];
        }
        $roh = $this->cache->get('sirisx:works', 3 * 3600);
        if ($roh === null) {
            $roh = $this->fetchSituations();
            if ($roh === null) {
                return ['ok' => false, 'error' => 'SIRI-SX nicht erreichbar', 'data' => []];
            }
            $this->cache->set('sirisx:works', $roh);
        }

        $bis = date('Y-m-d', strtotime('+' . max(1, $days) . ' days'));
        $heute = date('Y-m-d');
        $out = [];
        foreach ($roh as $w) {
            if (($w['end'] ?? '9999') < $heute || ($w['start'] ?? '') > $bis) {
                continue;
            }
            $von = $this->station($w['fromRef'], $w['fromName']);
            $nach = $this->station($w['toRef'], $w['toName']);
            if ($von === null || $nach === null) {
                continue;
            }
            $out[] = [
                'id'           => $w['id'],
                'title'        => $w['title'],
                'text'         => $w['text'],
                'from'         => $von,
                'to'           => $nach,
                'start'        => $w['start'],
                'end'          => $w['end'],
                'country'      => 'ch',
                'geometry'     => null,
                'category'     => 1,
                'products'     => $w['lines'],
                'longDistance' => (bool) preg_grep('/^(IC|IR|ICE|EC|EN|ECE|RJX|TGV|NJ)/', $w['lines']),
            ];
        }
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Den Datensatz laden und die Bahn-Bauarbeiten herausziehen. Gleiche
     * Sperrung an mehreren Wochenenden steht mehrfach drin - einmal je
     * Termin; hier bleiben sie getrennt, die Liste zeigt Termine.
     *
     * @return ?array<int,array<string,mixed>>
     */
    private function fetchSituations(): ?array
    {
        $datei = tempnam(sys_get_temp_dir(), 'sirisx');
        if ($datei === false) {
            return null;
        }
        $res = $this->http->download(self::ENDPOINT, $datei, [
            'Authorization' => 'Bearer ' . trim((string) $this->cfg['sirisx_planned_token']),
            'User-Agent'    => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
        ]);
        if (!$res['ok']) {
            @unlink($datei);
            return null;
        }
        try {
            return self::parseFile($datei);
        } finally {
            @unlink($datei);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function parseFile(string $datei): array
    {
        $r = new XMLReader();
        if (!$r->open($datei)) {
            return [];
        }
        $out = [];
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'PtSituationElement') {
                continue;
            }
            $xml = $r->readOuterXml();
            $e = $xml !== '' ? @simplexml_load_string($xml) : false;
            if ($e === false) {
                continue;
            }
            $w = self::situation($e);
            if ($w !== null) {
                $out[] = $w;
            }
            $r->next();
        }
        $r->close();
        return $out;
    }

    /** Eine Meldung, wenn sie eine geplante Bahn-Bauarbeit ist - sonst null. */
    public static function situation(SimpleXMLElement $e): ?array
    {
        $e->registerXPathNamespace('s', self::NS);
        $ursache = (string) ($e->xpath('s:AlertCause')[0] ?? '');
        if (!in_array($ursache, ['constructionWork', 'maintenanceWork'], true)) {
            return null;
        }
        $linien = array_values(array_unique(array_map('strval', $e->xpath('.//s:PublishedLineName') ?: [])));
        $bahn = array_values(array_filter($linien, static fn($l) => preg_match(self::BAHN, $l) === 1));
        if ($bahn === []) {
            return null;
        }
        // Halte in Reihenfolge, ohne Doppelte: der erste und der letzte
        // stecken den Abschnitt ab.
        $halte = [];
        foreach ($e->xpath('.//s:AffectedStopPlace') ?: [] as $h) {
            $h->registerXPathNamespace('s', self::NS);
            $ref = (string) ($h->xpath('s:StopPlaceRef')[0] ?? '');
            $name = (string) ($h->xpath('s:PlaceName')[0] ?? '');
            if ($ref !== '' && !isset($halte[$ref])) {
                $halte[$ref] = $name;
            }
        }
        if (count($halte) < 2) {
            return null;
        }
        $refs = array_keys($halte);
        $deutsch = static function (string $pfad) use ($e): string {
            foreach ($e->xpath($pfad) ?: [] as $t) {
                if (strtoupper((string) ($t->attributes('xml', true)['lang'] ?? '')) === 'DE') {
                    return trim((string) $t);
                }
            }
            return '';
        };
        $titel = $deutsch('.//s:TextualContent[s:TextualContentSize="M"]//s:SummaryText')
            ?: $deutsch('.//s:SummaryText') ?: ('Bauarbeiten ' . implode(', ', $bahn));
        $text = $deutsch('.//s:TextualContent[s:TextualContentSize="L"]//s:ConsequenceText')
            ?: $deutsch('.//s:TextualContent[s:TextualContentSize="M"]//s:DescriptionText');
        // DER ABSCHNITT AUS DEM TITEL: "Unterbruch zwischen Laufen und Basel
        // SBB". Die Haltestellenliste reicht oft weiter (bis Biel), weil sie
        // alle betroffenen Halte der Linien nennt, nicht nur die Sperrung.
        $von = $refs[0];
        $nach = $refs[count($refs) - 1];
        if (preg_match('/zwischen\s+(.+?)\s+und\s+(.+?)(?:\s*\[|\s*$|,|\s+\()/u', $titel, $m)) {
            $finde = static function (string $name) use ($halte): ?string {
                foreach ($halte as $ref => $n) {
                    if (mb_strtolower(trim($n)) === mb_strtolower(trim($name))) {
                        return (string) $ref;
                    }
                }
                return null;
            };
            $a = $finde($m[1]);
            $b = $finde($m[2]);
            if ($a !== null && $b !== null && $a !== $b) {
                [$von, $nach] = [$a, $b];
            }
        }
        $start = substr((string) ($e->xpath('s:ValidityPeriod/s:StartTime')[0] ?? ''), 0, 10);
        $ende = substr((string) ($e->xpath('s:ValidityPeriod/s:EndTime')[0] ?? ''), 0, 10);
        return [
            'id'       => 'ch:' . (string) ($e->xpath('s:SituationNumber')[0] ?? md5($titel . $start)),
            'title'    => $titel,
            'text'     => $text,
            'lines'    => $bahn,
            'fromRef'  => $von,
            'fromName' => $halte[$von],
            'toRef'    => $nach,
            'toName'   => $halte[$nach],
            'start'    => $start,
            'end'      => $ende !== '' ? $ende : null,
        ];
    }

    /** "ch:1:sloid:7000" -> "8507000"; eine Bahnhofsnummer bleibt, wie sie ist. */
    public static function uic(string $ref): ?string
    {
        if (preg_match('/^\d{7}$/', $ref)) {
            return $ref;
        }
        return preg_match('/sloid:(\d{1,5})$/', $ref, $m) ? '85' . str_pad($m[1], 5, '0', STR_PAD_LEFT) : null;
    }

    /** Name und Lage eines Bahnhofs, einmal je Bahnhof von der ÖBB-Auskunft geholt. */
    private function station(string $ref, string $name): ?array
    {
        $uic = self::uic($ref);
        if ($uic === null || $this->oebb === null) {
            return null;
        }
        $key = 'sirisx:stop:' . $uic;
        $hit = $this->cache->get($key, 30 * 86400);
        if ($hit !== null) {
            return $hit ?: null;
        }
        $res = $this->oebb->locations($uic, 1);
        $l = $res['data'][0] ?? null;
        $ort = ($l && (string) $l['id'] === $uic && isset($l['lat'], $l['lon']))
            ? ['name' => $name !== '' ? $name : (string) $l['name'], 'id' => $uic, 'lat' => (float) $l['lat'], 'lon' => (float) $l['lon']]
            : [];
        if ($res['ok'] ?? false) {
            $this->cache->set($key, $ort);
        }
        return $ort ?: null;
    }
}
