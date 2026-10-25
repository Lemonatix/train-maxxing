# train-maxxing als Android-App

Dieselbe Oberfläche wie die Website (`../public/`), als echte Android-App mit
eigenem Symbol, Startbild und Vollbild ohne Browserleiste. HTML, CSS und JS
stecken in der App; nur die Daten kommen von der Website
(`https://mika-riesterer.de/train-maxxing/public/api/`). Gebaut mit
[Capacitor](https://capacitorjs.com/) 8.

Fürs iPhone gibt es keine App - dort bleibt die Web-App über „Teilen → Zum
Home-Bildschirm". Apple erlaubt Installationen nur über den App Store.

## Was anders ist als im Browser

- **Daten und Links von der Website.** Die App lädt ihre Dateien unter
  `https://localhost`. `public/assets/js/site.js` erkennt das (an
  `window.TRAIN_MAXXING_APP`, gesetzt von `scripts/prepare-www.mjs`) und schickt
  API-Aufrufe, geteilte Fahrten und Sync-Links an die Website. Im Browser
  ändert sich nichts.
- **Das Backend muss die App zulassen:** `cors_origins` in
  `public/api/config.php` enthält dafür `https://localhost`. Ohne das
  blockt die WebView jede Antwort - die App zeigt dann „Das Backend ist nicht
  erreichbar".
- **Kein Service Worker.** Die Dateien liegen ohnehin im Gerät.
- **Keine Push-Benachrichtigungen.** Web Push gibt es in einer App-WebView
  nicht; dafür bräuchte es Firebase Cloud Messaging und Änderungen an
  `PushWatch`. Die Live-Verfolgung selbst funktioniert, nur ohne Meldung bei
  gesperrtem Bildschirm.
- **Kein Link „Zurück zur Hauptseite"**, und die Seite reicht bis unter die
  Statusleiste (deren Symbole folgen dem hellen bzw. dunklen Design).

## Bauen

Einmalig:

```bash
sudo pacman -S jdk21-openjdk     # Gradle 8.14 läuft mit Java 17-24, nicht mit 27
cd app && npm install
```

Ein Test-APK (signiert mit dem Entwicklerschlüssel):

```bash
cd app
JAVA_HOME=/usr/lib/jvm/java-21-openjdk npm run apk
```

Liegt danach unter `android/app/build/outputs/apk/debug/app-debug.apk`.
`npm run apk` erzeugt vorher `www/` neu aus `public/` - eine Änderung an der
Website ist damit auch in der App, sobald neu gebaut wird.

Andere Website-Adresse: in `package.json` unter `trainMaxxing.site`, oder
einmalig `TRAIN_MAXXING_SITE=https://…/ npm run apk`.

Symbol und Startbild kommen aus zwei Vorlagen: `resources/icon-foreground.svg`
(die Zugfront auf Schienen im Farbverlauf der Oberfläche) und
`resources/icon-background.svg` (der dunkle Grund mit leichtem Schein). Getrennt,
weil Android daraus ein adaptives Symbol macht und es je nach Hersteller rund,
eckig oder als Tropfen zuschneidet. Nach einer Änderung: `scripts/make-icons.sh`
(braucht `rsvg-convert` und ImageMagick). Das fertige Symbol liegt danach auch
als `resources/icon-512.png` bereit, z. B. für den Download-Link auf der Website.

## Veröffentlichen (auf der eigenen Website)

Das Test-APK lässt sich installieren, aber jedes Update muss mit **demselben
Schlüssel** signiert sein - sonst verweigert Android es, und man muss die
App erst löschen (samt gemerkter Reisen). Deshalb einmal einen eigenen
Schlüssel anlegen und gut aufheben (Passwortmanager, Backup):

```bash
keytool -genkeypair -v -keystore ~/train-maxxing-release.jks -alias train-maxxing \
  -keyalg RSA -keysize 4096 -validity 10000
```

Dann `android/keystore.properties` anlegen (steht in `.gitignore`):

```properties
storeFile=/home/mmr/train-maxxing-release.jks
storePassword=…
keyAlias=train-maxxing
keyPassword=…
```

Und bauen:

```bash
JAVA_HOME=/usr/lib/jvm/java-21-openjdk npm run apk:release
```

Ergebnis: `android/app/build/outputs/apk/release/app-release.apk`. Diese
Datei kommt auf die Website, z. B. als `train-maxxing.apk`. Damit der Browser sie
als App erkennt und nicht als Text anzeigt, in der `.htaccess` daneben:

```apache
AddType application/vnd.android.package-archive .apk
```

Auf dem Telefon: Datei öffnen, beim ersten Mal „Installation aus dieser
Quelle zulassen" für den Browser erlauben.

**Ein Update:** in `android/app/build.gradle` `versionCode` um eins erhöhen
(und `versionName` anpassen), neu bauen, Datei auf der Website ersetzen.
Wer die alte Fassung hat, installiert die neue einfach darüber; gemerkte
Reisen und Einstellungen bleiben.

## Ordner

```
app/
├── package.json            Abhängigkeiten, Bau-Befehle, Website-Adresse
├── capacitor.config.json   App-Kennung (de.mikariesterer.trainmaxxing), Name
├── scripts/
│   ├── prepare-www.mjs     public/ -> www/, ohne PHP, mit App-Kennung
│   └── make-icons.sh       Symbol und Startbild aus den beiden SVG-Vorlagen
├── resources/              icon-foreground.svg, icon-background.svg, icon-512.png
├── android/                Android-Projekt (Android Studio: npm run open)
└── www/                    erzeugt, nicht im Repository
```
