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
 * Sagt die Ist-Zeit am Einstieg etwas? Eine Abweichung ja. Dieselbe Minute
 * wie der Plan dagegen nicht unbedingt: für Züge, die hier beginnen, liefert
 * HAFAS als "Prognose" einfach die Plan-Zeit - der RE9 München-Augsburg
 * stand so als "pünktlich" da, während sein Zubringer zehn Minuten Verspätung
 * hatte.
 */
function saysSomething(plan, real) {
  if (!real) return false;
  const p = Date.parse(plan || '');
  const r = Date.parse(real);
  return !(Number.isFinite(p) && Number.isFinite(r) && Math.round((r - p) / 60_000) === 0);
}

/**
 * Lohnt die Frage für diesen Abschnitt? Nur Züge mit EVA-Nummer am Einstieg,
 * die noch nicht abgefahren sind und dort keine aussagekräftige Ist-Zeit
 * haben. Ob der Zug wirklich hier beginnt, entscheidet der Server.
 *
 * @param {object} leg      Abschnitt der Verbindung
 * @param {?object} run     nachgeladener Zuglauf, falls schon da
 */
export function wantsInbound(leg, run = null) {
  if (!leg || leg.mode === 'walk' || !/^\d{7}$/.test(String(leg.from?.id || ''))) return false;
  const ab = Date.parse(leg.departure || '');
  if (!Number.isFinite(ab) || Date.now() > ab + 2 * 60_000) return false;
  if (saysSomething(leg.departure, leg.departureReal)) return false;
  const ein = (run?.stops || []).find((s) => String(s.id || '') === String(leg.from.id))
    || (run?.stops || []).find((s) => s.name === leg.from.name);
  return !saysSomething(ein?.departure, ein?.departureReal);
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
 * So lange nach der Ankunft, bis man einsteigen kann: erst steigen die
 * Fahrgäste des einfahrenden Zuges aus. In der Regionalbahn geht das
 * schnell, zwei Minuten sind eine vorsichtige Schätzung.
 */
const BOARD_AFTER_MIN = 2;
/** Ab so viel Wartezeit am Gleis keine Einsteige-Zeit mehr versprechen. */
const LONG_WAIT_MIN = 20;

/**
 * Der Satz dazu.
 *
 * `text` ist die ausführliche Fassung (Live-Verfolgung, Zuglauf), `board`
 * die kurze für die Trefferkarte: ab wann man einsteigen kann.
 *
 * @returns {{text: string, board: string, late: boolean, title: string}}
 */
export function inboundText(link) {
  const z = link.inbound;
  const name = trainLabel({ category: z.category, line: z.line, trainNumber: z.trainNumber, name: z.name });
  const an = z.real || z.planned;
  const schonDa = Date.parse(an) <= Date.now();
  const verspaetung = z.delay > 0 ? ` (+${z.delay})` : '';
  let text = schonDa
    ? `Steht vermutlich schon da — kam als ${name} aus ${z.from} um ${hhmm(an)}`
    : `Kommt vermutlich als ${name} aus ${z.from} um ${hhmm(z.planned)}${verspaetung}`;
  if (!schonDa && !z.real) text += ' nach Fahrplan';
  if (link.expectedDeparture) text += ` — Abfahrt wohl erst gegen ${hhmm(link.expectedDeparture)}`;

  // Steht der Zug lange vor der Abfahrt da, wird er oft abgeschlossen oder
  // zum Reinigen abgestellt - dann keine Einsteige-Zeit versprechen, nur
  // sagen, ab wann er am Gleis ist.
  const herkunft = `${name} aus ${z.from}`;
  const bereitAb = Date.parse(an) + BOARD_AFTER_MIN * 60_000;
  const lange = Date.parse(link.departure?.planned || '') - bereitAb > LONG_WAIT_MIN * 60_000;
  let board;
  if (schonDa) {
    board = `Steht vermutlich schon am Gleis · kam als ${herkunft}`;
  } else {
    board = (lange ? `Zug vermutlich ab ca. ${hhmm(new Date(bereitAb).toISOString())} am Gleis`
      : `Einsteigen vermutlich ab ca. ${hhmm(new Date(bereitAb).toISOString())}`)
      + ` · kommt als ${herkunft} um ${hhmm(an)}${verspaetung}${z.real ? '' : ' (Fahrplan)'}`;
  }
  if (link.expectedDeparture) board += ` · Abfahrt wohl erst ${hhmm(link.expectedDeparture)}`;
  return {
    text,
    board,
    late: Boolean(link.expectedDeparture),
    title: 'Geschätzt aus der Gleisbelegung: der Zug, der vorher an diesem Gleis ankommt und hier endet. '
      + 'Die Bahn meldet nicht, welches Fahrzeug welche Fahrt übernimmt.'
      + (link.confidence === 'line' ? ' Ohne Gleisangabe nur über die Linie zugeordnet.' : ''),
  };
}
