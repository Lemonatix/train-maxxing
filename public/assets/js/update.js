/**
 * Hinweis auf eine neuere Android-App.
 *
 * Die App aktualisiert sich nicht selbst - sie kommt als APK von der Website.
 * Wer eine alte Version hat, merkte davon nichts. Hier fragt die App den
 * Server nach der neuesten (action=appversion, api/data/app.json) und zeigt
 * oben einen Hinweis, wenn die eigene Build-Nummer kleiner ist: welche
 * Version, seit wann, und ein Link zum Herunterladen.
 *
 * Nur in der App (im Browser ist die Seite immer aktuell). Weggeklickt
 * bleibt der Hinweis für diese Version weg; eine noch neuere zeigt ihn wieder.
 */
import { api } from './api.js';
import { APP_VERSION } from './site.js';

const KEY = 'train-maxxing:update-dismissed';

export async function checkForUpdate(mount) {
  if (!APP_VERSION || !mount) return;
  let neu;
  try {
    neu = (await api.appVersion())?.app;
  } catch {
    return;   // Offline oder Server alt: kein Hinweis, kein Fehler.
  }
  if (!neu || !(Number(neu.build) > APP_VERSION.build)) return;
  try {
    if (localStorage.getItem(KEY) === String(neu.build)) return;
  } catch { /* dann eben zeigen */ }

  const seit = neu.released
    ? new Date(`${neu.released}T12:00:00`).toLocaleDateString('de-DE', { day: 'numeric', month: 'long' })
    : '';
  const box = document.createElement('div');
  box.className = 'update-hint';
  box.setAttribute('role', 'status');

  const text = document.createElement('span');
  text.className = 'update-hint__text';
  text.textContent = `Neue Version ${neu.version}${seit ? ` seit ${seit}` : ''} - installiert ist ${APP_VERSION.version || 'eine ältere'}.`;

  const link = document.createElement('a');
  link.className = 'update-hint__link';
  link.href = neu.download || 'https://mika-riesterer.de/downloads/#train-maxxing';
  link.target = '_blank';
  link.rel = 'noopener';
  link.textContent = 'Herunterladen';

  const zu = document.createElement('button');
  zu.type = 'button';
  zu.className = 'update-hint__close';
  zu.textContent = '×';
  zu.setAttribute('aria-label', 'Hinweis schließen');
  zu.addEventListener('click', () => {
    try { localStorage.setItem(KEY, String(neu.build)); } catch { /* nur für jetzt */ }
    box.remove();
  });

  box.append(text, link, zu);
  mount.prepend(box);
}
