/**
 * Der Kasten "Auf mehreren Geräten" unter den gemerkten Reisen - siehe sync.js.
 *
 * Drei Zustände: aus (Code erstellen oder eingeben), Angebot (ein Link mit
 * Code wurde geöffnet - erst fragen, dann verbinden) und an (Stand, Code für
 * weitere Geräte, trennen).
 *
 * ERST FRAGEN beim Link: Wer einen fremden Link öffnet und still verbunden
 * würde, schickte seine Reisen an jemanden, der den Code kennt.
 */
import { sync, formatCode, codeLink, normalizeCode } from './sync.js';
import { qrSvg } from './qr.js';

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

const button = (text, onClick, cls = '') => {
  const b = el('button', 'live-btn' + (cls ? ' ' + cls : ''), text);
  b.type = 'button';
  b.addEventListener('click', onClick);
  return b;
};

function wann(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const zeit = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString()
    ? zeit
    : `${d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' })}, ${zeit}`;
}

export function createSyncPanel() {
  const box = el('section', 'sync');
  box.setAttribute('aria-label', 'Reisen auf mehreren Geräten');
  let angebot = null;   // Code aus einem Link, noch nicht bestätigt
  let eingabe = '';     // was im Feld steht - überlebt das Neuzeichnen
  let codeOffen = false;
  let qrOffen = false;
  let trennenFragen = false;
  let meldung = null;   // {text, bad}

  const melde = (text, bad = false) => { meldung = { text, bad }; render(); };

  async function verbinden(code) {
    meldung = null;
    try {
      await sync.connect(code);
      angebot = null;
      eingabe = '';
      if (sync.status().state === 'ok') melde('Verbunden — Reisen und Lieblingsorte beider Geräte sind zusammengeführt, Abos und Lieblingszüge übernommen.');
      else render();
    } catch (err) {
      melde(err.message, true);
    }
  }

  async function kopieren(text, ok) {
    try {
      await navigator.clipboard.writeText(text);
      melde(ok);
    } catch {
      melde('Kopieren ging nicht — bitte von Hand abschreiben.', true);
    }
  }

  function renderAus() {
    box.append(el('h3', 'sync__title', 'Auf mehreren Geräten'));
    box.append(el('p', 'sync__text',
      'Gemerkte Reisen, Lieblingsorte, Abos und Lieblingszüge liegen bisher nur in diesem Browser. Mit einem '
      + 'Sync-Code gleichen Handy und Laptop sie ab — ohne Konto. Verschlüsselt: der Server kann sie nicht lesen.'));

    const reihe = el('div', 'sync__row');
    reihe.append(button('Sync-Code erstellen', async () => {
      meldung = null;
      await sync.create();
      codeOffen = true;
      render();
    }));
    box.append(reihe);

    const form = el('form', 'sync__row sync__form');
    const input = el('input', 'sync__input');
    input.type = 'text';
    input.placeholder = 'Code vom anderen Gerät';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('autocapitalize', 'characters');
    input.setAttribute('aria-label', 'Sync-Code vom anderen Gerät');
    input.value = eingabe;
    input.addEventListener('input', () => { eingabe = input.value; });
    const los = el('button', 'live-btn', 'Verbinden');
    los.type = 'submit';
    form.append(input, los);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!normalizeCode(eingabe)) {
        melde('Ein Sync-Code hat 20 Zeichen, z.B. K7QM-2XPA-9DTE-R4HB-6WNC.', true);
        return;
      }
      verbinden(eingabe);
    });
    box.append(form);
  }

  function renderAngebot() {
    box.classList.add('is-offer');
    box.append(el('h3', 'sync__title', 'Mit diesem Sync-Code verbinden?'));
    box.append(el('code', 'sync__code-text', formatCode(angebot)));
    box.append(el('p', 'sync__text',
      'Deine gemerkten Reisen und Lieblingsorte werden mit allen Geräten zusammengeführt, die diesen Code '
      + 'nutzen, und sind dort sichtbar. Bei Abos und Lieblingszügen gilt die zuletzt geänderte Fassung. '
      + 'Verbinde nur Codes, die von dir selbst stammen.'));
    const reihe = el('div', 'sync__row');
    reihe.append(button('Verbinden', () => verbinden(angebot), 'is-on'));
    reihe.append(button('Abbrechen', () => { angebot = null; render(); }));
    box.append(reihe);
  }

  function renderAn() {
    const st = sync.status();
    const code = sync.code();
    box.append(el('h3', 'sync__title', 'Auf mehreren Geräten'));

    let text = '⇅ Abgleich aktiv';
    let cls = 'is-ok';
    if (st.state === 'syncing') { text = '⇅ Gleiche ab …'; cls = ''; }
    else if (st.state === 'error') { text = `Abgleich fehlgeschlagen: ${st.error}`; cls = 'is-bad'; }
    else if (st.at) text += ` · zuletzt ${wann(st.at)}`;
    box.append(el('p', `sync__status ${cls}`, text));

    const det = el('details', 'sync__details');
    det.open = codeOffen;
    det.addEventListener('toggle', () => { codeOffen = det.open; });
    det.append(el('summary', null, 'Code für weitere Geräte'));
    det.append(el('code', 'sync__code-text', formatCode(code)));
    const reihe = el('div', 'sync__row');
    reihe.append(button('Code kopieren', () => kopieren(formatCode(code), 'Code kopiert.')));
    reihe.append(button('Link teilen', async () => {
      const url = codeLink(code);
      if (navigator.share) {
        try { await navigator.share({ title: 'train-maxxing – Reisen abgleichen', url }); } catch { /* abgebrochen */ }
      } else {
        kopieren(url, 'Link kopiert.');
      }
    }));
    reihe.append(button(qrOffen ? 'QR-Code ausblenden' : 'QR-Code zeigen', () => {
      qrOffen = !qrOffen;
      render();
    }));
    det.append(reihe);
    if (qrOffen) {
      const qr = el('div', 'sync__qr');
      try {
        qr.append(qrSvg(codeLink(code), { label: 'QR-Code mit dem Link zum Verbinden' }));
        qr.append(el('p', 'sync__hint', 'Mit der Handykamera scannen. Der Link öffnet sich im Browser und '
          + 'fragt vor dem Verbinden nach.'));
      } catch {
        qr.append(el('p', 'sync__hint', 'Der Link ist zu lang für einen QR-Code — bitte den Code eintippen.'));
      }
      det.append(qr);
    }
    det.append(el('p', 'sync__hint',
      'Auf dem anderen Gerät unter „Reisen" eingeben. In der App vom iPhone-Home-Bildschirm bitte '
      + 'eintippen: ein Link öffnet Safari, und Safari hat einen eigenen Speicher.'));
    det.append(el('p', 'sync__hint',
      'Wer den Code hat, sieht deine gemerkten Reisen, Lieblingsorte und Abos. Nicht öffentlich teilen.'));
    box.append(det);

    const knoepfe = el('div', 'sync__row');
    knoepfe.append(button('Jetzt abgleichen', () => { meldung = null; sync.syncNow(); }));
    knoepfe.append(button(trennenFragen ? 'Wirklich trennen?' : 'Trennen', () => {
      if (!trennenFragen) {
        trennenFragen = true;
        render();
        return;
      }
      trennenFragen = false;
      codeOffen = false;
      sync.disconnect();
      melde('Getrennt. Reisen, Lieblingsorte und Einstellungen bleiben hier und auf den anderen Geräten.');
    }, trennenFragen ? 'sync__danger' : ''));
    box.append(knoepfe);
  }

  function render() {
    box.replaceChildren();
    box.classList.remove('is-offer');
    if (angebot) renderAngebot();
    else if (sync.enabled()) renderAn();
    else renderAus();
    if (meldung) box.append(el('p', 'sync__msg' + (meldung.bad ? ' is-bad' : ''), meldung.text));
  }

  sync.onStatus(render);
  render();

  return {
    el: box,
    /** Ein Link mit Code wurde geöffnet: Verbinden anbieten. */
    offer(input) {
      const code = normalizeCode(input);
      if (!code) return;
      if (code === sync.code()) {
        melde('Dieses Gerät nutzt den Code schon.');
        return;
      }
      angebot = code;
      meldung = null;
      render();
    },
  };
}
