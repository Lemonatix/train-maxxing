# train-maxxing

Vergleicht Zugverbindungen durch **Schweiz, Deutschland und Österreich** — nicht nur
nach Preis und Dauer, sondern auch danach, in welchem Zug du sitzt. Mit Abo-Auswahl
(Halbtax, GA, BahnCard, Vorteilscard, KlimaTicket) und zwei Modi:

- **Normal** — Preis und Zeit, eine sortierte Liste, fertig.
- **Nerd** — Zuggattung, Zugnummer, Streckenverlauf, Fahrzeugmodell,
  Routenzwang über eine bestimmte Stadt und ein Zeitwert-Modell, das
  ausrechnet, ob sich die halbe Stunde mehr Fahrt für den bequemeren Zug lohnt.

Dazu eine Routenkarte, ein Verkehrsmittel-Filter (Bus und
Schienenersatzverkehr lassen sich vorab ausschließen) und Buchungslinks zu SBB,
DB oder ÖBB, je nachdem welche Länder die Reise berührt.

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

**Gestaltung:** dunkles Glasmorphismus-Grundgerüst wie auf mika-riesterer.de
(Panels mit `backdrop-filter`, dünne helle Ränder, Sky/Purple/Emerald als
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

Die Seite ist bewusst **dark-only**, passend zu deiner Website. Für einen
hellen Modus reicht es, in `:root` die Flächen- und Textvariablen zu tauschen —
alle Komponenten leiten ihre Farben davon ab.

**Zurück-Link anpassen:** Oben links führt ein Knopf zurück zur Hauptseite. Das
Ziel steht in `index.html` und zeigt standardmäßig auf `/`:

```html
<a class="back" href="/" aria-label="Zurück zur Hauptseite">
```

Liegt das Tool in einem Unterordner einer größeren Seite, trag dort die
gewünschte Adresse ein.

**Als Teilseite einbetten:** Übernimm den Inhalt von `<div class="wrap">` in deine
Seite und binde `style.css` sowie `<script type="module" src="assets/js/app.js">`
ein. Wichtig: Das Skript braucht `type="module"`.

---

## Wie die Bewertung funktioniert

### Normal-Modus

Preis, Dauer und Umstiege werden je auf 0–1 normiert und gewichtet
(40 % / 40 % / 20 %). Kleinste Punktzahl gewinnt.

### Nerd-Modus

Ein Zeitwert-Modell rechnet alles in „effektive Kosten" um:

```
effektiv = Preis + Stunden × Zeitwert + Umstiege × Umstiegsaufwand − Komfortbonus
```

Der Komfortbonus wächst mit der Fahrzeit — ein angenehmer Zug ist auf fünf Stunden
mehr wert als auf einer. Genau damit lässt sich die Frage beantworten, ob der
durchgehende Railjet die halbe Stunde Mehrfahrzeit wert ist.

Angezeigt wird nicht der Absolutwert (der bei hoher Komfortgewichtung negativ
werden kann), sondern der **Abstand zur besten Option**: „+43 gegenüber Platz 1".

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

### Wagenreihung aktivieren

Die DB hat eine Schnittstelle, die die Wagenreihung und damit die Baureihe kennt.
Sie ist standardmäßig **aus**, weil sie einen Request je Zug kostet und weil die
Parameterkombination hier nicht abschließend verifiziert werden konnte: Der
Endpunkt antwortet auf unsere Anfragen mit `HTTP 422` — also erreichbar, aber
Parameter nicht akzeptiert.

Einschalten in `api/config.php`:

```php
'wagenreihung' => ['enabled' => true, /* ... */],
```

Falls dann keine Baureihen erscheinen, hol dir die echten Parameter aus dem
Browser: auf `bahn.de` eine Verbindung für **heute** öffnen, die Wagenreihung
eines ICE aufklappen, in den Entwicklertools den Netzwerk-Tab filtern nach
`vehicle-sequence` und die Query-Parameter mit denen in
`lib/Providers/DbWagenreihung.php` vergleichen. Der Parser sucht die Baureihe
unter mehreren bekannten Feldnamen (`constructionType`, `baureihe`,
`vehicleSeries`, `series`) und liefert lieber nichts als etwas Falsches.

Grundsätzlich gilt: nur deutsche Fernzüge, nur am Reisetag. Für eine Fahrt in
drei Wochen gibt es keine Wagenreihung — dafür sind die eigenen Regeln da.

### Karte

Unter den Hinweisen liegt eine Karte mit allen gefundenen Routen; die ausgewählte
ist hervorgehoben. Klick auf eine Linie wählt die Verbindung aus, Klick auf eine
Verbindung hebt die Linie hervor — beides ist synchron.

Die Karte ist **reines SVG**, gezeichnet aus den Polylines der Fahrplandaten in
Web-Mercator. Keine Kartenkacheln, keine externen Requests, kein Tracking —
damit funktioniert sie auch hinter strengen Content-Security-Policies. Pro
Abschnitt werden höchstens 60 Stützpunkte übertragen, das reicht für eine
Übersicht und hält die Antwort klein.

**Beschriftungen überlappen nicht.** Für jeden Halt werden acht Positionen rund
um den Punkt durchprobiert; passt keine kollisionsfrei ins Bild, bleibt der Name
weg — ein fehlender Name ist besser als zwei übereinandergedruckte. Start und
Ziel haben dabei Vorrang.

Auf schmalen Bildschirmen wechselt die Karte ins Hochformat, weil dort vertikal
mehr Platz ist. Beim Drehen des Telefons wird sie neu gezeichnet.

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

### Ticketshops

An jeder Verbindung stehen die Shops der berührten Länder, das Startland zuerst:
Zürich–München ergibt SBB und DB, Wien–München ÖBB und DB.

Die ÖBB- und DB-Links sind mit Orten, Datum und Zeit vorbelegt. Der SBB-Link ist
mit `*` markiert: Der alte Deeplink `fahrplan.xhtml` liefert inzwischen
durchgehend HTTP 400, und ob die Nachfolgeseite die Parameter übernimmt, lässt
sich serverseitig nicht prüfen, weil die Suche clientseitig aufgebaut wird. Im
Zweifel landest du auf der Fahrplansuche und trägst die Orte selbst ein.

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
        ├── Shops.php             Buchungs-Deeplinks je Land
        └── Providers/
            ├── OebbHafas.php     Fahrplan, Zuggattungen, Ländercodes, Geometrie
            ├── DbVendo.php       Echtpreise
            └── DbWagenreihung.php Baureihe (optional, experimentell)
```

### API-Endpunkte

Alles per GET auf `api/`:

| Aufruf | Zweck |
|---|---|
| `?action=health` | Welche Quellen sind erreichbar? |
| `?action=catalogue` | Abo-Liste fürs Frontend |
| `?action=locations&q=Bern` | Stationssuche |
| `?action=journeys&from=…&to=…&date=…&time=…` | Verbindungen inklusive Preis |

`journeys` versteht zusätzlich `discounts` (kommagetrennt), `products`
(kommagetrennt, leer = alle), `class` (1/2), `results`, `arrival=1` und `via`
(EVA-Nummern, kommagetrennt).

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
