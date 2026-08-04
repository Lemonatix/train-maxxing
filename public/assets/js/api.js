/**
 * Zugriff auf das PHP-Backend.
 *
 * Alle Aufrufe gehen relativ auf ./api/ - dadurch funktioniert das Tool in
 * jedem Unterordner deiner Website, ohne dass du eine Domain konfigurieren musst.
 */

const BASE = new URL('api/', document.baseURI).href;

async function call(action, params = {}, { signal } = {}) {
  const url = new URL(BASE);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  }

  let res;
  try {
    res = await fetch(url, { signal, headers: { Accept: 'application/json' } });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    throw new Error(
      'Das Backend ist nicht erreichbar. Läuft api/index.php auf dem Server und ist PHP aktiv?'
    );
  }

  let data;
  try {
    data = await res.json();
  } catch {
    // Typischer Fall: PHP wirft eine Warnung/Fatal und liefert HTML statt JSON.
    throw new Error(
      `Das Backend hat keine gültige Antwort geliefert (HTTP ${res.status}). Ruf api/index.php?action=health direkt auf, um die Fehlermeldung zu sehen.`
    );
  }

  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Fehler ${res.status}`);
  }

  return data;
}

export const api = {
  health: (opts) => call('health', {}, opts),

  catalogue: (opts) => call('catalogue', {}, opts),

  locations: (q, opts) => call('locations', { q }, opts),

  /** @param {number[]} bounds [süd, west, nord, ost] */
  liveTrains: (bounds, products, opts) =>
    call('livetrains', {
      bbox: bounds.map((v) => v.toFixed(4)).join(','),
      products: (products || []).join(','),
    }, opts),

  trainDetails: (jid, opts) => call('traindetails', { jid }, opts),

  bestPrices: (params, opts) =>
    call('bestprices', {
      from: params.from, to: params.to, date: params.date,
      class: params.travelClass || 2,
      discounts: (params.discounts || []).join(','),
      products: (params.products || []).join(','),
    }, opts),

  journeys(params, opts) {
    return call(
      'journeys',
      {
        from: params.from,
        to: params.to,
        date: params.date,
        time: params.time,
        arrival: params.arrival ? '1' : '0',
        class: params.travelClass || 2,
        results: params.results || 8,
        discounts: (params.discounts || []).join(','),
        products: (params.products || []).join(','),
        via: (params.via || []).join(','),
      },
      opts
    );
  },
};
