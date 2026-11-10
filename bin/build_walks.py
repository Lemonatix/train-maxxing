"""
Wegenetz eines Bahnhofs für den Umstiegsplan (lib/StationWalks.php), aus
den Fußwegen, Treppen und Rolltreppen in OpenStreetMap.

    python3 bin/build_walks.py <overpass.json> <EVA> "<Name>"

<overpass.json> ist die Antwort von Overpass auf diese Abfrage (R = 450 m
um den Bahnhof, `out body geom`, damit die Wege ihre Knotennummern tragen):

    way(around:R,lat,lon)["railway"="platform"];
    way(around:R,lat,lon)["highway"~"^(footway|steps|corridor|pedestrian|elevator)$"];
    way(around:R,lat,lon)["conveying"];
    node(around:R,lat,lon)["highway"="elevator"];

Die Gleispunkte kommen aus public/api/data/platforms/<EVA>.json
(bin/build_platforms.php), die Ausgabe ist public/api/data/walks/<EVA>.json.

DAS SKRIPT RECHNET NUR DEN ENTWURF. Jeder Abschnitt steht danach mit
`reviewed: false` da und wird nicht ausgeliefert, bis ein Mensch den Weg
auf der Karte angesehen hat (Prüfmodus: ?wegpruefung an der Adresse) und
ihn abnimmt. Der erste Versuch, Laufwege rein aus OSM zu rechnen, ist genau
daran gescheitert (README, "Kein Laufweg mehr").

Knoten werden je EBENE geführt ("<osmid>@<ebene>"): derselbe OSM-Knoten an
einem Aufzug ist auf jeder Ebene ein eigener Punkt, verbunden durch eine
Aufzugskante. Treppen und Rolltreppen mit "level=0;-1" verbinden ihre beiden
Enden über die Ebenen hinweg; welches Ende oben liegt, sagt die Ebene der
anschließenden Wege.
"""
import heapq, json, math, sys
from collections import defaultdict

import os
HBF, EVA, NAME = sys.argv[1:4]
_root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'api', 'data')
PLAT = os.path.join(_root, 'platforms', f'{EVA}.json')
OUT = os.path.join(_root, 'walks', f'{EVA}.json')
d = json.load(open(HBF))
st = json.load(open(PLAT))

def lv_list(t):
    v = t.get('level')
    if v is None:
        return None
    out = []
    for x in str(v).split(';'):
        try:
            out.append(float(x))
        except ValueError:
            pass
    return out or None

def metres(a, b):
    kx = math.cos(math.radians((a[0] + b[0]) / 2))
    return math.hypot((a[0] - b[0]) * 111320, (a[1] - b[1]) * 111320 * kx)

ways = [e for e in d['elements'] if e['type'] == 'way' and 'geometry' in e]
pos = {}
for w in ways:
    for nid, g in zip(w['nodes'], w['geometry']):
        pos[nid] = (g['lat'], g['lon'])

def kind_of(t):
    if t.get('railway') == 'platform' or t.get('public_transport') == 'platform':
        return 'platform'
    if 'conveying' in t:
        return 'escalator'
    h = t.get('highway')
    if h in ('footway', 'corridor', 'pedestrian'):
        return 'walk'
    if h == 'steps':
        return 'steps'
    if h == 'elevator':
        return 'elevator'
    return None

# Ebene jedes Knotens aus den einstufigen Wegen.
node_lv = defaultdict(set)
for w in ways:
    t = w.get('tags', {})
    k = kind_of(t)
    lv = lv_list(t)
    if k in ('walk', 'platform') and lv and len(lv) == 1:
        for n in w['nodes']:
            node_lv[n].add(lv[0])
# Wege ohne Ebene: draußen, Straßenniveau.
for w in ways:
    t = w.get('tags', {})
    if kind_of(t) == 'walk' and lv_list(t) is None and t.get('tunnel') != 'yes':
        for n in w['nodes']:
            node_lv[n].add(0.0)

edges = []      # (a, b, kind, via, osmid)
def key(n, lv):
    return f'{n}@{lv:g}'

for w in ways:
    t = w.get('tags', {})
    k = kind_of(t)
    if k is None or k == 'platform' or t.get('access') in ('no', 'private'):
        continue
    lv = lv_list(t) or [0.0]
    ns = w['nodes']
    if k in ('steps', 'escalator') and len(lv) >= 2:
        # Ende oben/unten über die anschließenden Wege.
        a, b = ns[0], ns[-1]
        la = node_lv.get(a, set()) & set(lv)
        lb = node_lv.get(b, set()) & set(lv)
        hi, lo = max(lv), min(lv)
        if la and lb and la != lb:
            ea, eb = max(la), max(lb)
        elif la:
            ea = max(la); eb = lo if ea == hi else hi
        elif lb:
            eb = max(lb); ea = lo if eb == hi else hi
        else:
            continue  # nicht angebunden
        via = [pos[n] for n in ns[1:-1]]
        edges.append((key(a, ea), key(b, eb), k, via, w['id']))
        continue
    if len(lv) != 1:
        continue
    L = lv[0]
    for x, y in zip(ns, ns[1:]):
        edges.append((key(x, L), key(y, L), 'steps' if k == 'steps' else 'walk', [], w['id']))

