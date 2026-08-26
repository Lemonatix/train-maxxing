# train-maxxing (name to be changed)

Vergleicht Zugverbindungen durch **Schweiz, Deutschland und Österreich** — nicht nur
nach Preis und Dauer, sondern auch danach, in welchem Zug du sitzt. Mit Abo-Auswahl
(Halbtax, GA, BahnCard, Vorteilscard, KlimaTicket) und zwei Modi:

- **Normal** — Preis und Zeit, eine sortierte Liste, fertig.
- **Nerd** — Zuggattung, Zugnummer, Streckenverlauf, Fahrzeugmodell,
  Routenzwang über eine bestimmte Stadt und eine Sortierung nach
  **Routenvarianten**: der Weg über den Gotthard und der über den Arlberg
  sind keine Abstufung derselben Sache, sondern eine Entscheidung.

Dazu eine Routenkarte, ein Verkehrsmittel-Filter (Bus und
Schienenersatzverkehr lassen sich vorab ausschließen), eine **Live-Verfolgung**
der gewählten Verbindung samt GPS-Mitfahrt und Buchungslinks zu SBB, DB oder
ÖBB, je nachdem welche Länder die Reise berührt.

Gebaut als statisches Frontend plus schlankes PHP-Backend, damit du den Ordner
einfach auf deinen Webspace ziehen kannst. Die Oberfläche ist für Telefone
ausgelegt: keine horizontalen Scrollleisten, Touch-Flächen ab 44 px, und die
Karte wechselt auf schmalen Bildschirmen ins Hochformat.

---

## Was du wissen musst, bevor du loslegst

| Quelle | Fahrplan | Zuggattung & -nummer | Geometrie | Preise |
|---|---|---|---|---|
| ÖBB HAFAS | ja | ja, sehr detailliert | ja (Karte) | nein, nur Shop-Link |
| DB bahn.de | ja | ja | nein | **ja** |
| MVG (München) | nein* | Linien-Label | nur Halt-Koordinaten | nein |

