<?php
/**
 * Schweizer Echtzeit über OJP 2.0 von opentransportdata.swiss - mit Schlüssel.
 *
 * WOZU, WO ES DOCH SwissOpenData GIBT: transport.opendata.ch reicht dieselben
 * Prognosen weiter, drosselt aber (HTTP 429), und seine Tafel nennt statt der
 * Zugnummer oft nur die Linie - der eigene Zug war nur über Gattung und
 * Minute zu finden. OJP ist die offizielle Auskunft der Schweizer Bahnen:
 * je Abfahrt Zugnummer, Plan- und Ist-Zeit, geplantes und geändertes Gleis,
 * Ausfall, und alle folgenden Halte mit ihren Prognosen.
 *
 * GENUTZT WIRD NUR `OJPStopEventRequest` (Abfahrten bzw. Ankünfte eines
 * Bahnhofs), zweimal:
 *   - trip(): Live-Verfolgung eines Schweizer Abschnitts. Die Abfahrt am
 *     Einstieg samt der folgenden Halte liefert in einer Anfrage Ist-Zeiten
 *     für den ganzen Abschnitt bis zum Ausstieg.
 *   - board(): Ergänzung der Abfahrtstafel an Schweizer Bahnhöfen, für die
 *     HAFAS nur den Fahrplan hat (Bern: 0 von 14 mit Ist-Zeit).
 *
 * HALTE: OJP benennt Steige als "ch:1:sloid:3000:503:43" - die Zahl nach
 * "sloid:" ist die Dienststellennummer, also die UIC-Nummer ohne die 85
 * davor (Zürich HB 8503000 -> 3000). Darüber findet sich der Ausstieg in den
 * folgenden Halten.
 *
 * Schlüssel: config.local.php, 'swiss' => ['token' => …].
 */
