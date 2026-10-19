/**
 * Wo die App zu Hause ist - im Browser oder als Android-App.
 *
 * IM BROWSER ist die Antwort die eigene Adresse: index.html liegt in einem
 * beliebigen Ordner der Website, und alles (API, geteilte Links) hängt
 * relativ daran. So läuft das Tool in jedem Unterordner ohne Einstellung.
 *
 * IN DER APP (app/, Capacitor) liegen dieselben Dateien im Gerät und werden
 * unter https://localhost geladen. Relativ wäre dann alles falsch: die API
 * läge auf dem Telefon, und ein geteilter Link zeigte auf "localhost". Das
 * Build-Skript der App (app/scripts/prepare-www.mjs) setzt deshalb vor allen
 * Modulen `window.TRAIN_MAXXING_APP = { site: 'https://…/' }` - die öffentliche
 * Adresse der Website, an der API und Links hängen.
 */

const app = typeof window !== 'undefined' ? window.TRAIN_MAXXING_APP : null;

/** Läuft die Seite in der Android-App? */
export const IN_APP = Boolean(app?.site);

/**
 * Die öffentliche Adresse der Seite, mit Schrägstrich am Ende - z. B.
 * "https://mika-riesterer.de/train-maxxing/public/". Im Browser der Ordner von
 * index.html.
 */
export const SITE = IN_APP
  ? new URL('./', app.site).href
  : new URL('./', typeof document !== 'undefined' ? document.baseURI : 'http://localhost/').href;

/** Eine Adresse relativ zur Seite, z. B. siteUrl('api/') oder siteUrl('?live=…'). */
export function siteUrl(path = '') {
  return new URL(path, SITE).href;
}
