/**
 * Zubringer: mit welchem Zug kommt der Zug an, der am Einstieg erst beginnt?
 *
 * Ein Zug, der hier beginnt, hat vor der Abfahrt keine Echtzeit - "pünktlich"
 * hieße dort nur "keine Information". Der Server schätzt aus der Gleis-
 * belegung, welcher einfahrende Zug als dieser wieder abfährt (siehe
 * api/lib/Inbound.php); hier wird gefragt und ein Satz daraus gemacht.
 *
 * Eine SCHÄTZUNG, und so steht sie auch da ("vermutlich"). Sie geht nicht in
 * Benachrichtigungen: eine falsche Meldung "dein Zug kommt zwölf Minuten zu
 * spät" wäre schlimmer als gar keine.
 */
import { api } from './api.js';
import { trainLabel } from './map.js';

/** Gleich wie der Server: eine Antwort je Bahnhof und Viertelstunde. */
const BUCKET_MS = 15 * 60_000;
const TTL_MS = 60_000;
const cache = new Map(); // "station|bucket" -> {at, promise}

const hhmm = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
};

/**
 * Lohnt die Frage für diesen Abschnitt? Nur Züge mit EVA-Nummer am Einstieg,
 * die noch nicht abgefahren sind und dort keine Ist-Zeit haben.
 *
 * @param {object} leg      Abschnitt der Verbindung
 * @param {?object} run     nachgeladener Zuglauf, falls schon da
 */
export function wantsInbound(leg, run = null) {
  if (!leg || leg.mode === 'walk' || !/^\d{7}$/.test(String(leg.from?.id || ''))) return false;
  const ab = Date.parse(leg.departure || '');
  if (!Number.isFinite(ab) || Date.now() > ab + 2 * 60_000) return false;
  if (leg.departureReal) return false;
  const ein = (run?.stops || []).find((s) => String(s.id || '') === String(leg.from.id))
    || (run?.stops || []).find((s) => s.name === leg.from.name);
  return !ein?.departureReal;
}

/** Der Zubringer zu einem Abschnitt, oder null. Fehler bleiben still. */
export async function inboundFor(leg) {
  const ab = Date.parse(leg.departure);
  const station = String(leg.from.id);
  const key = `${station}|${Math.floor(ab / BUCKET_MS)}`;
  let e = cache.get(key);
  if (!e || Date.now() - e.at > TTL_MS) {
    e = {
      at: Date.now(),
      promise: api.inbound(station, new Date(ab).toISOString()).then((r) => r.links || []).catch(() => []),
    };
    cache.set(key, e);
  }
  const links = await e.promise;
  const nr = String(leg.trainNumber || '');
  const minute = Math.floor(ab / 60_000);
  return links.find((l) => nr && l.departure.trainNumber === nr)
    // Ohne passende Nummer (andere Quelle, andere Schreibweise): gleiche
    // Abfahrtsminute und gleiche Linie.
    || links.find((l) => Math.floor(Date.parse(l.departure.planned) / 60_000) === minute
      && l.departure.line && l.departure.line.replace(/\s/g, '') === String(leg.line || '').replace(/\s/g, ''))
    || null;
}

/**
 * Der Satz dazu.
 *
 * @returns {{text: string, late: boolean, title: string}}
 */
export function inboundText(link) {
  const z = link.inbound;
  const name = trainLabel({ category: z.category, line: z.line, trainNumber: z.trainNumber, name: z.name });
  const an = z.real || z.planned;
  const schonDa = Date.parse(an) <= Date.now();
  const verspaetung = z.delay > 0 ? ` (+${z.delay})` : '';
  let text = schonDa
    ? `Steht vermutlich schon da — kam als ${name} aus ${z.from}, ${hhmm(an)}`
    : `Kommt vermutlich als ${name} aus ${z.from} · an ${hhmm(z.planned)}${verspaetung}`;
  if (!schonDa && !z.real) text += ' nach Fahrplan';
  if (link.expectedDeparture) text += ` — Abfahrt wohl erst gegen ${hhmm(link.expectedDeparture)}`;
  return {
    text,
    late: Boolean(link.expectedDeparture),
    title: 'Geschätzt aus der Gleisbelegung: der Zug, der vorher an diesem Gleis ankommt und hier endet. '
      + 'Die Bahn meldet nicht, welches Fahrzeug welche Fahrt übernimmt.'
      + (link.confidence === 'line' ? ' Ohne Gleisangabe nur über die Linie zugeordnet.' : ''),
  };
}
