"""
Lücken in einem Wegenetz-Entwurf schließen: Teile, die auf DERSELBEN Ebene
höchstens <Meter> auseinanderliegen, werden mit einem geraden Stück
verbunden - immer das kürzeste Paar zuerst, bis nichts mehr passt.

    python3 bin/close_walk_gaps.py <EVA> <Meter>

Wozu: In Zürich HB enden die Rolltreppen der Bahnsteige auf Ebenen, deren
Böden in OSM als Fläche und nicht als Weg erfasst sind - ohne die Brücken
hing jeder Bahnsteig für sich. Die neuen Kanten tragen `note: "lücke"` und
`reviewed: false`; ob ein gerades Stück so gelaufen werden kann, sagt erst
die Prüfung auf der Karte. Läuft das Skript erneut, ersetzt es seine alten
Brücken.
"""
import json, math, os, sys, collections
eva, maxm = sys.argv[1], float(sys.argv[2])
p = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'api', 'data', 'walks', f'{eva}.json')
d = json.load(open(p, encoding='utf8'))
d['edges'] = [e for e in d['edges'] if e.get('note') != 'lücke']
def m(a, b):
    kx = math.cos(math.radians(a[0]))
    return math.hypot((a[0]-b[0])*111320, (a[1]-b[1])*111320*kx)
G = set(d['tracks'].values())
neu = 0
while True:
    adj = collections.defaultdict(set)
    for e in d['edges']:
        adj[e['a']].add(e['b']); adj[e['b']].add(e['a'])
    comp = {}
    for s in d['nodes']:
        if s in comp or s in G: continue
        st = [s]; comp[s] = s
        while st:
            u = st.pop()
            for v in adj[u]:
                if v not in comp and v not in G:
                    comp[v] = s; st.append(v)
    # nächstes Paar zweier Komponenten auf derselben Ebene
    by_lv = collections.defaultdict(list)
    for k, v in d['nodes'].items():
        if k in G: continue
        by_lv[v[2]].append(k)
    best = None
    for lv, ks in by_lv.items():
        for i, a in enumerate(ks):
            for b in ks[i+1:]:
                if comp[a] == comp[b]: continue
                dist = m(d['nodes'][a], d['nodes'][b])
                if dist <= maxm and (best is None or dist < best[0]):
                    best = (dist, a, b)
    if best is None: break
    d['edges'].append({'a': best[1], 'b': best[2], 'via': [], 'kind': 'walk', 'reviewed': False, 'note': 'lücke'})
    neu += 1
json.dump(d, open(p, 'w', encoding='utf8'), ensure_ascii=False, separators=(',', ':'))
print('Lücken geschlossen:', neu)