final class SwissOjp
{
    private const ENDPOINT = 'https://api.opentransportdata.swiss/ojp20';

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['token'] ?? '')) !== '';
    }

    /**
     * Abfahrten (bzw. Ankünfte) eines Bahnhofs ab einem Zeitpunkt.
     *
     * @return array{ok:bool,error:?string,data:array<int,array<string,mixed>>}
     */
    public function stopEvents(string $uic, int $ab, bool $arrivals = false, int $anzahl = 40, bool $folgende = false): array
    {
        $zeit = gmdate('Y-m-d\TH:i:s\Z', $ab);
        $jetzt = gmdate('Y-m-d\TH:i:s\Z');
        $typ = $arrivals ? 'arrival' : 'departure';
        $folgendeXml = $folgende ? 'true' : 'false';
        $uicXml = htmlspecialchars($uic, ENT_XML1);
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OJP xmlns="http://www.vdv.de/ojp" xmlns:siri="http://www.siri.org.uk/siri" version="2.0">
  <OJPRequest>
    <siri:ServiceRequest>
      <siri:RequestTimestamp>{$jetzt}</siri:RequestTimestamp>
      <siri:RequestorRef>OmniRail</siri:RequestorRef>
      <OJPStopEventRequest>
        <siri:RequestTimestamp>{$jetzt}</siri:RequestTimestamp>
        <Location>
          <PlaceRef><StopPlaceRef>{$uicXml}</StopPlaceRef></PlaceRef>
          <DepArrTime>{$zeit}</DepArrTime>
        </Location>
        <Params>
          <NumberOfResults>{$anzahl}</NumberOfResults>
          <StopEventType>{$typ}</StopEventType>
          <IncludePreviousCalls>false</IncludePreviousCalls>
          <IncludeOnwardCalls>{$folgendeXml}</IncludeOnwardCalls>
          <IncludeRealtimeData>true</IncludeRealtimeData>
        </Params>
      </OJPStopEventRequest>
    </siri:ServiceRequest>
  </OJPRequest>
</OJP>
XML;
        $res = $this->http->request('POST', rtrim((string) ($this->cfg['ojp_endpoint'] ?? self::ENDPOINT), '/'), [
            'Authorization' => 'Bearer ' . $this->cfg['token'],
            'Content-Type'  => 'application/xml',
            'Accept'        => 'application/xml',
            'User-Agent'    => 'train-maxxing (Fahrplanwerkzeug)',
        ], $xml);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'OJP: HTTP ' . $res['status'], 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => self::parse($res['body'], $arrivals)];
    }

    /**
     * Die StopEvents einer OJP-Antwort, flach.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parse(string $xml, bool $arrivals = false): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            return [];
        }
        $doc->registerXPathNamespace('o', 'http://www.vdv.de/ojp');
        $doc->registerXPathNamespace('s', 'http://www.siri.org.uk/siri');

        // Meldungen (Baustellen, Störungen) stehen einmal im Kopf und werden
        // je Fahrt nur referenziert.
        $meldungen = [];
        foreach ($doc->xpath('//o:Situations/o:PtSituation') ?: [] as $sit) {
            $sit->registerXPathNamespace('o', 'http://www.vdv.de/ojp');
            $sit->registerXPathNamespace('s', 'http://www.siri.org.uk/siri');
            $nr = (string) ($sit->xpath('s:SituationNumber')[0] ?? '');
            // Die Kurzfassung, deutsch wo vorhanden - OJP liefert sie in
            // allen vier Landessprachen.
            $text = (string) ($sit->xpath('.//s:SummaryText[@xml:lang="DE" or @xml:lang="de"]')[0]
                ?? $sit->xpath('.//s:SummaryText')[0] ?? '');
            if ($nr !== '' && trim($text) !== '') {
                $meldungen[$nr] = Text::plain($text);
            }
        }

        $out = [];
        foreach ($doc->xpath('//o:StopEventResult/o:StopEvent') ?: [] as $ev) {
            $ev->registerXPathNamespace('o', 'http://www.vdv.de/ojp');
            $ev->registerXPathNamespace('s', 'http://www.siri.org.uk/siri');
            $txt = static function ($knoten, string $pfad): string {
                $knoten->registerXPathNamespace('o', 'http://www.vdv.de/ojp');
                $knoten->registerXPathNamespace('s', 'http://www.siri.org.uk/siri');
                $n = $knoten->xpath($pfad);
                return $n ? trim((string) $n[0]) : '';
            };

            $diese = $ev->xpath('o:ThisCall/o:CallAtStop')[0] ?? null;
            if ($diese === null) {
                continue;
            }
            $halt = self::call($diese, $txt);
            $zeit = $arrivals ? ($halt['arrival'] ?? null) : ($halt['departure'] ?? null);
            if ($zeit === null) {
                continue;
            }
            $folgende = [];
            foreach ($ev->xpath('o:OnwardCall/o:CallAtStop') ?: [] as $c) {
                $folgende[] = self::call($c, $txt);
            }
            $gruende = [];
            foreach ($ev->xpath('o:Service/o:SituationFullRefs/o:SituationFullRef/s:SituationNumber') ?: [] as $ref) {
                if (isset($meldungen[(string) $ref])) {
                    $gruende[] = $meldungen[(string) $ref];
                }
            }
            $out[] = [
                'trainNumber' => $txt($ev, 'o:Service/o:TrainNumber'),
                'category'    => $txt($ev, 'o:Service/o:ProductCategory/o:ShortName/o:Text')
                    ?: $txt($ev, 'o:Service/o:Mode/o:ShortName/o:Text'),
                'line'        => $txt($ev, 'o:Service/o:PublishedServiceName/o:Text')
                    ?: $txt($ev, 'o:Service/o:PublicCode'),
                'direction'   => $txt($ev, 'o:Service/o:DestinationText/o:Text'),
                'journeyRef'  => $txt($ev, 'o:Service/o:JourneyRef'),
                'planned'     => $zeit['planned'],
                'real'        => $zeit['estimated'],
                'platform'    => $halt['platform'],
                'platformPlanned' => $halt['platformPlanned'],
                'cancelled'   => $txt($ev, 'o:Service/o:Cancelled') === 'true' || $halt['cancelled'],
                'remarks'     => array_values(array_unique($gruende)),
                'stop'        => $halt,
                'onward'      => $folgende,
            ];
        }
        return $out;
    }

    /** Ein Halt (CallAtStop) mit Zeiten und Gleis. */
    private static function call(SimpleXMLElement $c, callable $txt): array
    {
        $zeit = static function (string $art) use ($c, $txt): ?array {
            $plan = $txt($c, "o:Service{$art}/o:TimetabledTime");
            if ($plan === '') {
                return null;
            }
            $ist = $txt($c, "o:Service{$art}/o:EstimatedTime");
            return ['planned' => self::iso($plan), 'estimated' => $ist !== '' ? self::iso($ist) : null];
        };
        $geplant = $txt($c, 'o:PlannedQuay/o:Text');
        $neu = $txt($c, 'o:EstimatedQuay/o:Text');
        $ref = $txt($c, 's:StopPointRef');
        return [
            'uic'             => preg_match('/sloid:(\d+)/', $ref, $m) === 1 ? (string) (8500000 + (int) $m[1]) : null,
            'name'            => $txt($c, 'o:StopPointName/o:Text'),
            'arrival'         => $zeit('Arrival'),
            'departure'       => $zeit('Departure'),
            'platform'        => $neu !== '' ? $neu : ($geplant !== '' ? $geplant : null),
            'platformPlanned' => $geplant !== '' ? $geplant : null,
            'cancelled'       => $txt($c, 'o:NotServicedStop') === 'true',
        ];
    }

    private static function iso(string $t): ?string
    {
        $ts = strtotime($t);
        return $ts === false ? null : (new DateTimeImmutable('@' . $ts))
            ->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d\TH:i:sP');
    }

    /**
     * Den eigenen Zug unter den Abfahrten finden: über die Zugnummer, wo
     * bekannt, sonst über Gattung und Planminute.
     */
    public static function find(array $events, int $plan, string $num, string $cat): ?array
    {
        $num = ltrim(trim($num), '0');
        $cat = strtoupper(trim($cat));
        $kandidat = null;
        foreach ($events as $e) {
            $t = strtotime((string) $e['planned']);
            if ($t === false || abs($t - $plan) > 90) {
                continue;
            }
            if ($num !== '' && ltrim((string) $e['trainNumber'], '0') === $num) {
                return $e;
            }
            if ($num === '' && ($cat === '' || strtoupper((string) $e['category']) === $cat)) {
                $kandidat ??= $e;
            }
        }
        return $kandidat;
    }

    /**
     * Echtzeit eines Schweizer Abschnitts, im Format von SwissOpenData::trip()
     * - dazu `stops`: die Halte vom Einstieg bis zum Ausstieg, je mit Ist-Zeit.
     *
     * @param array{from:string,to:string,cat:string,num?:string,dep:string,arr:string} $leg
     * @return array{ok:bool,error:?string,data:array}
     */
    public function trip(array $leg): array
    {
        $tDep = strtotime($leg['dep']);
        if ($tDep === false) {
            return ['ok' => false, 'error' => 'Zeitangabe ungültig', 'data' => []];
        }
        $res = $this->stopEvents($leg['from'], $tDep - 120, false, 20, true);
        if (!$res['ok']) {
            return $res;
        }
        $e = self::find($res['data'], $tDep, (string) ($leg['num'] ?? ''), $leg['cat']);
        if ($e === null) {
            return ['ok' => true, 'error' => null, 'data' => ['hasRealtime' => false, 'source' => 'ojp']];
        }

        // Halte bis zum Ausstieg; danach ist die Fahrt für diese Reise zu Ende.
        $halte = [[
            'id' => $leg['from'], 'name' => $e['stop']['name'],
            'departure' => $e['planned'], 'departureReal' => $e['real'],
            'platform' => $e['platform'], 'cancelled' => $e['cancelled'],
        ]];
        $aus = null;
        foreach ($e['onward'] as $c) {
            $halte[] = [
                'id' => $c['uic'], 'name' => $c['name'],
                'arrival' => $c['arrival']['planned'] ?? null, 'arrivalReal' => $c['arrival']['estimated'] ?? null,
                'departure' => $c['departure']['planned'] ?? null, 'departureReal' => $c['departure']['estimated'] ?? null,
                'platform' => $c['platform'], 'cancelled' => $c['cancelled'],
            ];
            if ($c['uic'] === $leg['to']) {
                $aus = $c;
                break;
            }
        }

        $depReal = $e['real'];
        $arrReal = $aus['arrival']['estimated'] ?? null;
        $delay = null;
        if ($depReal !== null) {
            $delay = (int) round((strtotime($depReal) - $tDep) / 60);
        } elseif ($arrReal !== null && isset($aus['arrival']['planned'])) {
            $delay = (int) round((strtotime($arrReal) - strtotime($aus['arrival']['planned'])) / 60);
        }

        return ['ok' => true, 'error' => null, 'data' => [
            'hasRealtime'   => $depReal !== null || $arrReal !== null,
            'delay'         => $delay,
            'departureReal' => $depReal,
            'arrivalReal'   => $arrReal,
            'platformFrom'  => $e['platform'],
            'platformTo'    => $aus['platform'] ?? null,
            'cancelled'     => $e['cancelled'] || ($aus['cancelled'] ?? false),
            'stops'         => $aus !== null ? $halte : [],
            // Keine Meldungen: OJP hängt eine Störung auf einer S-Bahn-Linie
            // an jede Abfahrt des Bahnhofs (gemessen in Zürich HB: dieselbe
            // Regensdorf-Meldung am IR nach St. Gallen). Das ist das Rauschen,
            // das die App sonst mühsam herausfiltert.
            'messages'      => [],
            'source'        => 'ojp',
        ]];
    }

    /**
     * Eine HAFAS-Tafel um die Ist-Daten der Schweizer Bahnen ergänzen - wie
     * DbApi::enrichBoard(), nur mit OJP.
     *
     * @return array{entries:array,matched:int}
     */
    public static function enrichBoard(array $entries, array $events): array
    {
        $norm = static fn(?string $s): string => mb_strtolower(str_replace(' ', '', (string) $s));
        $nachNummer = [];
        $nachLinie = [];
        foreach ($events as $e) {
            $minute = substr((string) $e['planned'], 0, 16);
            if ($e['trainNumber'] !== '') {
                $nachNummer[ltrim($e['trainNumber'], '0') . '|' . $minute] = $e;
            }
            if ($e['line'] !== '') {
                $nachLinie[$norm($e['line']) . '|' . $minute] = $e;
            }
        }
        $treffer = 0;
        foreach ($entries as $i => $x) {
            // Planzeiten in Schweizer Ortszeit vergleichen - HAFAS liefert
            // dieselbe Zone, aber sicher ist sicher.
            $t = strtotime((string) ($x['planned'] ?? ''));
            if ($t === false) {
                continue;
            }
            $minute = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d\TH:i');
            $e = $nachNummer[ltrim((string) ($x['trainNumber'] ?? ''), '0') . '|' . $minute]
                ?? $nachLinie[$norm($x['line'] ?? '') . '|' . $minute]
                ?? null;
            if ($e === null) {
                continue;
            }
            $treffer++;
            if (($x['real'] ?? null) === null && $e['real'] !== null) {
                $entries[$i]['real'] = $e['real'];
                $entries[$i]['delay'] = (int) round((strtotime($e['real']) - $t) / 60);
            }
            if ($e['platform'] !== null && $e['platform'] !== $e['platformPlanned']) {
                $entries[$i]['platform'] = $e['platform'];
                $entries[$i]['platformChanged'] = true;
            } elseif (($x['platform'] ?? null) === null && $e['platform'] !== null) {
                $entries[$i]['platform'] = $e['platform'];
            }
            if ($e['cancelled']) {
                $entries[$i]['cancelled'] = true;
            }
            $entries[$i]['source'] = trim(($x['source'] ?? 'hafas') . '+ojp', '+');
        }
        return ['entries' => $entries, 'matched' => $treffer];
    }
}
