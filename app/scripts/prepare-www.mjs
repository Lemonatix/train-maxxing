/**
 * Die Oberfläche für die App zusammenstellen: public/ -> app/www/.
 *
 *   node scripts/prepare-www.mjs        (oder: npm run www)
 *
 * WAS MITKOMMT: alles, was der Browser lädt - index.html, assets/, das
 * Manifest. WAS NICHT: der PHP-Teil (api/, check.php) und die
 * Apache-Dateien. Die App holt ihre Daten von der Website; die Adresse
 * steht in package.json unter "trainMaxxing.site" (oder in TRAIN_MAXXING_SITE).
 *
 * UND EINE ZEILE DAZU: index.html bekommt vor allen Modulen
 * `window.TRAIN_MAXXING_APP = { site }`. Daran erkennt public/assets/js/site.js,
 * dass es in der App läuft - API und geteilte Links zeigen dann auf die
 * Website statt auf "localhost". Die Seite im Browser bleibt unverändert.
 *
 * Kein Kopieren von Hand und kein zweiter Stand: www/ wird bei jedem Bau
 * neu erzeugt und steht in .gitignore.
 */
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const hier = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(hier, '..');
const quelle = path.resolve(appDir, '..', 'public');
const ziel = path.join(appDir, 'www');

const pkg = JSON.parse(await readFile(path.join(appDir, 'package.json'), 'utf8'));
const site = String(process.env.TRAIN_MAXXING_SITE || pkg.trainMaxxing?.site || '');
if (!/^https:\/\/[^/]+\//.test(site.endsWith('/') ? site : site + '/')) {
  console.error(`Keine gültige Website-Adresse: "${site}". In package.json unter "trainMaxxing.site" eintragen.`);
  process.exit(1);
}
const siteUrl = site.endsWith('/') ? site : site + '/';

// Was nicht in die App gehört - relativ zu public/.
const OHNE = [
  /^api(\/|$)/,          // PHP-Backend, Schlüssel, Cache
  /^check\.php$/,        // Prüfseite des Servers
  /(^|\/)\.htaccess$/,   // Apache
  /^sw\.js$/,            // Service Worker: in der App unnötig, siehe site.js
];

await rm(ziel, { recursive: true, force: true });
await mkdir(ziel, { recursive: true });
await cp(quelle, ziel, {
  recursive: true,
  filter: (src) => {
    const rel = path.relative(quelle, src).split(path.sep).join('/');
    return rel === '' || !OHNE.some((m) => m.test(rel));
  },
});

// Die Kennung für site.js - vor dem ersten <script>, damit sie steht,
// bevor irgendein Modul lädt. `in-app` an <html> blendet aus, was in der
// App nichts zu suchen hat (der Link zurück zur Hauptseite der Website).
const indexPfad = path.join(ziel, 'index.html');
let html = await readFile(indexPfad, 'utf8');

// Bis unter die Systemleisten zeichnen; den Abstand hält das CSS
// (.in-app body, safe-area-inset-*). Sonst läge über der App ein Streifen
// in fester Farbe, auch im hellen Design.
html = html.replace(
  /<meta name="viewport" content="([^"]*)">/,
  (m, inhalt) => (inhalt.includes('viewport-fit') ? m : `<meta name="viewport" content="${inhalt}, viewport-fit=cover">`),
);
const marke = '<script>';
const i = html.indexOf(marke);
if (i < 0) {
  console.error('index.html: kein <script> gefunden - wo soll die App-Kennung hin?');
  process.exit(1);
}
const kennung = `<script>window.TRAIN_MAXXING_APP = ${JSON.stringify({ site: siteUrl })};`
  + "document.documentElement.classList.add('in-app');</script>\n  ";
html = html.slice(0, i) + kennung + html.slice(i);
await writeFile(indexPfad, html);

console.log(`www/ erzeugt aus public/ - Daten von ${siteUrl}api/`);
