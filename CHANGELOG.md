# Änderungen

## 1.3 (November 2026)

Gilt für Website und App. Was der Server rechnet (Zugsuche, Baustellen,
Störungen, Preise), bekommt auch eine ältere App, sobald die Website
aktualisiert ist. Die Oberfläche (Farben, Karte, Zugsuche) gibt es in der App
erst mit dem Update auf 1.3.

### Neu

- **Zugsuche auf der Live-Karte.** Einen Zug nach Name oder Nummer finden
  („RJ 80“, „ICE 690“, „RB 65“), auch für einen anderen Tag. Jeder Treffer
  lässt sich öffnen und verfolgen, auch ein Zug, mit dem man nicht selbst
  fährt (zum Beispiel beim Abholen).
  - Nur noch genaue Treffer: „Bus 181“ liefert nicht mehr die Busse
    1810–1819 aus Polen.
  - Was gerade fährt, steht oben und ist mit „fährt gerade“ markiert.
    Bereits gefahrene Züge sind blass.
  - Ein Tipp auf einen fahrenden Zug zeigt ihn auf der Karte als großen,
    beschrifteten Punkt.
  - Schon beim Tippen erscheinen Vorschläge aus den Zügen, die gerade im
    Kartenausschnitt fahren.
- **Streckenkilometer** an jeder Verbindung.
- **Baustellen mit echtem Verlauf.** Der betroffene Abschnitt folgt jetzt den
  Gleisen (aus den Karten von strecken-info.de) statt einer geraden Linie.
  Ohne bekannten Verlauf markieren Ringe die beiden Enden.
- **Störungen in München nach Verkehrsmittel gruppiert:** zuerst die Bahn,
  dann U-Bahn, Tram und Bus. Dazu Filter, alle Meldungen statt nur sechs und
  ausklappbare Texte.
- **Züge in Großbritannien** heißen auf der Live-Karte nach ihrem Betreiber
  („Elizabeth line“, „Southern“).
- **Umstiegswege in München-Pasing und Zürich HB.** Wie am München Hbf
  zeigt der Lageplan dort den Weg vom Ankunfts- zum Abfahrtsgleis mit Metern
  und Minuten: in Pasing durch die Unterführung, in Zürich an den
  Bahnsteigköpfen der Halle (Gleise 3–18, die unterirdischen Gleise folgen
  später).
- **Gehtempo beim Umsteigen** in den Einstellungen: gemütlich (Gepäck,
  Kinder, Kinderwagen), normal oder zügig. Die Minuten der gezeichneten
  Umstiegswege richten sich danach, und der Plan sagt, mit welchem Tempo
  gerechnet ist.
- **Umstiegswege zu U-Bahn und S-Bahn am München Hbf** (in Vorbereitung).
  Die Wege zur S-Bahn tief und zu den U-Bahnen sind gezeichnet. Die U-Bahn
  findet ihren Bahnsteig über Linie und Richtung, weil der Fahrplan für sie
  kein Gleis nennt. Sichtbar werden die Wege, sobald sie geprüft sind.

### Aussehen

- **Farben je Verkehrsmittel** (U-Bahn, S-Bahn, Tram, Bus, Fern- und
  Regionalverkehr) in den Fahrtdetails, dazu ein Fahrtbalken über der
  ganzen Reise.
- **Live-Karte in Pünktlichkeitsfarben:** grün pünktlich, gelb ab 3 Minuten,
  rot ab 10 Minuten, grau ohne Echtzeitdaten. Die Verspätung steht im
  Tooltip. Beim Drüberfahren bleibt die Farbe erhalten.
- **Der verfolgte Zug ist ein großer weißer Punkt mit blauem Ring** und
  seinem Namen daneben. Auf seiner eigenen blauen Strecke war er vorher kaum
  zu sehen. Rot heißt jetzt „mehr als zehn Minuten zu spät“.
- Symbole an den Reitern und Platzhalter, solange Ergebnisse laden.
- **Fahrtdetails auf dem Handy:** Der Knopf „Fahrtinfo“ bleibt oben rechts.
  Zeit und Ort stehen in einer Zeile, lange Ortsnamen brechen in ihrer
  eigenen Spalte um.
- Weiches Aufklappen jetzt auch in Firefox und älterem Safari.

### Verbessert

- **Keine doppelten Verbindungen mehr.** Fahren zwei Verbindungen mit
  denselben Zügen und unterscheiden sich nur im Fußweg, bleibt die
  schnellere.
- **Echtzeiten rund um Fußwege:** Verspätet sich der Zug, verschieben sich
  der Weg zur Haltestelle und von ihr weg mit.
- **Verspätung auf der Live-Karte:** Es zählt die Verspätung am nächsten
  Halt, nicht mehr die größte des ganzen Laufs. Ein Nacht-ICE, der Minuten
  aufgeholt hat, steht nicht mehr dauerhaft rot da.
- Die Baustellen stehen wieder unter der Suche.

### Behoben

- **Zuglauf von U-Bahn, Tram und Bus:** Er begann an der eigenen
  Einstiegshaltestelle. Die U6 ab Freimann „begann in Freimann“. Jetzt kommt
  der ganze Lauf von der MVV, mit Prognose an jedem Halt, und in der
  richtigen Richtung.
- **Tipp auf den verfolgten Punkt:** Bei U-Bahn, Bus und manchen DB-Zügen
  passierte nichts. Jetzt öffnet sich der Zuglauf.

- **Deutschlandticket bei MVG-Bussen:** Eine Fahrt, die vollständig im
  Deutschlandticket liegt, bekam trotzdem einen Preis („ca. 2,35 €“).
- **Umstieg an derselben Haltestelle:** Statt „Garching → Garching, Umstieg
  2 min“ stehen jetzt die Zeit zwischen den Zügen und der Fußweg da.
- **Standort auf dem iPhone:** Wird er verweigert, erklärt ein Hinweis, wie
  man die App auf dem Home-Bildschirm zurücksetzt.
- Über der Live-Karte stand ein schmaler, leerer Balken.
