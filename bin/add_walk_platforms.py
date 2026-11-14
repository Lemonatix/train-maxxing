"""
Weitere Bahnsteige an ein schon abgenommenes Wegenetz hängen - U-Bahn und
S-Bahn tief, ohne das Netz neu zu rechnen (bin/build_walks.py --neu würde
jede Abnahme verwerfen).

    python3 bin/add_walk_platforms.py <overpass.json> <EVA> <Gleis>=<Weg-ID>:<Ebene>[@lat,lon] …

<overpass.json> ist dieselbe Abfrage wie bei build_walks.py. Je Gleis wird
ein Knoten "G<Gleis>@<Ebene>" angelegt und über Einstiegskanten (board) mit
den Knoten des Bahnsteigumrisses verbunden, an denen das Netz anschließt.
Ohne @lat,lon steht der Gleispunkt in der Mitte des Bahnsteigs.

Die neuen Kanten sind `reviewed: false` - ausgeliefert werden die Wege
erst, wenn sie im Prüfmodus (?wegpruefung) angesehen und abgenommen sind.

U-Bahn-Gleise haben keine Nummer im Fahrplan. Welcher Bahnsteig zu einem
Abschnitt gehört, steht deshalb in `lineTracks` der Netzdatei - je Linie
und Richtung, siehe StationWalks::trackFor(). Das trägt man von Hand ein.
"""
import json, os, sys

HBF, EVA, *SPECS = sys.argv[1:]
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'api', 'data', 'walks', f'{EVA}.json')
d = json.load(open(HBF))
net = json.load(open(OUT, encoding='utf8'))
ways = {e['id']: e for e in d['elements'] if e['type'] == 'way' and 'geometry' in e}

for spec in SPECS:
    gleis, rest = spec.split('=', 1)
    punkt = None
    if '@' in rest:
        rest, p = rest.split('@', 1)
        punkt = [float(x) for x in p.split(',')]
    wid, lv = rest.split(':')
    w = ways[int(wid)]
    lv = float(lv)
    if punkt is None:
        punkt = [sum(g['lat'] for g in w['geometry']) / len(w['geometry']),
                 sum(g['lon'] for g in w['geometry']) / len(w['geometry'])]
    tid = f'G{gleis}@{lv:g}'
    net['nodes'][tid] = [round(punkt[0], 7), round(punkt[1], 7), lv]
    net['tracks'][gleis] = tid
    anschluss = sorted({f'{n}@{lv:g}' for n in w['nodes'] if f'{n}@{lv:g}' in net['nodes']})
    # Alte Einstiegskanten dieses Gleises ersetzen, falls das Skript zweimal läuft.
    net['edges'] = [e for e in net['edges'] if not (e['kind'] == 'board' and tid in (e['a'], e['b']))]
    for n in anschluss:
        net['edges'].append({'a': tid, 'b': n, 'via': [], 'kind': 'board', 'reviewed': False})
    print(gleis, tid, len(anschluss), 'Anschlüsse')

json.dump(net, open(OUT, 'w', encoding='utf8'), ensure_ascii=False, separators=(',', ':'))