*MVG liefert Ortssuche und Störungsmeldungen für den Münchner Nahverkehr, aber
keine Verbindungssuche. Details unter [Münchner Nahverkehr](#münchner-nahverkehr-über-die-mvg-api).

### Preise: warum nur die DB

Nachgemessen, ob sich SBB oder ÖBB als zweite Preisquelle anzapfen lassen:

| Endpunkt | Ergebnis |
|---|---|
| `journey-service-int.api.sbb.ch` | **401** — SBB-Partner-API, braucht Registrierung |
| `www.sbb.ch/api/journeys` | **403** — Bot-Schutz, wie bei bahn.de |
| `shop.oebbtickets.at/api/domria/…` | **403 / 404** — Shop-API abgeriegelt |
| `transport.opendata.ch` | **200**, aber **kein Preisfeld** (nachgeprüft) |

Kurz: **ohne Zugangsdaten gibt es keine Schweizer oder österreichischen
Preise.** Die DB bleibt die einzige Quelle, und ÖBB/SBB steuern nur den
Deeplink in ihren Shop bei.

Was stattdessen geht: der **Gegenwert in der anderen Währung**. Die
Tageskurse kommen von der Europäischen Zentralbank
(`?action=fxrate`, sechs Stunden gecacht) — kein Schlüssel, keine
Registrierung, offizieller Referenzkurs mit Datum. An jeder Verbindung steht
damit z.B. `40,99 €` und darunter `≈ 38,34 CHF`. Bewusst mit „≈" und als
Nebenzeile: der Referenzkurs ist **kein Bankkurs**, beim Kartenzahlen kommen
Aufschläge dazu.

### Warum ÖBB HAFAS den Fahrplan liefert und die DB nur die Preise

Nachgemessen für fünf Relationen, jeweils dieselbe Abfrage an beide Quellen:

| | ÖBB HAFAS | DB bahn.de |
|---|---|---|
| Treffer CH/AT-Relationen | 6–10 | 5–6 |
| Streckengeometrie (Karte) | **alle Abschnitte** | keine |
| Zuglauf-ID für Echtzeit | **alle Abschnitte** | `journeyId` je Abschnitt |
| Preise | keine | **4–6 je Suche** |
| Auslastung | keine | **ja**, sogar je Halt |
| Zwischenhalte mit Koordinaten | 100 % | 100 % |
| Gattung, Zugnummer, Gleis, Ländercode | ja | ja |
| Antwortzeit | 0,8–7,0 s | 0,7–4,4 s |

Die Aufteilung ist also kein Zufall, sondern folgt den Lücken: **ohne HAFAS gäbe
es keine Karte** (die DB liefert keine Polylines) und **ohne die DB keine Preise**.
Auf österreichischen und Schweizer Relationen findet HAFAS zudem mehr — Wien→München
10 statt 5 Treffer, Zürich→Wien 8 statt 6.

Was die DB besser kann und was das Tool bereits nutzt: **Auslastungsangaben**
werden beim Preis-Merge auf die HAFAS-Abschnitte übertragen, ebenso die Angabe,
wo das Deutschlandticket gilt. Beides gibt es bei HAFAS nicht.

**Echtzeit kommt von der DB, nicht von HAFAS.** Die DB schickt neben
`sollzeit` ein Feld `echtzeit` direkt in der Suchantwort mit, dazu unter
`risNotizen` den Grund („Verspätung eines vorausfahrenden Zuges",
„Polizeieinsatz"). HAFAS bräuchte dafür je Abschnitt eine eigene Abfrage.
Deshalb übernimmt `mergeLegFlags()` die Ist-Zeiten auf die ÖBB-Abschnitte —
so stehen Verspätungen schon in der Trefferliste, ohne Zusatzabfrage.

Drei Fallstricke, die dabei aufgefallen sind:

- **Die DB liefert Zeiten ohne Zeitzone** (`2026-08-22T00:47:00`), die ÖBB mit
  Offset (`…+02:00`). Ohne Normalisierung interpretiert PHP den DB-Wert in der
  Serverzone. Auf einem deutschen Webspace fällt das nie auf, auf einem
  UTC-Server liegen beide Quellen zwei Stunden auseinander und der Abgleich
  über Ab-/Ankunftszeit findet gar nichts mehr — oder das Falsche.
  `DbVendo::iso()` hängt deshalb `Europe/Berlin` an.
- **Der Merge darf nicht am Preis hängen.** Die DB liefert Echtzeit auch für
  Relationen, die sie nicht verkauft — nachts und im Ausland der Normalfall.
  Früher übersprang `mergePrices()` preislose Treffer und verlor damit genau
  dort die Verspätung, wo sie am meisten hilft.
- **Bei S-Bahnen nennt die DB die Linie („S8"), die ÖBB die Zugnummer
  („35884").** Über die Nummer findet sich da nichts, deshalb fällt
  `mergeLegFlags()` auf die Position zurück, wenn beide Quellen gleich viele
  Zugabschnitte haben.

Und: die DB-Abschnitte tragen eine eigene `journeyId`. Damit wäre die
Live-Verfolgung auch für DB-Fahrpläne möglich — es fehlt nur ein
Zuglauf-Endpunkt auf DB-Seite.

### Die DB blockt nach TLS-Fingerprint, nicht nach IP

bahn.de läuft hinter Akamai Bot Manager. Der prüft nicht Header oder IP, sondern
den **TLS-ClientHello**. Mit den Standardeinstellungen von cURL kommt immer
`HTTP 403 OPS_BLOCKED` zurück — auch von einem gewöhnlichen Privatanschluss.

Setzt man die Cipher-Reihenfolge eines Chrome-Browsers, geht dieselbe Anfrage
durch. Nachgemessen: ohne Cipher-Liste 403, mit Cipher-Liste 200 in 5 von 5
Versuchen — und zwar sogar ohne User-Agent. Genau das macht `Http::withBrowserTls()`.

Voraussetzung ist cURL 7.61+ mit OpenSSL 1.1.1+, damit `CURLOPT_TLS13_CIPHERS`
existiert. `check.php` prüft das.

### Die DB kennt nur BahnCards

Die Angebots-API akzeptiert ausschließlich `BAHNCARD25/50/100`. Halbtax, GA,
VORTEILScard, KlimaTicket und Deutschlandticket werden **stillschweigend
ignoriert** — die API liefert denselben Preis wie ohne Ermäßigung und meldet
keinen Fehler. Nachgemessen für Zürich→München, 2. Klasse:

| Ermäßigung | günstigster Preis |
|---|---|
| ohne | 34,19 € |
| BahnCard 25 | 29,57 € |
| BahnCard 50 | 29,57 € |
| BahnCard 100 | 21,60 € |
| Halbtax | 34,19 € (wirkungslos) |
| GA | 34,19 € (wirkungslos) |
| frei erfundener Wert | 34,19 € (wirkungslos) |

Deshalb der Hybrid: Der Echtpreis der DB ist die Basis, und die Abos, die sie
nicht kennt, rechnet das Tool auf den Länderanteil hoch. Das wird als
„Echtpreis + Abo geschätzt" gekennzeichnet und im Detail vorgerechnet.

### Preise gibt es nur für Relationen, die die DB verkauft

Zürich→München liefert Preise. Zürich→Wien liefert Verbindungen **ohne** Preis,
weil die DB diese Relation nicht vertreibt. Dann fällt das Tool auf die
Schätzung mit Spanne zurück. Fahrplan, Züge, Umstiege, Streckenverlauf und Karte
sind davon nicht betroffen.

---

## Installation

1. **Hochladen.** Den kompletten Inhalt von `public/` in dein Webverzeichnis
   ziehen, z.B. nach `/train/`. Die Struktur muss erhalten bleiben:

   ```
   train/
   ├── index.html
   ├── check.php
   ├── assets/
   └── api/
       ├── index.php
       ├── config.php
       ├── cache/          ← muss beschreibbar sein
       └── lib/
   ```

2. **Selbsttest aufrufen:** `https://deine-domain.tld/train/check.php`
   Die Seite prüft PHP-Version, cURL, Schreibrechte und beide Datenquellen.

3. **Falls das Cache-Verzeichnis nicht beschreibbar ist:** entweder per FTP auf
   `0775` setzen, oder in `api/config.php` einen anderen Pfad eintragen:

   ```php
   'cache_dir' => sys_get_temp_dir() . '/train-maxxing',
   ```

4. **`check.php` löschen**, wenn alles läuft — sie verrät sonst unnötig
   Serverdetails.

5. Fertig: `https://deine-domain.tld/train/`

### Nach einem Update: Browser-Cache beachten

Die JavaScript-Dateien haben keine Versionsnummer im Namen. Lädst du eine neue
Fassung hoch, halten Browser die alte oft noch fest — dann fehlen neue
Funktionen scheinbar. Beim Testen ist mir genau das passiert.

Zwei Wege: einmal hart neu laden (`Strg`+`Shift`+`R`), oder in `index.html` an
die Skript- und Stylesheet-Pfade eine Version hängen und sie bei jedem Update
hochzählen:

```html
<link rel="stylesheet" href="assets/css/style.css?v=2">
<script type="module" src="assets/js/app.js?v=2"></script>
```

### Voraussetzungen

- PHP 8.0 oder neuer
- cURL-Erweiterung aktiv
- Ausgehende HTTPS-Verbindungen erlaubt (bei manchen Billig-Hostern gesperrt)

### nginx statt Apache?

Die mitgelieferten `.htaccess`-Dateien schützen `api/cache/` und `api/lib/` vor
direktem Zugriff. Unter nginx wirken sie **nicht** — trag dort stattdessen ein:

```nginx
location ~ ^/train/api/(cache|lib)/ { deny all; }
```

---

## In die eigene Website einbauen

Das Frontend hat keine JavaScript-Abhängigkeiten. Alle API-Pfade sind relativ
(`api/…`), es funktioniert also in jedem Unterordner ohne Konfiguration.

**Gestaltung:** Glasmorphismus-Grundgerüst wie auf mika-riesterer.de
(Panels mit `backdrop-filter`, dünne Ränder, Sky/Purple/Emerald als
Akzente, Outfit + JetBrains Mono), kombiniert mit der Informationsarchitektur des
DB-Navigators — große Zeitangaben in Tabellenziffern, farbcodierte
Zuggattungs-Chips, Zeitachse im Detailbereich, Verkehrsrot als Signalfarbe für
den Fernverkehr.

Die Schriften werden in `index.html` von Google Fonts geladen. Ist das Tool in
deine Website eingebettet, sind sie ohnehin da — dann kannst du die beiden
`<link>`-Zeilen ersatzlos streichen.

**Farben anpassen:** alles läuft über CSS-Variablen. Nach dem Einbinden von
`style.css` überschreiben:

```css
:root {
  --accent: #c084fc;
  --radius: 4px;
}
```

Die Seite kennt **hell und dunkel**. Ohne gespeicherte Wahl folgt sie dem
Betriebssystem, der Umschalter oben rechts überschreibt das und legt die
Entscheidung unter `train-maxxing:theme` ab. Die hellen Werte stehen in
`:root`, die dunklen in `[data-theme="dark"]` — alle Komponenten leiten ihre
Farben davon ab.

### Umstiegsplan: Bahnhofsskizze mit Laufweg

Bei vier Minuten Umsteigezeit ist die Gleisnummer allein wertlos: entscheidend
ist, ob man zwanzig Meter weiter oder ans andere Hallenende muss. Die
Fahrplanquellen wissen das nicht, OpenStreetMap teilweise schon.

Grosse Bahnhöfe sind dort oft **innen kartiert** — München Hbf mit über 600
Fusswegen und Treppen samt Ebenenangabe. `?action=platforms&from=8&to=14` baut
daraus ein Wegenetz, sucht mit Dijkstra den Weg zwischen den beiden Gleisen und
liefert Länge, geschätzte Gehzeit und ob Treppen dabei sind.

**Gezeigt wird das als Karte, nicht als Skizze.** Vorher war es ein eigenes SVG
mit ein paar Balken darauf: massstäblich zwar, aber ohne Bezug zu irgendetwas,
nicht zoombar — und ein Umstieg über mehrere Ebenen lag darin als ein Strich
übereinander, in dem sich Bahnsteig, Unterführung und Halle nicht unterscheiden
liessen. Vorbild für den Ersatz ist die SBB-App: eine Karte, auf den Umstieg
gezoomt, mit einem Umschalter für die Ebene.

Es ist dieselbe Kartenkomponente wie überall sonst, nur im **Bahnhofsmodus**
(`new RouteMap(el, { mode: 'station' })`) — Kacheln als Untergrund, ziehen und
zoomen inklusive. Das spart eine zweite Kartenmaschinerie; die SBB nimmt dafür
das MapLibre-SDK, hier reichen die vorhandenen `<img>`-Kacheln mit SVG darüber.

**Der Ebenenumschalter** braucht eine Angabe, die es vorher nicht gab: zu
welcher Ebene jeder Punkt des Weges gehört. `StationPlan` gibt sie jetzt als
`levels` neben `path` zurück. Sie zu bestimmen ist Stückwerk — ein Wegknoten
kann zu mehreren Ebenen gehören (eine Treppe trägt in OSM beide, die sie
verbindet), und viele Wege tragen gar keine Angabe. Deshalb wird von vorne
durchgegangen und die zuletzt sichere Ebene mitgeführt: passt sie noch zur
Auswahl des nächsten Punktes, bleibt sie; sonst wechselt sie. Anfang und Ende
bekommen die Ebene ihres Bahnsteigs — die kennt OSM meist, auch wo sie an den
Wegen fehlt.

Was auf einer anderen Ebene liegt, verschwindet nicht, sondern wird blass
gezeichnet: sonst verliert man beim Umschalten die Orientierung, weil das halbe
Bild wegfällt. Gibt es nur eine Ebene, bleibt der Umschalter ganz weg, statt
untätig herumzustehen.

Für München Hbf, Gleis 8 → 14: *„Rund 645 m Fussweg, etwa 9 min."* Gemessen
wird von Bahnsteigmitte zu Bahnsteigmitte — siehe unten. Zum Vergleich: die DB
setzt für München Hbf 10 Minuten Mindestumsteigezeit an, die SBB für Zürich HB
Gleis 7 → 31 rund vier; die Rechnung kommt dort auf 3,7.

Sechs Dinge, die dabei zu beachten waren:

- **Treppen werden vierfach gewichtet**, Aufzüge fünffach. Für die Frage
  „schaffe ich den Umstieg" zählt Zeit, nicht Entfernung.
- **Zugänge, die zu beiden Bahnsteigen gehören, dürfen kein Ziel sein.** Sonst
  endet die Suche sofort beim Startknoten und meldet null Meter — bei
  benachbarten Bahnsteigen in einer Halle passiert genau das.
- **Der Weg beginnt in der Bahnsteigmitte, nicht am nächsten Zugang.** Ein
  Zugang liegt irgendwo auf einem 400 m langen Bahnsteig, und bis dorthin ist
  man schon gelaufen. Ohne diesen Anteil meldete Ulm Hbf für Gleis 8 → 2
  „rund 40 m" — gemessen zwischen den Nordenden zweier Bahnsteige, während der
  Weg in Wirklichkeit durch die Unterführung führt. Mit Anfahrt und Abgang:
  214 m.
- **Der Bahnsteig selbst ist begehbar.** In OSM ist er eine *Fläche*, kein Weg;
  Treppen enden darauf und hängen deshalb im Wegenetz in der Luft. Zürich HB
  zerfiel so in 179 unverbundene Inseln, und von 276 Gleispaaren waren 60
  berechenbar. Die Zugänge eines Bahnsteigs werden deshalb der Länge nach
  aufgereiht und verbunden — eine Kette, keine Verbindung jeder mit jedem,
  sonst kürzte der Weg quer über die Gleise ab. Danach: 276 von 276.
- **Ebenen zählen, bevor Entfernungen zählen.** In der Draufsicht liegt ein
  Tiefbahnsteig genau unter dem oberirdischen. Rein nach Entfernung gemessen
  sind die Treppen der Ebene −4 also „direkt an Gleis 7", und die Suche startete
  dort, wo sie ankommen sollte: Zürich HB meldete für Gleis 7 → 31 drei Minuten
  *ebenen* Weges über vier Ebenen hinweg.
- **Ein einzelner gemeinsamer Zugang heisst nichts.** Zugänge, die zu beiden
  Bahnsteigen gehören, flogen früher pauschal aus der Zielmenge — sonst hätte
  die Suche null Meter gemeldet. Seit die Anfahrt zur Bahnsteigmitte
  mitzählt, ist das nicht mehr nötig und war sogar schädlich: an einem
  Kopfbahnhof münden alle Bahnsteige in dieselbe Querhalle. „Nebenan" gilt
  deshalb nur noch, wenn die Bahnsteige *sämtliche* Zugänge teilen.
- **Bahnsteigabschnitte auf die nackte Nummer abbilden.** Ulm Hbf führt in OSM
  „4 Nord", „4 Süd", „5a", „5b" — und kein einziges nacktes „4". Der Fahrplan
  sagt aber „Gleis 4". Die blosse Nummer kommt als Zweitname dazu, nachrangig:
  wo es ein echtes „4" gibt, gewinnt das.
- **Einzelne fehlende Nummern ergänzen.** Mannheim Hbf hat in OSM die Gleise
  1–5 und 7–12, aber **kein 6** — jemand hat es beim Erfassen ausgelassen.
  Fuhr der Anschlusszug von Gleis 6, entfiel deshalb der ganze Umstiegsplan,
  obwohl der Bahnhof ringsum vollständig kartiert ist. Ergänzt wird nur, wo
  genau *eine* Nummer zwischen zwei vorhandenen fehlt und die beiden Nachbarn
  dicht beieinanderliegen — Gleis 5 und 7 sind in Mannheim neun Meter
  auseinander, die Kante dazwischen ist dann kein Raten mehr. Zwischen Gleis
  18 und 31 in Zürich klaffen dreizehn Nummern und mehrere hundert Meter;
  dort wird nichts ergänzt. Was ergänzt wurde, sagt die Anzeige dazu.

**Die Abdeckung ist sehr unterschiedlich** — und war lange schlechter, als sie
sein musste. Zwei Fehler steckten dahinter:

1. Die Overpass-Abfrage entstand per `sprintf` in einem **doppelt gequoteten**
   PHP-String. Dort liest PHP `%1$d` als `%1` gefolgt von der Variablen `$d`;
   die war nie gesetzt, `sprintf` bekam `%1,` zu sehen und warf *Unknown format
   specifier*. Die Abfrage kam gar nicht erst zustande — **jeder** Bahnhof ohne
   Cache-Eintrag meldete „keine Bahnsteige erfasst".
2. An Haltepunkten trägt `ref` **mancherorts** die Nummer des Bahnhofs, nicht
   die des Gleises. Zürich HB lieferte darüber „Gleis 13030" — die Gleisnummer
   steht dort in `local_ref`. Nur `local_ref` zu nehmen war aber auch falsch:
   Mannheim Hbf führt seine zwölf Gleise als `ref` und kennt kein `local_ref`,
   und der Lageplan zeigte dort **einen** Bahnsteig. Jetzt gilt `local_ref`
   vor `ref`, und was wie eine Stationsnummer aussieht, fällt vorher raus: was
   auf **drei oder mehr** Haltepunkten gleich lautet, kann keine Gleisnummer
   sein — ein Gleis hat höchstens zwei, einen je Richtung.

Nach der Korrektur, nachgemessen:

| Bahnhof | Bahnsteige mit Nummer | berechenbare Gleispaare |
|---|---|---|
| Zürich HB | 24 (Gleis 3–18, 31–34, 41–44) | 276 / 276 |
| Mannheim Hbf | 12 (vorher 1) | — |
| Frankfurt Hbf | 28 (vorher 0) | — |
| Stuttgart Hbf | 17 (vorher 1) | nur benachbarte — die Fusswege fehlen dort in OSM |
| München Hbf | 17 | 136 / 136 |
| Bern | 16 | 120 / 120 |
| Ulm Hbf | 18 (Abschnitte) | — |
| Winterthur | 10 | 45 / 45 |
| Olten | 14 | 78 / 91 (der Rest sind Bussteige) |

Der Plan erscheint nur, wenn **beide** Gleise gefunden werden; sonst sagt die
Anzeige, was fehlt — und unterscheidet dabei „Dienst gerade überlastet" von
„Bahnhof nicht kartiert". Vorher stand in beiden Fällen dieselbe Zeile, und in
einem davon war sie falsch.

**Der Ausschnitt folgt dem Laufweg, nicht dem Bahnhof.** Ein grosser Bahnhof
ist vierhundert Meter lang; passt er ganz ins Bild, ist der Umsteigeweg ein
Strich von zwei Zentimetern irgendwo darin — und die Frage, um die es geht
(„wo muss ich lang"), bleibt unbeantwortet. Massgebend sind deshalb der Weg
selbst und die beiden beteiligten Gleise. Die übrigen Bahnsteige werden
weiterhin gezeichnet, als Orientierung; was nicht in den Ausschnitt passt,
schneidet das SVG ab, und die Gleisnummer steht dort, wo der Bahnsteig ins
Bild kommt, statt an seinem Anfang ausserhalb.

**Overpass ist der wunde Punkt.** Der Dienst stellt Anfragen bei Last in eine
Warteschlange — gemessen: elf Sekunden für eine *triviale* Abfrage —, und die
Ausweichserver sind zeitweise ganz weg (HTTP 502). Drei Dinge dagegen:

- **Vier Instanzen statt zwei**, der Reihe nach, und jede bekommt nur einen
  Teil des Zeitbudgets. Vorher wartete eine Anfrage zweimal fünfzig Sekunden
  und gab dann auf, obwohl eine dritte Instanz sofort geantwortet hätte.
  Nachgemessen über fünf Bahnhöfe: 2,4 s bis 35,7 s, alle mit Ergebnis.
- **Nur weltweite Instanzen.** Regionale Auszüge wie `overpass.osm.ch`
  antworten für einen deutschen Bahnhof mit HTTP 200 und einer *leeren*
  Liste — von „nicht kartiert" nicht zu unterscheiden. Aus demselben Grund
  gilt eine Antwort nur dann als Erfolg, wenn sie sich als JSON lesen lässt:
  überlastete Instanzen schicken eine HTML-Fehlerseite mit Status 200.
- **Es wird wiederholt.** Vorher setzte die Anzeige ihr `geladen`-Flag,
  *bevor* die Antwort da war — schlug sie fehl, tat erneutes Aufklappen
  nichts mehr, und der Rat „später noch einmal aufklappen" ging ins Leere.
  Jetzt gilt ein Versuch erst als erledigt, wenn er etwas geliefert hat, und
  zwei Wiederholungen mit wachsendem Abstand laufen von selbst; der
  Zwischenstand steht im Kasten, statt dass minutenlang „Lade Bahnsteige …"
  stehen bleibt.

Der **Streckenverlauf der Baustellen** fragt dieselben Instanzen in
*umgekehrter* Reihenfolge ab. Er ist Beiwerk, der Umstiegsplan nicht — fragt
das Beiwerk zuerst die Hauptinstanz, verbraucht es genau das Kontingent, das
gleich der Bahnhofsplan braucht.

Ein Fallstrick, der beim Bauen aufgefallen ist: Würzburg Hbf liefert vierzehn
Objekte mit den Nummern 1–14 — das ist aber der **Busbahnhof davor**
(`bus=yes`, `highway=platform`). Ohne den Filter in `Overpass.php` hätte die
App Bussteige als Zuggleise angezeigt. Ausgeschlossen wird nur, was sich
ausdrücklich als Nicht-Bahn ausweist; ein fehlendes `train`-Tag heisst bei
Bahnsteigen meist nur, dass es niemand eingetragen hat.

**Das Aufräumen darf die teuren Einträge nicht wegwerfen.** Der Cache-Ordner
wird gelegentlich durchgesehen, damit er nicht unbegrenzt wächst — mit dem
Standardwert von einem Tag. Genau der traf aber jeden Tag die Einträge, die am
teuersten zu beschaffen sind: Bahnsteige gelten sieben Tage, Streckenverläufe
dreissig, und Overpass durfte sie danach jedes Mal neu liefern. Die Grenze
richtet sich jetzt nach der längsten eingestellten Haltbarkeit.

Wo die Fusswege fehlen, bleibt es bei der Lage ohne Weg — die Anzeige sagt das
dann auch. Geladen wird erst beim Aufklappen: Overpass ist ein kostenlos
betriebener Gemeinschaftsdienst, ungefragte Abfragen für jeden sichtbaren
Umstieg wären unfair. Bahnsteige und Wege kommen in **einer** Abfrage und
werden sieben Tage gecacht (rund 70 KB je Bahnhof).

### Grosse Baustellen im Netz

`?action=works` liefert Bauarbeiten mit **betroffenem Abschnitt** (von Bahnhof
A bis Bahnhof B), Zeitraum und — soweit ermittelbar — dem tatsächlichen
Streckenverlauf.

**Eine eigene Karte, nicht die Routenkarte.** Baustellen und Suchergebnisse
beantworten verschiedene Fragen und stehen einander im Weg: über einer
gefundenen Verbindung liegen ein Dutzend markierter Abschnitte, die mit ihr
nichts zu tun haben, und der Ausschnitt kann nicht beiden gerecht werden — die
Route will Zürich–Wien zeigen, die Baustellenkarte das ganze Netz. Der Kasten
unter der Trefferliste bringt deshalb seine eigene Karte mit; gebaut wird sie
erst beim Aufklappen, denn eine Karte lädt Kacheln.

#### Zwei Quellen, Deutschland zuerst

| Land | Quelle | liefert |
|---|---|---|
| Deutschland | DB InfraGO über `strecken-info.de/api/baustellen` | Totalsperrungen im ganzen Netz, mit Betriebsstelle, Zeitraum, Art der Arbeiten und Streckennummer |
| Österreich, Schweiz | HAFAS Information Manager der ÖBB (`HimSearch`) | Betriebsmeldungen mit Abschnitt und Zeitraum, teils mit Streckenverlauf |

Vorher gab es nur die ÖBB-Quelle, und die ist österreichlastig: nachgemessen
über 500 Meldungen 452 mit österreichischem, 17 mit deutschem und 9 mit
schweizerischem Anfangsbahnhof — nach Kategorie- und Dauerfilter blieb aus
Deutschland praktisch nichts übrig. Für eine Übersicht „wo wird gerade gross
gebaut" war das die falsche Hälfte des Bildes. Jetzt stehen 60 deutsche
Vorhaben vor 35 österreichischen.

**Für die Schweiz gibt es keine Quelle.** Die ÖBB-Instanz kennt zwar
schweizerische Meldungen — neun von 500 —, aber keine davon übersteht den
Kategorie- und Dauerfilter; in der Liste steht deshalb nichts aus der Schweiz.
Geprüft und ergebnislos: der offene Datenkatalog der SBB (`data.sbb.ch`)
enthält keinen Datensatz zu Bauarbeiten, und `opentransportdata.swiss` verlangt
für die interessanten Datensätze einen Schlüssel.

Zwei Eigenheiten der DB-Schnittstelle haben Arbeit gemacht:

- **`revision`.** Jede Anfrage muss den Datenstand nennen, auf den sie sich
  bezieht; einen Endpunkt, der ihn allein liefert, gibt es nicht (die
  Weboberfläche bekommt ihn beim Start mitgeliefert). Die Zahl wächst monoton,
  und der Server nimmt ein Fenster von einigen hundert Ständen an.

  Entscheidend ist, dass er sagt, **in welche Richtung** man suchen muss:
  *„Angefragte Revision 3520724 zu alt"* gegen *„Revision 3530000 existiert
  noch nicht"*. Genau diese Unterscheidung fehlte im ersten Wurf — jeder
  Fehlschlag galt als „zu neu", also lief die Suche nach unten, während der
  hinterlegte Ausgangswert in Wirklichkeit veraltet war und es nach oben
  gegangen wäre. Die Folge: **sobald der Startwert alt genug war, blieben die
  deutschen Baustellen stumm aus** und die Liste zeigte wieder nur Österreich.
  Jetzt wird die Fehlermeldung ausgewertet, exponentiell nach oben getastet
  und dann halbiert. Der zuletzt gültige Stand wird gemerkt; im Normalfall
  bleibt es bei einer einzigen kleinen Abfrage.
- **Koordinaten in EPSG:3857**, nicht in Grad.

Gefiltert wird auf **Totalsperrungen ab einer Woche Dauer** und nach
`baustellenID`-Präfix gebündelt: ein Bauvorhaben zerfällt in der Quelle in
viele Einzeleinträge (je Richtung, je Abschnitt, je Zeitfenster), aus 66
Einträgen „1E79F.x" wird ein Vorhaben. Ohne das stünden über viertausend
nächtliche Sperrpausen in der Liste.

Für die ÖBB-Seite gilt weiterhin: nach Kategorie filtern (1–3 sind
Betriebsmeldungen, 4 sind Reisehinweise — ohne den Filter standen 117
„ACHTUNG: Starker Reisetag" in der Liste), nach Land filtern, und richtungs-
wie zeitraumunabhängig entdoppeln.

#### Der Streckenverlauf

Eine gerade Linie zwischen zwei Betriebsstellen läuft quer durchs Gelände,
während die Schiene einen Bogen macht. Deshalb zwei Wege zum echten Verlauf:

- **Die ÖBB liefert ihn mit** — `getPolyline: true` im `HimSearch`-Request.
  Der Schalter gehört in `req`, nicht in `cfg`; dort quittiert ihn HAFAS mit
  *„Parse fail"*.
- **Für die deutschen Abschnitte** kommt er aus OpenStreetMap: deutsche
  Strecken tragen ihre VzG-Nummer als `ref` an den Gleisen. `RailGeometry`
  holt das Stück Netz mit dieser Nummer — begrenzt auf ein Rechteck um die
  beiden Endpunkte, sonst antwortet Overpass mit einer Zeitüberschreitung —
  und sucht darin per Dijkstra den Weg von einem Endpunkt zum anderen.

  **Lücken schliessen:** Innerhalb eines Bahnhofs tragen die Gleise meist
  keine Streckennummer; sie klebt an der freien Strecke. Der Graph riss
  deshalb genau dort auseinander, wo die Endpunkte liegen. Enden zweier
  Gleisstücke, die keine vierzig Meter auseinanderliegen, werden verbunden.

Alle Abschnitte kommen in **einer** Overpass-Abfrage mit je einer begrenzten
Teilabfrage — sechzig einzelne Anfragen wären unhöflich und langsam. Pro
Aufruf werden höchstens zwölf Abschnitte nachgeladen, das Ergebnis hält
dreissig Tage (Schienen ziehen nicht um), und die Karte wird so von Aufruf zu
Aufruf genauer. Wo es nicht klappt — keine Streckennummer, in OSM nicht
erfasst, Overpass überlastet — bleibt es bei der geraden Linie; sie wird
gestrichelt gezeichnet, der echte Verlauf durchgezogen.

**Erfolg hält dreissig Tage, Misserfolg nur einen.** Ein leerer Eintrag heisst
nämlich nicht zwingend „gibt es in OSM nicht" — er entsteht genauso, wenn
Overpass an dem Tag nur einen Teil der Gleise geliefert hat. Gemessen an
denselben zwanzig Abschnitten schwankte die Ausbeute je nach erwischter
Instanz zwischen 7 und 11; ohne diese Unterscheidung hätte sich so eine
Schwankung für einen Monat festgesetzt.

Nachgemessen: **41 von 60 deutschen Abschnitten** (68 %) mit echtem
Streckenverlauf, dazu 4 der 35 österreichischen aus den HAFAS-Polylinien. Die
Längen sind plausibel — Berlin Zoologischer Garten bis Friedrichstrasse 4,9 km
Verlauf gegen 4,0 km Luftlinie, Meerbeck–Xanten 26,3 gegen 25,3.

Was übrig bleibt, scheitert an der Erfassung, nicht am Verfahren: die Strecke
zerfällt in OSM in mehrere unverbundene Teile (Hochrheinbahn Rheinfelden–
Waldshut, Berlin Bornholmer Strasse–Schönholz) oder am gemeldeten Endpunkt
liegt gar kein Gleis (Neustadt–Puttgarden: 52 km bis zum nächsten). Dort bleibt
die gerade Linie.

### Leistung auf schwacher Hardware

Vier Posten, die auf einem älteren Laptop ohne GPU-Beschleunigung spürbar sind
und deshalb entschärft wurden:

- **`backdrop-filter` nur noch auf `.panel` und `.map`.** Vorher lag er auf
  zehn Selektoren, davon acht mit inzwischen deckendem Hintergrund — der Blur
  war dort unsichtbar und kostete trotzdem je eine Compositing-Ebene, bei
  `.journey` sogar eine **pro Ergebniskarte**.
- **Der Hintergrundverlauf liegt auf `body::before`**, nicht mehr per
  `background-attachment: fixed` auf dem `body` selbst. Fixierte Hintergründe
  erzwingen bei jedem Scrollschritt ein Neuzeichnen des ganzen Sichtbereichs;
  als eigene fixierte Ebene wird nur noch verschoben.
- **Die Nerd-Regler entstehen erst beim ersten Öffnen** des Modus. Es sind über
  fünfzig Schieberegler; vorher wurden sie bei jedem Seitenaufruf gebaut, auch
  im Normal-Modus hinter `display: none`. Gemessen: 294 statt 683 DOM-Knoten
  und 1 statt 49 `input[type=range]` beim Start.
- **Kartenschwenks sind auf einen Frame gebündelt.** `pointermove` feuert auf
  schnellen Mäusen über hundertmal pro Sekunde, und jedes `render()` baut
  Kachelgitter und SVG-Overlay komplett neu auf.

**Eine Regel dabei ist wichtig: alles Anklickbare bekommt einen deckenden
Grund.** Karten, Knöpfe, Panels und Eingabefelder benutzen `--surface-card`
bzw. `--field-bg`, nicht das halbtransparente `--surface`. Der Grund ist
handfest: über dem Glas-Panel und der Kartenkachel-Ebene ergibt dieselbe
transparente Fläche je nach Untergrund einen anderen Kontrast. Beim `<select>`
kam dazu, dass das aufgeklappte Menü vom System gezeichnet wird — es erbt die
Textfarbe, malt den Hintergrund aber selbst, was im dunklen Theme fast weissen
Text auf hellem Menü ergab. Transparenz bleibt deshalb den rein dekorativen
Containern vorbehalten: `.panel`, `.map`, `.notice`.

**Zurück-Link anpassen:** Oben links führt ein Knopf zurück zur Hauptseite. Das
Ziel steht in `index.html` und zeigt standardmäßig auf `/`:

```html
<a class="back" href="/" aria-label="Zurück zur Hauptseite">
```

Liegt das Tool in einem Unterordner einer größeren Seite, trag dort die
gewünschte Adresse ein.

**Tab-Icon anpassen:** In `index.html` stehen drei `<link rel="icon">`-Zeilen
mit absoluten Pfaden auf die Icons der Hauptseite:

```html
<link rel="icon" type="image/svg+xml" href="/assets/pictures/MMR_v2.svg?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/pictures/MMR_v2.png?v=2">
<link rel="apple-touch-icon" href="/assets/pictures/MMR_v2.png?v=2">
```

Absolut, damit sie auch aus einem Unterordner heraus stimmen — die Dateien
liegen ja bei der Hauptseite, nicht beim Tool. Läuft das Tool auf einer eigenen
Domain, leg die Icons dort ab und trag den passenden Pfad ein. Das PNG ist
Absicht: SVG-Favicons zeichnen nicht alle Browser, unter Windows blieb der Tab
sonst leer.

**Als Teilseite einbetten:** Übernimm den Inhalt von `<div class="wrap">` in deine
Seite und binde `style.css` sowie `<script type="module" src="assets/js/app.js">`
ein. Wichtig: Das Skript braucht `type="module"`.

---

## Wie die Bewertung funktioniert

### Wie ein Zug heisst

Im Fernverkehr ist die **Zugnummer** der Name: ein ICE 593 fährt heute so und
morgen anders, und genau so steht es an der Anzeigetafel. Im Nahverkehr ist es
umgekehrt — dort steht die **Linie** angeschrieben, und die Zugnummer ist eine
interne Betriebsnummer, die auf keiner Tafel auftaucht.

Die App zeigte lange die Nummer, auch im Nahverkehr: aus einer S 11 wurde
„S 20318". Der Grund lag im HAFAS-Feld, das gelesen wurde. HAFAS liefert für
dieselbe Fahrt:

```
name  = "S 33 (Zug-Nr. 20326)"     nameS = "S 33"
prodCtx.num  = "20326"             prodCtx.line = "33"
```

Genommen wurde bisher der *längere* der beiden Namen — eine Regel, die im
Fernverkehr richtig ist (dort steht die Nummer mal nur im einen, mal nur im
anderen) und im Nahverkehr genau danebengreift. Jetzt gilt:

- `prodCtx.line` gesetzt → **die Linie** benennt den Zug (`S 33`, `RE 48`).
  Trägt die Linie die Gattung schon in sich (`RE3`), wird sie nicht doppelt
  davorgesetzt.
- `prodCtx.line` leer → Gattung plus Zugnummer (`ICE 593`).
- Der Zusatz „(Zug-Nr. …)" fliegt aus dem Produktnamen; er ist eine
  Anzeigehilfe von HAFAS und gehört in keine Beschriftung.

Ein Nebeneffekt, der Arbeit gemacht hat: `sameTrain()` verglich zwei Meldungen
über ihre **Beschriftung**. Das ging, solange die Nummer darin stand — mit der
Linie nicht mehr, denn auf der S 33 sind zu jeder Zeit mehrere Züge unterwegs.
Verglichen wird jetzt die Zugnummer, und nur wo keine vorliegt, der
Produktname.

### Sortierung der Trefferliste

Über der Liste steht ein Menü mit drei Möglichkeiten.

**Voreingestellt ist „Abfahrt — chronologisch".** Das ist die Reihenfolge, in
der die Züge fahren: man sucht sich die Abfahrt, die zeitlich passt, und
vergleicht erst dann. Die Empfehlung mischt Preis und Dauer zu einer Punktzahl
— als Voreinstellung verbirgt sie, wonach eigentlich sortiert wurde.

| Auswahl | Reihenfolge |
|---|---|
| **Empfehlung** (Normal) / **Strecke & Komfort** (Nerd) | das Bewertungsmodell des jeweiligen Modus, siehe unten |
| **Preis** | günstigste zuerst; bei Gleichstand die frühere Abfahrt. Verbindungen **ohne** Preisangabe stehen am Ende — eine fehlende Angabe ist kein Nullpreis |
| **Abfahrt** | chronologisch. Die Suche liefert die Verbindungen ab der gewählten Zeit, nach unten wird es also später |

Eine ausdrückliche Sortierung **schlägt beide Bewertungsmodelle**: wer „nach
Preis" wählt, will eine Preisliste sehen, keine Empfehlung — und im Nerd-Modus
auch keine Gruppierung nach Routenvarianten. Die Variantenüberschriften
entfallen dann.

Umsortiert wird sofort, ohne neue Netzabfrage — es ist dieselbe Trefferliste in
anderer Reihenfolge, auch über nachgeladene Seiten hinweg. Dabei springt die
**Auswahl zurück an den Listenanfang**: die Karte über der Liste zeigt immer die
ausgewählte Verbindung, und bliebe sie an der alten hängen, änderte sich im
halben Bild nichts, obwohl die Liste längst neu sortiert ist. Bei Modus- und
Reglerwechseln bleibt die Auswahl dagegen an ihrer Verbindung kleben — dort hat
man sich für eine entschieden und schraubt nur an der Reihenfolge drumherum
(`rerank({ keepSelection })`).

### Normal-Modus

Preis, Dauer und Umstiege werden je auf 0–1 normiert und gewichtet
(40 % / 40 % / 20 %). Kleinste Punktzahl gewinnt.

### Nerd-Modus

Kein Preismodell, sondern eine Frage des Weges. Die Treffer werden zu
**Routenvarianten** gruppiert, und innerhalb einer Variante gilt:

1. wenigste Umstiege
2. bei Gleichstand die kürzere Fahrt
3. bei Gleichstand der angenehmere Zug

Die **Reisezeit wird bewusst nicht verrechnet**. Sie entscheidet erst, wenn alles
andere gleich ist — es gibt keinen Wechselkurs zwischen einer Stunde Fahrzeit und
einem Umstieg. Der Preis steht an der Karte, geht hier aber nicht in die Wertung
ein. In welcher Reihenfolge die Varianten stehen, bestimmt deine Bewertung unter
**Lieblingsstrecken** und **Lieblingszüge**.

### Strecken erkennen

`assets/js/data/routes.js` beschreibt gut dreißig Korridore — Gotthard-Basistunnel,
Gotthard-Bergstrecke, SFS Köln–Rhein/Main, NBS Wendlingen–Ulm, Allgäubahn, Gäubahn,
Westbahn, Arlberg, Semmering und so weiter, je mit Streckenhöchstgeschwindigkeit.
Erkannt werden sie an den **Namen der Zwischenhalte**: die Wegpunkte einer Strecke
müssen in konsistenter Reihenfolge im Zuglauf vorkommen.

Zwei Schwellen verhindern Fehlzuordnungen, weil sich Korridore Endpunkte teilen:

- **Deckung ≥ 60 %** der Wegpunkte. Sonst würde jeder Zug München–Augsburg auch
  als „München–Augsburg–Nürnberg" gelten.
- **höchstens ein ausgelassener Wegpunkt am Stück**. Wer Kempten und Immenstadt
  überspringt, fährt nicht über das Allgäu, sondern über Memmingen.

Beanspruchen mehrere Korridore denselben Abschnitt, gewinnt der mit den meisten
wiedergefundenen Wegpunkten. Eine feste Deckungsschwelle taugt dafür **nicht**:
schnelle Züge halten an den Zwischenpunkten gerade nicht — ein ICE über die SFS
Nürnberg–Ingolstadt lässt Allersberg und Kinding aus und wäre ausgerechnet als
der Zug durchgefallen, für den die Strecke gebaut wurde. Ein einzelner
gemeinsamer Halt gilt dagegen als Übergang, nicht als Widerspruch: Bern–Olten
und Bern–Brig treffen sich in Bern und gelten beide als befahren.

Die Fälle, an denen frühere Fassungen gescheitert sind, stehen als Test in
`bin/test_routes.mjs`:

```bash
node bin/test_routes.mjs
```

Der Knopf **„Schnelle Strecken bevorzugen"** setzt alle Regler auf einmal aus der
Streckenhöchstgeschwindigkeit — 300 km/h gibt +3, eine Bergstrecke mit 90 km/h −2.

**Was die Liste nicht abdeckt**, wird geschätzt: aus Luftlinie und Fahrzeit
ergibt sich ein Durchschnittstempo, und der Regler *„Unbenannte Strecken nach
Tempo"* bestimmt, wie stark das zählt. Diese Einträge sind gestrichelt und mit
`ø` gekennzeichnet, weil die Zahl etwas anderes bedeutet als bei den gepflegten
Strecken — eine Reisegeschwindigkeit inklusive Halten, keine
Streckenhöchstgeschwindigkeit.

### Live-Verfolgung

Jede Verbindung hat einen Knopf **„Live verfolgen"**. Das Panel unter der Karte
frischt alle 30 Sekunden die Echtzeitlage aller Abschnitte auf: Verspätung,
Ist-Zeiten je Halt, Gleiswechsel, Meldungen. Für München kommen die
Störungsmeldungen der MVG dazu, gefiltert auf die tatsächlich benutzten Linien.

Grundlage ist die HAFAS-Journey-ID (`leg.jid`), die jeder Zugabschnitt mitbringt.
Verbindungen, deren Fahrplan von der DB statt der ÖBB stammt, haben keine — das
Panel sagt das dann auch.

Die Verfolgung **überlebt Neuladen und neue Suchen**: die Verbindung liegt unter
`train-maxxing:tracked` im localStorage und wird beim Start wieder aufgenommen,
solange die Fahrt noch läuft. Taucht sie in der aktuellen Trefferliste nicht auf
— nach einer anderen Suche oder nach dem Umdisponieren — steht oben eine Zeile
„Du verfolgst …", damit sie nicht unsichtbar weiterläuft.

**Alternativen sind auswählbar, nicht nur Information.** Schon in der
Trefferliste steht unter jedem Umstieg von 1–4 Minuten, was die nächsten
Verbindungen ab dem Umsteigebahnhof wären — jede davon per „übernehmen"
anzunehmen. Die Verbindung wird dann an Ort und Stelle durch die
umdisponierte Variante ersetzt und als solche gekennzeichnet; die Abschnitte
davor bleiben stehen. Dieselbe Mechanik (`spliceJourney`) benutzt die
Live-Verfolgung, wenn ein Anschluss unterwegs platzt.

**Anschlusswache.** Aus den Ist-Zeiten wird je Umstieg gerechnet, ob er noch zu
schaffen ist: der Zubringer kommt um X an, der Anschluss fährt um Y ab. Liegt Y
vor X, ist der Anschluss weg — auch wenn im Fahrplan zwanzig Minuten standen.
Unter zwei Minuten Rest gilt als gefährdet.

Dann lädt die App die nächsten Verbindungen ab dem Umsteigebahnhof und bietet sie
zur Auswahl an. Ein Klick auf **„übernehmen"** ersetzt die Verbindung ab dem
geplatzten Umstieg — die bereits gefahrenen Abschnitte bleiben stehen, man sitzt
ja im Zug — und die Verfolgung läuft mit der neuen Route weiter.

Alarm gibt es nur bei echten Echtzeitdaten. Ein knapper *Fahrplan*-Umstieg steht
schon an der Verbindungskarte und würde hier nur doppelt warnen.

**Auf der Karte** wird die verfolgte Verbindung grün hervorgehoben — Verlauf,
Start und Ziel. Die Zugposition selbst ist **rot**: alle Live-Züge des
Ausschnitts sind grün, und der eigene ging darin unter, obwohl er der einzige
ist, den man wirklich sucht.

Der Zug ist dabei **ein einziger Punkt** — derselbe kleine Punkt wie jeder
andere Live-Zug, nur rot statt grün. Einen zweiten, größeren Marker gibt es
nicht; er stand nur dem eigentlichen Punkt im Weg.

Er wird **immer** gezeichnet, solange verfolgt wird. Das ist nicht
selbstverständlich, denn die Positionsantwort ist auf 40 Züge im Ausschnitt
gedeckelt — zoomt man heraus, fällt der eigene Zug regelmäßig heraus. Steht er
nicht in der Antwort, ergänzt ihn `RouteMap.trainsOnMap()` aus der zuletzt
bekannten Position, mitsamt Tooltip und Antippbarkeit. Sonst wäre ausgerechnet
der Zug unsichtbar, um den es geht.

War die Position aus dem Fahrplan hochgerechnet statt gemeldet, bleibt der
Punkt **hohl** und der Tooltip sagt es dazu. Für die Hochrechnung wird der
Restfahrplan um die bekannte Verspätung verschoben; sonst läge ein verspäteter
Zug ausserhalb jedes Zeitfensters und wäre gar nicht auffindbar.

Hochgerechnet wird zwischen zwei **Halten**, also auf der Luftlinie — und die
schneidet jeden Bogen ab. Der Punkt sass dadurch sichtbar neben der Linie, auf
der er fahren sollte, im Extremfall zig Kilometer. `snapToLine()` zieht ihn
deshalb auf den gezeichneten Streckenverlauf des Abschnitts: Lotfusspunkt auf
das nächste Segment, auf das Segment begrenzt. Gemeldete Positionen bleiben
unangetastet — die liegen ohnehin auf dem Gleis.

Dass die Ergänzung beim Zeichnen passiert und nicht in der Verfolgung, ist
wichtig: die Live-Züge wechseln bei jedem Schwenk und Zoom, die Verfolgung
rechnet nur alle 30 s. Aus veralteten Daten entschieden, blieb der Punkt grün
oder verschwand beim Herauszoomen.

**Wiedererkannt** wird der Zug über `sameTrain()` — Bezeichnung („ICE 516"),
ersatzweise die blosse Nummer, wenn der Positionsmeldung die Gattung fehlt.
Die `jid` taugt dafür nur bedingt: HAFAS baut sie pro Anfrage neu auf, die
Kennung aus der Verbindungssuche und die aus der Positionsmeldung sind deshalb
in aller Regel verschieden.

**Mitfahren (GPS)** schaltet `watchPosition` dazu: die Position landet auf der
Karte, und die Route wird zugeordnet — „Zwischen Augsburg Hbf und Günzburg —
25,1 km hinter Augsburg Hbf. Noch 20 Halte." Gesucht wird dabei der *Abschnitt*
mit der kleinsten Entfernungssumme zu beiden Enden, nicht der nächstgelegene
Halt allein: zwischen zwei Halten kann der vor einem liegende näher sein als der
hinter einem. Die Position verlässt den Browser nicht.

### Ergebnisliste und Umstiege

Angezeigt werden **sechs Verbindungen**; der Knopf darunter klappt erst die
restlichen geladenen auf und holt danach die nächste Seite. Das ist nötig, weil
HAFAS je Anfrage bei rund sechs Treffern deckelt — spätere Abfahrten gibt es nur
über den Blätter-Kontext (`outCtxScrF`) der vorigen Antwort.

Die Umsteigezeit steht standardmäßig auf **kürzestmöglich**. Damit tauchen auch
Verbindungen mit vier Minuten Umstieg auf — und für genau die (1 bis 4 Minuten)
lädt die App den **nächstspäteren Anschluss** nach und schreibt ihn unter die
Warnung: „Verpasst? 13:36 → 14:53 · SBB 19732 · S 18955 · +30 min später am Ziel."
Die Frage bei einem Vier-Minuten-Umstieg ist nicht, ob man ihn schafft, sondern
was passiert, wenn nicht.

### Zugkomfort und die Sache mit dem ICE 4

**Die Fahrplandaten enthalten keine Baureihe.** Du bekommst Gattung und Zugnummer
(`ICE 118`, `RJX 262`), aber nirgends steht, ob das ein ICE 4 (BR 412) oder ein
ICE 3neo (BR 408) ist.

Im Nerd-Modus bewertest du deshalb unter **Lieblingszüge** die Fahrzeuge selbst —
ICE 4, ICE 3neo, Giruno, railjet und so weiter, jeweils von −5 (meiden) bis +5
(bevorzugen). Die Bewertung greift, sobald das Fahrzeug bekannt ist. Dafür gibt
es genau zwei Wege:

1. **Die Gattung lässt nur ein Fahrzeug zu.** railjet, Nightjet, WESTbahn und
   TGV sind damit immer eindeutig — im UI mit „immer erkennbar" markiert.
2. **Die Wagenreihung liefert die Baureihe** (BR 412 → ICE 4). Nur deutscher
   Fernverkehr, nur am Reisetag, und nur wenn in `config.php` aktiviert.

Ist das Fahrzeug unbekannt, greift die Gattungsbewertung aus
`assets/js/data/trains.js` — das Tool rät nicht. An jeder Verbindung steht, ob
das Modell erkannt wurde und woher.

Die frühere Variante mit Zugnummernbereichen ist entfallen: Nummernkreise sind
nicht stabil genug, um daraus verlässlich auf eine Baureihe zu schließen.

### Baureihe: gelöst über bahn.expert

Die Baureihe kommt jetzt aus der Wagenreihung — `ICE 4 (BR412)`, inklusive
Wagenzahl je Klasse. Bezogen über **bahn.expert**, das dieselben Daten
(Quelle `DB-risTransports`) über eine erreichbare Schnittstelle anbietet.

Zwei Fallstricke, falls du daran arbeitest:

- Der Parameter `input` muss **doppelt JSON-kodiert** sein: ein JSON-String,
  der das Array enthält. Sonst antwortet der Dienst mit
  `"[object Object]" is not valid JSON`.
- Die Antwort ist superjson: Element 0 ist die Wurzel, jeder Wert darin ein
  Index in dasselbe Array. `CoachSequence.php` navigiert gezielt statt das
  Format allgemein aufzulösen.

Es gilt weiterhin: nur deutscher Fernverkehr, nur am Reisetag.

**bahn.expert ist ein privat betriebenes Projekt, kein offizieller Dienst.**
Deshalb ist das Tool zurückhaltend: Ergebnisse werden 30 Minuten gecacht,
`max_lookups` begrenzt die Abfragen je Verbindung auf drei, und jeder Fehler
führt stillschweigend dazu, dass die Baureihe eben fehlt.

Wer das Tool dauerhaft betreibt, sollte auf den **DB API Marketplace**
wechseln: Das Modul `RIS::Transports` liefert dieselben Daten offiziell, unter
Vertrag und mit API-Key. Dann tauscht du in `CoachSequence.php` nur die
`fetch()`-Methode aus.

### Der direkte DB-Weg: weiterhin verschlossen

Die Baureihe aus der Wagenreihung zu holen, ist bisher **nicht gelungen**. Was
geprüft wurde:

| Weg | Ergebnis |
|---|---|
| `bahn.de/web/api/reisebegleitung/wagenreihung/vehicle-sequence` | **HTTP 422** bei acht Parametervarianten — mit und ohne Zeitzone, Sekunden, Session-Cookies, `administrationId` 80/1080, `date` vs. `departure`. Getestet mit einem real fahrenden ICE. |
| JS-Bundles von bahn.de nach dem echten Aufruf durchsucht | `vehicle-sequence` kommt in den geladenen Bundles nicht vor; der Code liegt in einem Chunk, der sich nicht auffinden ließ |
| `ist-wr.noncd.db.de` (der alte Endpunkt) | kein DNS-Eintrag mehr, abgeschaltet |
| HAFAS `JourneyDetails` mit `getTrainComposition`, Methoden `TrainComposition`/`TrainFormation` | keine Wagenfelder bzw. Methode existiert nicht |

Der Endpunkt **existiert** (422 statt 404), aber die Parameterkombination ließ
sich nicht ermitteln. Da bahn.expert dieselben Daten liefert, ist das kein
Problem mehr — der Abschnitt steht hier nur, damit niemand denselben Weg noch
einmal geht.

Für Fahrten in der Zukunft gibt es ohnehin keine Wagenreihung. Dafür sind die
„immer erkennbaren" Modelle (railjet, Nightjet, WESTbahn, TGV) und die
Gattungsbewertung da.

### Karte

Eine echte Slippy-Map mit Kartenhintergrund: ziehen zum Verschieben, scrollen
oder Pinch zum Zoomen, dazu Knöpfe für Zoom und „ganze Route zeigen". Sie ist
**immer sichtbar** — ohne Suche zeigt sie den Überblick, die Routen kommen
dazu, sobald Start und Ziel stehen. Alle gefundenen Routen liegen übereinander,
die ausgewählte ist hervorgehoben. Klick auf eine Linie wählt die Verbindung,
Klick auf eine Verbindung hebt die Linie hervor — beides synchron.

**Zur Bedienung mit der Maus:** Der Pointer wird erst nach mehr als sechs Pixel
Bewegung eingefangen. Vorher bleibt ein Klick ein Klick, auch wenn die Maus
dabei leicht wackelt — sonst verschluckt `setPointerCapture` die Auswahl von
Routen und Zügen. Und das beim Drücken getroffene Element wird gemerkt, weil
`event.target` nach einem Capture auf den Viewport zeigt statt auf die Linie.

Gebaut ohne Leaflet: ein Kachel-Layer aus `<img>`-Elementen, darüber ein SVG mit
Routen, Halten und Zugpositionen. Das spart ein mitzulieferndes Paket und hält
die Kachelquelle an einer Stelle (`TILES` in `assets/js/map.js`).

**Zur Kachelquelle:** OpenStreetMap direkt, ohne Schlüssel.

Vorher stand hier CARTO. Deren Basemap-CDN verlangt inzwischen einen
API-Schlüssel — und verweigert die Auskunft nicht etwa mit einem Fehlercode,
sondern liefert weiterhin **HTTP 200 mit einem Bild, auf dem „API key
required" steht**. Für den Browser ist das eine gültige Kachel, `onerror`
schlägt nie an, und die Karte besteht aus lauter Fehlermeldungen, ohne dass die
App etwas davon merkt. Genau so sah es aus.

OSM braucht keinen Schlüssel. Die Nutzungsbedingungen verlangen die
Namensnennung — sie steht unten rechts im Bild und darf nicht entfernt werden —
und keine Massenabfragen; eine Handvoll Kacheln je Seitenaufruf erfüllt das.

**Die Karte ist schwarzweiss.** Der Hintergrund ist Hintergrund; Farbe gehört
den Routen, den Zügen und den Baustellen darüber. Die OSM-Standardkacheln sind
bunt — grüne Wälder, gelbe Strassen, blaue Flüsse —, und darüber gingen die
farbigen Linien unter.

**Dunkles Layout ohne zweite Quelle:** OSM hat keine dunklen Kacheln. Derselbe
Filter erledigt das mit — `invert(1)` dreht Hell und Dunkel um. Einen
`hue-rotate` braucht es nicht: nach dem Entsättigen ist nichts Farbiges mehr
da, das sich verdrehen könnte.

**Die Werte sind an der alten Quelle geeicht**, und das war nötig. Ein erster
Versuch mit blossem Entsättigen sah schlechter aus als CARTO zuvor. Der Grund
liess sich messen: über eine Stadt- und eine Landkachel gemittelt liegt CARTO
„positron" bei einer Helligkeit von 0,92 und „dark matter" bei **0,05** — der
erste Versuch landete im dunklen Layout bei **0,22**, einem flauen Mittelgrau
statt einer dunklen Karte.

Der Fehler steckte im `contrast` unter 1: das zieht alles zur Mitte und hellt
die dunklen Flächen auf. Richtig ist das Gegenteil — Kontrast leicht **über**
1, und die Helligkeit danach herunterskalieren:

| | Filter | gemessen |
|---|---|---|
| hell | `grayscale(1) contrast(0.5) brightness(1.42)` | 0,91 (Ziel 0,92) |
| dunkel | `grayscale(1) invert(1) contrast(1.2) brightness(0.34)` | 0,051 (Ziel 0,052) |

Gemessen wird immer an derselben Kachel (Zürich, Zoomstufe 13) — über Stadt-
und Landkachel gemittelt fällt der Wert niedriger aus, und dann vergleicht man
Äpfel mit Birnen.

Die Reihenfolge zählt: `brightness` steht **nach** `invert` und skaliert die
umgedrehten Werte nach unten. Davor hätte es die Karte aufgehellt.

Der Filter liegt nur auf den Kacheln; das SVG mit Routen und Zügen darüber
bleibt unangetastet.

Damit sieht ein fremder Server die IP-Adressen deiner Besucher — das ist der
Preis für den Hintergrund. Wer das nicht will, setzt `TILES.url` auf `null`;
dann rendert die Karte nur die Routen, ganz ohne externe Requests.

**Beschriftungen überlappen nicht.** Für jeden Halt werden acht Positionen rund
um den Punkt durchprobiert; passt keine kollisionsfrei ins Bild, bleibt der Name
weg — ein fehlender Name ist besser als zwei übereinandergedruckte. Ab Zoomstufe
9 werden auch Zwischenhalte beschriftet.

### Wo fährt der Zug gerade?

Über der Karte lässt sich **„Züge live anzeigen"** einschalten. Dann erscheinen
alle Züge, die im sichtbaren Ausschnitt gerade unterwegs sind, als pulsierende
Punkte.

**Ein Klick auf einen Zug öffnet seinen kompletten Lauf** unter der Karte: alle
Halte mit Plan- und Ist-Zeit, Gleisen und der Verspätung je Halt. Oben steht die
größte Verspätung als Kennzeichen — grün „pünktlich", gelb ab einer Minute, rot
ab fünf oder bei Ausfall. Weicht die Ist-Zeit ab, wird die Planzeit
durchgestrichen und die tatsächliche daneben gezeigt. Störungsmeldungen des
Betreibers stehen darüber.

Die Positionen kommen von HAFAS (`JourneyGeoPos`) und werden aus Fahrplan und
Echtzeitlage **berechnet**, nicht per GPS geortet. Sie sind eine gute Näherung,
keine Ortung auf den Meter. Die Verspätungen dagegen sind echte Echtzeitdaten.
Beim Verschieben und Zoomen wird nachgeladen (gedrosselt, 30 Sekunden Cache);
ein zu großer Ausschnitt liefert bewusst nichts.

### Auslastung, knappe Umstiege, Bestpreis, Historie

**Auslastung** meldet die DB je Abschnitt und Klasse (Stufe 1 gering bis
4 ausgebucht). Sie steht als Kennzeichen an der Verbindung und je Abschnitt im
Detail — die Klasse richtet sich nach deiner Auswahl.

**Knappe Umstiege** rechnet das Tool selbst aus der Lücke zwischen Ankunft und
Weiterfahrt, Fußwege eingerechnet. Unter 5 Minuten gilt als riskant (rot), unter
10 als knapp (gelb). Bei drei bis fünf Umstiegen ist das meist der Punkt, an dem
eine Verbindung in der Praxis platzt.

**Mindestumsteigezeit** lässt sich im Suchformular einstellen (Standard 5 min,
„egal" bis 30 min). Der Wert wird an **beide** Quellen durchgereicht — HAFAS
kennt `minChgTime`, die DB `minUmstiegszeit`. Das ist deutlich besser als
nachträglich zu filtern: Die Quellen suchen dann passende Verbindungen, statt
dass knappe einfach wegfallen. Nachgemessen für Josef-Wirth-Weg → Garching
Forschungszentrum: ohne Vorgabe Umstiege von 3–4 Minuten, mit `5` dann 8–9, mit
`12` dann 13–14. Ein Nachfilter bleibt als Sicherheitsnetz; bliebe dadurch
nichts übrig, werden lieber die knappen Verbindungen gezeigt als eine leere
Liste.

**Fußwege** werden jetzt zuverlässig erkannt. Vorher tauchten sie als
„Unbekannt" auf, weil die DB im Nahverkehr das Feld `typ` schlicht weglässt und
der Abschnitt dadurch als Fahrzeug ohne Gattung durchging. Erkannt wird
stattdessen am Verkehrsmittel selbst: kein Gattungskürzel, keine Liniennummer,
Name „Fußweg". Bei der ÖBB gilt umgekehrt, dass alles außer einer Fahrt (`JNY`)
ein Weg zu Fuß ist — das deckt auch seltenere Abschnittstypen ab.

Unterschieden wird dabei, ob der Halt wechselt: „Umstieg am selben Halt" ist
etwas anderes als „Zu Fuss: Studentenstadt → Situlistraße". Letzteres bekommt
eine eigene Kennzeichnung, weil so ein Fußweg oft der Grund ist, warum eine
Verbindung schneller oder entspannter ist.

**Was nicht geht:** Von sich aus schlägt keine der beiden Quellen einen Fußweg
zu einer *anderen* Haltestelle vor, um dort besser umzusteigen. Für Josef-Wirth-Weg
→ Garching liefern beide ausschließlich die Route über Studentenstadt, nie über
Situlistraße. Wer so eine Variante will, kann sie im Nerd-Modus über
**„Über eine bestimmte Stadt"** erzwingen — dort Situlistraße eintragen.

**Bestpreis über den Tag:** Unter den Hinweisen stehen sechs Zeitfenster mit dem
jeweils günstigsten Angebot. Ein Klick übernimmt die Uhrzeit und sucht neu.
Gemessen für Zürich–München: 33,99 € abends gegen 41,99 € nachts.

**Pünktlichkeitshistorie** kombiniert drei Quellen, damit auch beim ersten
Aufruf eines Zuges eine ehrliche Zahl auf dem Bildschirm steht:

1. **Eigene Messungen.** Bei jedem Zuglauf mit Echtzeitdaten wird die
   beobachtete Verspätung festgehalten — höchstens ein Wert je Zug und Tag,
   gleitendes Fenster über 60 Beobachtungen bzw. 120 Tage. Zusätzlich wird ein
   **7-Tage-Fenster** ausgewiesen: „so ist es aktuell", nicht nur ein
   Langzeitschnitt.
2. **Baseline aus den Betreiber-Jahresstatistiken** (DB Konzernbericht, ÖBB
   Geschäftsbericht, SBB Jahresbericht). Damit gibt es auch beim allerersten
   Aufruf einen belastbaren Startwert — als solcher gekennzeichnet und im
   Blend über einen Bayes-Prior (Gewicht 5) mit den eigenen Messungen
   verrechnet. Schon ~10 eigene Werte übersteuern die Baseline sichtbar.
3. **Schweizer Ist-Daten V2** (Open-Data-Plattform Mobilität Schweiz). Für
   Züge, die durch die Schweiz fahren, kann die tatsächliche Verspätung aus
   der täglich veröffentlichten CSV berechnet werden — siehe Cron-Skript unten.

Jede Zahl trägt eine **Quellenkennung**: „aus eigenen Messungen", „Näherung aus
Betreiber-Jahresstatistik (noch keine eigenen Messungen)" oder „eigene Messungen
ergänzt um Betreiber-Statistik". Als pünktlich gilt unter 6 Minuten, wie im
Bahnverkehr üblich.

Gespeichert wird als JSON je Zug unter `api/cache/punctuality/` — kein
Datenbankserver nötig.

#### Schweizer Ist-Daten importieren (optional, Cron)

`bin/import_ch_istdaten.php` lädt die täglichen Ist-Daten-CSVs von
`data.opentransportdata.swiss/dataset/ist-daten-v2`, ermittelt je Zugfahrt die
Ankunftsverspätung am Endhalt und schreibt das Ergebnis in denselben
JSON-Store, den auch die Live-Sammlung nutzt. Für die App ist danach kein
Unterschied sichtbar — Ist-Daten-Samples verhalten sich wie eigene Messungen.

```bash
# Trockenlauf für gestern mit 200 000 Zeilen (ca. 10 s, ~5 000 Fahrten):
php bin/import_ch_istdaten.php --days=1 --limit=200000 --verbose

# Cron-Empfehlung: täglich morgens die letzten zwei Tage nachziehen
0 4 * * * cd /pfad/zu/train-maxxing && php bin/import_ch_istdaten.php --days=2 >> logs/import.log 2>&1
```

Optionen:

| Flag | Bedeutung |
|---|---|
| `--days=N` | Anzahl Tage rückwärts (Standard 7, max 60) |
| `--date=YYYY-MM-DD` | Nur diesen einen Tag holen |
| `--limit=N` | Nur die ersten N CSV-Zeilen (für schnelle Tests) |
| `--force` | Bereits importierte Tage erneut ziehen |
| `--verbose` | Fortschrittsmeldungen alle 100 000 Zeilen |

**Was der Importer nicht kann:** Deutschland und Österreich veröffentlichen
keine vergleichbaren Ist-Daten-Feeds. Für DE- und AT-Züge bleibt es bei
Baseline + eigener Sammlung. Die CH-Daten helfen aber auch dort mit, wenn die
Verbindung durch die Schweiz führt (Zürich–München erfasst den ICE zwischen
Zürich und Basel).

**Ressourcenbedarf:** Eine volle Tages-CSV ist rund 300–500 MB, die
Verarbeitung streamt zeilenweise und braucht wenige zehn MB RAM. Pro Tag
werden ~30 000–50 000 CH-Zugfahrten aggregiert; die Punctuality-JSONs bleiben
insgesamt im niedrigen zweistelligen MB-Bereich.

**Idempotenz:** Der Importer merkt sich erledigte Tage in
`api/cache/punctuality/.imports/YYYY-MM-DD.done`; `Punctuality::record()` selbst
lässt zusätzlich nur einen Wert je Zug und Tag zu. Ein doppelter Cron-Aufruf
richtet also keinen Schaden an.

#### Deutsche Ist-Daten aus bahnvorhersage.de/open-data (manuell)

Die Datenbasis existiert und deckt den Zeitraum ab September 2021 fast
vollständig ab — sie wird aber **nicht automatisch abgerufen**, weil:

- Die Downloads laufen über die [Mobilithek](https://mobilithek.info/) und
  erfordern einen Account (Anmeldung erforderlich, Freischaltung des Datensatzes
  auf Anfrage).
- Jährliche `.tar`-Archive mit täglichen **Parquet**-Dateien; ein Jahr sind
  mehrere Gigabyte.
- Parquet in reinem PHP zu parsen erfordert eine Zusatzabhängigkeit, die den
  Charakter „einfach hochladen und läuft" bricht.

Wer die Baseline für deutsche Züge mit echten Daten verfeinern möchte, kann die
Parquet-Dateien mit einem einmaligen Python-Skript in unser JSON-Format
umrechnen. Das relevante Schema (siehe
[Bahn-Vorhersage-Doku](https://bahnvorhersage.de/open-data/parsed-train-delays)):

- `is_final == true` — nur den letzten Prognosewert je Halt nehmen
- `is_arrival == true` — Ankunftsseite verwenden (analog zum CH-Importer)
- `delay` (Sekunden) — auf Minuten umrechnen, dann in `api/cache/punctuality/de/`
  ablegen mit dem Key `<category>_<trainNumber>.json` und dem Schema aus
  `public/api/lib/Punctuality.php`

Solange kein solcher Import läuft, greift für DE-Züge die Baseline aus dem
DB-Konzernbericht plus die eigenen Live-Messungen — die App zeigt korrekt an,
welche Quelle die Zahl gerade trägt.

### Münchner Nahverkehr über die MVG-API

Für München gibt es **zwei Lücken**, die die MVG-Web-API schließt:

1. **Reine U-Bahn-Halte** (Odeonsplatz, Sendlinger Tor …) haben keine
   EVA-Nummer und tauchen in der HAFAS-Suche oft gar nicht auf. Die Ortssuche
   fragt deshalb zusätzlich `https://www.mvg.de/api/bgw-pt/v3/locations`
   und mischt Treffer mit MVG-Präfix `mvg:` in die Liste. HAFAS-Treffer
   mit identischem Namen absorbieren die MVG-Ergänzung; nur reine
   MVG-Halte bleiben eigenständig. Sie erhalten das Flag `noJourneys=true`,
   weil sich mit der MVG-`globalId` keine Verbindung anrouten lässt
   (HAFAS akzeptiert nur EVA-Nummern).

2. **Aktuelle Störungsmeldungen** (`?action=disruptions`) — Baustellen,
   Ausfälle, Umleitungen für U-Bahn, Tram, Bus, S-Bahn. Das Frontend blendet
   sie als kollabierbaren Ticker unter den Suchergebnissen ein, wenn welche
   vorliegen. Zwei Minuten Cache serverseitig, alle 120 Sekunden erneutes
   Nachladen im Browser.

Die MVG-API läuft ohne Auth und ist ausdrücklich für die MVG-Web-App gedacht;
wir identifizieren uns per `User-Agent` (konfigurierbar in `config.php`).
Ausschalten geht per `providers.mvg.enabled = false` — dann verschwindet der
Ticker und die Ortssuche fällt auf HAFAS-only zurück.

**Was die MVG-API nicht kann:** eine Verbindungssuche. Es gibt keinen
`/trips`- oder `/journeys`-Endpunkt (getestet). Für die Fahrplanauskunft
zwischen zwei MVG-Halten muss weiterhin HAFAS herhalten — und HAFAS versteht
die MVG-IDs nicht. In der Praxis ist das selten ein Problem, weil HAFAS
München S-Bahn/Fernverkehr ohnehin sauber abbildet.

### Datum und Uhrzeit auf dem Telefon

iOS und Android zeichnen `input[type="date"]` und `input[type="time"]` als
**Systemsteuerelement** und geben ihm die Breite seines *Inhalts*.
`width: 100%`, `max-width` und `min-width: 0` prallen daran ab: das Element ist
schlicht nicht bereit, schmaler zu werden als der Text darin, und schiebt sich
über das Nachbarfeld und über den Rand des Panels.

Es hilft nur `appearance: none`. Was dabei verloren geht, wird einzeln
zurückgeholt — genau das war der Einwand beim ersten Versuch:

| verloren | zurückgeholt mit |
|---|---|
| Wert sass oben links statt mittig | `display: flex` + `align-items: center` |
| Kalender- bzw. Uhrsymbol fehlte | eingebettetes SVG als `background-image` |
| Feld wirkte leer | `::-webkit-date-and-time-value { text-align: left }` |

Ein Detail, das eine Weile gekostet hat: `background-position: right 0.6rem
center` sind **drei** Werte, und die Dreiwert-Schreibweise ist ungültig — der
Browser wirft die Zeile ersatzlos weg und setzt das Symbol nach oben links.
Gültig sind ein, zwei oder vier Werte, hier also `right 0.6rem top 50%`.

Nachgemessen bei 375 px und bei 320 px Fensterbreite, jeweils auch mit auf
22 px hochgesetzter Grundschrift: kein Überlauf, die Felder schrumpfen mit.
Unter 360 px bekommt das Datum eine eigene Zeile — 360 und nicht 380, weil die
verbreiteten Telefongrössen bei 375 und 390 px liegen und dort beide Felder
bequem nebeneinander passen.

### Teilen

Der Knopf **„Suche teilen"** legt die komplette Suche in der Adresszeile ab —
Orte, Datum, Zeit, Abos, Verkehrsmittel, Modus. Auf dem Telefon öffnet sich das
native Teilen-Menü, sonst landet der Link in der Zwischenablage. Wer ihn öffnet,
bekommt die Suche automatisch ausgeführt.

### Verkehrsmittel filtern

Unter **Verkehrsmittel** lassen sich Gruppen abwählen — am häufigsten wohl Bus
und Schienenersatzverkehr. Der Filter greift schon bei der Suche, nicht erst in
der Anzeige, und gilt für Fahrplan und Preisabfrage gleichermaßen.

Die zugrunde liegenden HAFAS-Produktklassen sind nicht geraten, sondern
nachgemessen (`api/lib/Products.php`):

| Bit | Wert | Gattungen |
|---|---|---|
| 0 | 1 | ICE, RJ, RJX |
| 1 | 2 | Schienenersatzverkehr |
| 2 | 4 | EC, IC, IR |
| 3 | 8 | NJ, EN, FLX |
| 4 | 16 | RE, RB, R, REX |
| 5 | 32 | S-Bahn |
| 6 | 64 | Bus |
| 8 | 256 | U-Bahn |
| 9 | 512 | Tram |
| 12 | 4096 | WESTbahn |

### Über eine bestimmte Stadt

Im Nerd-Modus lässt sich ein Zwischenhalt erzwingen — etwa um Zürich–Wien über
München statt über den Arlberg zu führen. Verifiziert: ohne Vorgabe liefert die
Suche die Arlberg-Route, mit Vorgabe „München" ausschließlich Verbindungen über
München.

### Ortssuche

Zwei Quellen werden zusammengeführt, weil keine allein reicht: Die ÖBB-Suche ist
auf Österreich geeicht — „Marienplatz" liefert dort Graz, Viehofen und
Hafnerbach, aber kein München. Die DB-Suche kennt den deutschen Nahverkehr bis
zur einzelnen U-Bahn-Station, ist dafür bei kleinen Halten in AT und CH dünner.

Sortiert wird in drei Stufen:

1. **Namensrelevanz** — exakt, als ganzes Wort, enthalten. Das dominiert alles
   andere. Ohne diese Stufe gewinnt „Schendlingen (Bregenz)" gegen „Sendlinger
   Tor, München", weil HAFAS unscharf sucht und Schendlingen ein
   Fernverkehrshalt ist. Treffer ohne jeden Namensbezug fliegen raus.
2. **Bedeutung des Ortes** — gewichtet nach Verkehrsangebot. U-Bahn und S-Bahn
   zählen hoch, weil es sie nur in Großstädten gibt; das ist der beste
   verfügbare Ersatz für „Größe der Stadt", die keine der APIs mitliefert.
3. **Rang der Quelle**, bei Gleichstand mit Vorrang für die DB.

### Wenn die ÖBB die Station nicht kennt

Die Fahrplansuche läuft normalerweise über die ÖBB. Die kennt deutsche
EVA-Nummern problemlos (geprüft mit `8004135`, München Marienplatz), aber
**keine lokalen Kennungen**. Nahverkehrshalte wie „Sendlinger Tor, München"
tragen Nummern wie `625176` — dort antwortet HAFAS mit
`location missing or invalid`.

Deshalb gibt es einen Fallback: Findet die ÖBB nichts, übernimmt die DB auch den
Fahrplan. Geprüft für Sendlinger Tor → München Hbf: ÖBB null Treffer, DB fünf
Verbindungen (U7, U2). Alle Halte tragen Koordinaten, die Karte kann sie also
zeichnen — nur der genaue Streckenverlauf fehlt, weil die DB keine Polylines
liefert. Das steht dann als Hinweis über den Ergebnissen.

Damit lassen sich auch reine U-Bahn- und Tram-Halte als Start oder Ziel
verwenden.

### Ticketshops

An jeder Verbindung stehen die Shops der berührten Länder, das Startland zuerst:
Zürich–München ergibt SBB und DB, Wien–München ÖBB und DB.

**Der ÖBB-Link** kommt fertig aus der Fahrplanantwort und ist zuverlässig
vorbelegt.

**Der DB-Link** war mit bloßen Ortsnamen kaputt — die Buchungsstrecke meldete
„Keine Verbindungen gefunden" und ignorierte das Datum. Er enthält jetzt die
vollständigen Location-IDs samt Koordinaten (`soid`, `zoid`, `soei`, `zoei`).
Abschließend verifizieren ließ sich das nicht: bahn.de sperrte den Testbrowser
nach wenigen Aufrufen mit Fehler 751 aus. Deshalb ist er wie der SBB-Link mit
`*` markiert — bitte einmal gegenprüfen.

**Der SBB-Link** führt auf die Fahrplansuche. Der alte Deeplink
`fahrplan.xhtml` liefert durchgehend HTTP 400, und ob die Nachfolgeseite die
Parameter übernimmt, lässt sich serverseitig nicht feststellen.

### Abos und Preisschätzung

Die Reise wird in Länderanteile zerlegt — über die **Zwischenhalte**, die jeweils
einen Ländercode tragen. Eine Fahrt Wien–München ergibt so korrekt ~317 km AT plus
~145 km DE statt einer pauschalen Halbierung. Darauf werden die Abo-Regeln
angewendet:

| Abo | Wirkung | Besonderheit |
|---|---|---|
| Halbtax | 50 % auf den CH-Anteil | |
| GA | CH-Anteil frei | |
| GA Night | CH-Anteil frei | nur 19–5 Uhr, 2. Klasse |
| BahnCard 25 / 50 / 100 | 25 % / 50 % / ganz auf den DE-Anteil | Echtpreis von der DB |
| Deutschlandticket | DE-Anteil frei | **nur Nahverkehr**, nicht ICE/IC/EC |
| VORTEILScard | 45 % auf den AT-Anteil | |
| KlimaTicket | AT-Anteil frei | |

*seven25* ist kein eigener Eintrag — es ist dasselbe Produkt wie GA Night, nur
der Tarif für unter 25-Jährige.

**Zeitfenster gelten je Teilstück, nicht je Verbindung.** Maßgeblich ist die
Uhrzeit, zu der der Zug das jeweilige Teilstück tatsächlich befährt — dafür
werden die Zeiten der Zwischenhalte ausgewertet (fehlen sie, werden sie über die
Distanz interpoliert). Der ECE München–Zürich ab 17:03 ist damit korrekt vom GA
Night gedeckt, weil er den Schweizer Abschnitt erst nach 19:00 erreicht. Liegt
ein Teilstück auf der Fenstergrenze, wirkt der Rabatt anteilig: 18:45–19:15
ergibt den halben Nachlass.

Zwei Sonderfälle sind hinterlegt: Die BahnCard 50 gibt auf Sparpreise nur 25 %.
Und beim Deutschlandticket verlässt sich das Tool nicht auf die Gattung allein —
die DB markiert in ihrer Antwort selbst, auf welchen Teilstrecken es gilt
(inklusive Hinweisen wie „Singen(Hohentwiel) – Stuttgart Hbf"). Diese Angabe hat
Vorrang vor der eigenen Heuristik.

**Doppelrabatte sind ausgeschlossen:** Enthält der Echtpreis bereits eine
BahnCard, geht sie nicht noch einmal in die Hochrechnung ein — nur die Abos, die
die DB nicht kennt.

**Genauigkeit der Schätzung:** Die Gesamtdistanz stimmt gut (gemessen: 473 km
berechnet gegen ~462 km reale Bahnkilometer für Wien–München; 828 gegen ~860 km
für Zürich–Wien). Ungenau wird es bei Abschnitten, die eine Grenze **ohne
Zwischenhalt** queren — die werden hälftig geteilt, obwohl die Grenze selten in der
Mitte liegt. Bei Fahrten aus der Schweiz fällt das kaum ins Gewicht, weil Basel,
Buchs SG und Chiasso fast immer Halte sind.

Geschätzte Preise sind **nie verbindlich**. Maßgeblich ist der Ticketshop, und der
Buchungslink hängt an jeder Verbindung.

---

## Aufbau

```
public/
├── index.html                    Oberfläche
├── check.php                     Selbsttest für den Webspace
├── assets/
│   ├── css/style.css             Alle Farben als CSS-Variablen
│   └── js/
│       ├── app.js                Zustand, Formular, Moduswechsel, Auswahl
│       ├── api.js                Aufrufe ans eigene Backend
│       ├── scoring.js            Bewertungsmodelle
│       ├── render.js             Ergebnisdarstellung
│       ├── map.js                SVG-Routenkarte inkl. Label-Platzierung
│       └── data/trains.js        Gattungen, Fahrzeugmodelle, Komfortwerte
└── api/
    ├── index.php                 Router, führt Fahrplan und Preise zusammen
    ├── config.php                Einzige Datei, die du anfassen musst
    └── lib/
        ├── Http.php              cURL-Wrapper inkl. Browser-TLS-Profil
        ├── Cache.php             Dateicache, degradiert still
        ├── Fares.php             Abo- und Preislogik
        ├── Products.php          Verkehrsmittel-Gruppen und Bitmasken
        ├── Locations.php         Ortssuche aus beiden Quellen
        ├── Punctuality.php       Selbst gesammelte Pünktlichkeitsstatistik
        ├── Shops.php             Buchungs-Deeplinks je Land
        └── Providers/
            ├── OebbHafas.php     Fahrplan, Zuggattungen, Ländercodes, Geometrie
            ├── DbVendo.php       Echtpreise, Auslastung, Bestpreis
            └── CoachSequence.php Baureihe über bahn.expert
```

### API-Endpunkte

Alles per GET auf `api/`:

| Aufruf | Zweck |
|---|---|
| `?action=health` | Welche Quellen sind erreichbar? |
| `?action=catalogue` | Abo-Liste fürs Frontend |
| `?action=locations&q=Bern` | Stationssuche |
| `?action=journeys&from=…&to=…&date=…&time=…` | Verbindungen inklusive Preis (`&scroll=…` blättert weiter) |
| `?action=livetrains&bbox=süd,west,nord,ost` | Züge, die dort gerade fahren |
| `?action=traindetails&jid=…` | Zuglauf mit Halten und Verspätung |
| `?action=bestprices&from=…&to=…&date=…` | Günstigste Zeitfenster am Tag |
| `?action=nextconnection&from=…&to=…&date=…&time=…` | Nächster Anschluss nach einem knappen Umstieg |
| `?action=fxrate` | EZB-Tageskurse, für den Gegenwert in Franken |
| `?action=platforms&lat=…&lon=…&from=…&to=…` | Bahnsteige und Umsteigeweg aus OpenStreetMap |
| `?action=works` | Bauarbeiten im Netz, mit Abschnitt und Zeitraum |
| `?action=disruptions` | Aktive Störungsmeldungen der MVG München |

`journeys` versteht zusätzlich `discounts` (kommagetrennt), `products`
(kommagetrennt, leer = alle), `class` (1/2), `results`, `arrival=1`, `via`
(EVA-Nummern, kommagetrennt) und `minchange` (Mindestumsteigezeit in Minuten,
1–60).

---

## Anpassen

**Preisrichtwerte** (falls die Schätzungen systematisch danebenliegen):
`api/lib/Fares.php`, Konstanten `RATE_PER_KM`, `BASE_FEE`, `SAVER_FACTOR`.

**Weiteres Abo hinzufügen:** in `Fares.php` einen Eintrag in `DISCOUNTS` ergänzen
(Land, Faktor, Label). Das Frontend zieht die Liste automatisch über
`?action=catalogue` — im UI musst du nichts anfassen. Soll das Abo auch an die DB
durchgereicht werden, zusätzlich in `DbVendo::DISCOUNT_MAP` eintragen.

**Zugkomfort:** `assets/js/data/trains.js` — `TRAIN_TYPES` für die Gattungen,
`TRAIN_MODELS` für die Fahrzeuge. Ein neues Modell braucht `label`, die
`categories`, unter denen es fährt, und — falls die Wagenreihung es melden soll —
die `series` (Baureihennummern). `sole: true` bedeutet: diese Gattung fährt
praktisch nur dieses Fahrzeug, die Zuordnung ist dann auch ohne Wagenreihung
eindeutig.

**Verkehrsmittel-Gruppen:** `api/lib/Products.php`. Dort stehen Bitmaske und
DB-Gattungsnamen nebeneinander; das Frontend zieht die Liste automatisch.

**Cache-Zeiten:** `api/config.php` → `cache_ttl`. Standard: Orte 1 Tag,
Verbindungen 5 Minuten.

**Rate-Limit:** ebenfalls in `config.php`. Standard 60 Anfragen pro Minute und IP —
schützt dich davor, dass dein Webspace bei den Betreibern als Scraper auffällt.

### DB-Enum-Werte verifizieren

Sollte die DB die Bezeichner für Ermäßigungen ändern, brechen die Echtpreise mit
Abo. So kommst du an die aktuellen Werte: auf `bahn.de` eine Suche mit deinem Abo
starten, in den Entwicklertools den Netzwerk-Tab öffnen, den POST auf
`angebote/fahrplan` suchen und im Request-Body unter `reisende[].ermaessigungen`
nachsehen. Diese Werte in `DbVendo::DISCOUNT_MAP` eintragen.

---

## Rechtliches und Fairness

Das Tool nutzt dieselben Schnittstellen, die auch die Websites und Apps der
Betreiber verwenden. Es sind keine offiziell dokumentierten öffentlichen APIs.
Für den privaten Gebrauch ist das üblich und verbreitet — aber:

- Die Schnittstellen können sich **jederzeit ohne Ankündigung ändern**. Wenn etwas
  nicht mehr geht, ist meistens das die Ursache.
- Cache und Rate-Limit sind absichtlich konservativ eingestellt. Dreh sie nicht
  hoch, es sei denn, du weißt was du tust.
- Für kommerziellen Einsatz brauchst du echte Verträge mit den Betreibern.
- Gebucht wird immer im offiziellen Shop. Das Tool verkauft nichts und
  speichert keine personenbezogenen Daten — Einstellungen liegen ausschließlich
  im localStorage deines Browsers.

---

## Bekannte Grenzen

- **Schweizer und österreichische Abos sind immer geschätzt.** Die DB kennt nur
  BahnCards. Für Halbtax, GA oder KlimaTicket ist der verbindliche Preis der im
  SBB- bzw. ÖBB-Shop.
- **Keine Echtpreise auf reinen CH/AT-Relationen** wie Zürich–Wien, weil die DB
  sie nicht vertreibt.
- **Baureihen** nur über die Wagenreihung (deutscher Fernverkehr, Reisetag) oder
  eigene Regeln.
- **Nachtzüge** sind im Preisvergleich benachteiligt, weil die gesparte
  Hotelnacht nicht eingerechnet wird.
- **Grenzabschnitte ohne Zwischenhalt** werden hälftig aufgeteilt. Bei Fahrten
  aus der Schweiz fällt das kaum ins Gewicht, weil Basel, Buchs SG und Chiasso
  fast immer Halte sind.
- **Reservierungen und Zuschläge** sind in den Schätzungen nicht enthalten.
- **Der TLS-Trick kann jederzeit brechen.** Ändert Akamai die Erkennung, kommt
  wieder `OPS_BLOCKED` und das Tool fällt auf Schätzpreise zurück.