# Aufzüge als Knoten mit mehreren Ebenen.
for e in d['elements']:
    if e['type'] == 'node' and e.get('tags', {}).get('highway') == 'elevator':
        lv = sorted(lv_list(e['tags']) or [])
        pos[e['id']] = (e['lat'], e['lon'])
        for a, b in zip(lv, lv[1:]):
            edges.append((key(e['id'], a), key(e['id'], b), 'elevator', [], e['id']))

# Bahnsteige: jeder Gleispunkt wird ein Knoten, verbunden mit den Knoten des
# Bahnsteigumrisses, an denen ein Weg anschließt.
adj_nodes = defaultdict(set)
for a, b, *_ in edges:
    adj_nodes[a.split('@')[0]].add(a)
    adj_nodes[b.split('@')[0]].add(b)

def tracks_of(t):
    ref = t.get('local_ref') or t.get('ref') or ''
    return [x.strip() for x in ref.replace(',', ';').split(';') if x.strip()]

tracks = {}
# Nur Bahnsteige der Eisenbahn: Tram- und Bussteige tragen eigene Nummern
# ("Hauptbahnhof Süd, 1"), die U-Bahn ist bei der DB ein eigener Halt - und
# die S-Bahn tief ein eigener Bahnhof mit eigener EVA-Nummer.
def eisenbahn(t):
    if t.get('railway') != 'platform':
        return False
    return not any(t.get(x) == 'yes' for x in ('bus', 'tram', 'subway', 'light_rail'))
plat_ways = [w for w in ways if eisenbahn(w.get('tags', {}))]
for w in plat_ways:
    t = w['tags']
    trs = tracks_of(t)
    lv = (lv_list(t) or [0.0])[0]
    anschluss = [n for n in w["nodes"] if key(n, lv) in adj_nodes.get(str(n), set())]
    for tr in trs:
        p = st['trackPoints'].get(tr)
        if not p:
            p = (sum(pos[n][0] for n in w['nodes']) / len(w['nodes']), sum(pos[n][1] for n in w['nodes']) / len(w['nodes']))
        tid = f'G{tr}'
        pos[tid] = tuple(p)
        tracks[tr] = key(tid, lv)
        # EINSTIEG, nicht Weg: wo man am Bahnsteig steht, hängt am Wagen.
        # Der Weg beginnt am Anschluss - in der Kopfhalle am Bahnsteigkopf.
        for n in set(anschluss):
            edges.append((key(tid, lv), key(n, lv), 'board', [], f'platform:{w["id"]}'))

nodes = {}
for a, b, *_ in edges:
    for k in (a, b):
        n, lv = k.split('@')
        p = pos[int(n) if n.isdigit() else n]
        nodes[k] = [round(p[0], 7), round(p[1], 7), float(lv)]

# Ketten von Knoten mit genau zwei Nachbarn zu einem Abschnitt zusammenfassen:
# weniger Abschnitte zum Abnehmen, dieselben Wege.
nachbarn = defaultdict(list)
for i, (a, b, k, via, oid) in enumerate(edges):
    nachbarn[a].append(i); nachbarn[b].append(i)
behalten = {k for k in nodes if len(nachbarn[k]) != 2} | set(tracks.values())
for i, (a, b, k, via, oid) in enumerate(edges):
    if k != 'walk':
        behalten.update((a, b))
fertig = set()
out_edges = []
for start in behalten:
    for i in nachbarn[start]:
        if i in fertig:
            continue
        kette, via, cur, ei = [start], [], start, i
        kinds = set()
        while True:
            fertig.add(ei)
            a, b, k, v, oid = edges[ei]
            kinds.add(k)
            nxt = b if a == cur else a
            via.extend(v if a == cur else list(reversed(v)))
            if nxt in behalten or k != 'walk':
                break
            via.append(nodes[nxt][:2])
            weiter = [j for j in nachbarn[nxt] if j != ei and j not in fertig and edges[j][2] == 'walk']
            if not weiter:
                break
            cur, ei = nxt, weiter[0]
        kind = next((x for x in ('board', 'elevator', 'escalator', 'steps') if x in kinds), 'walk')
        out_edges.append({'a': start, 'b': nxt, 'via': [[round(p[0], 7), round(p[1], 7)] for p in via],
                          'kind': kind, 'reviewed': False})
used = {e['a'] for e in out_edges} | {e['b'] for e in out_edges}
json.dump({'eva': EVA, 'name': NAME,
           'source': '© OpenStreetMap-Mitwirkende (ODbL), von Hand geprüft',
           'nodes': {k: v for k, v in nodes.items() if k in used},
           'tracks': tracks, 'edges': out_edges},
          open(OUT, 'w'), ensure_ascii=False, separators=(',', ':'))
print('Knoten', len(used), 'Abschnitte', len(out_edges), 'Gleise', sorted(tracks, key=lambda x: int(x) if x.isdigit() else 999))
