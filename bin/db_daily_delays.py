"""
Verspätung am Ziel je Zug und Tag aus dem Archiv der DB-Fahrplandaten.

    python3 bin/db_daily_delays.py 2026-11-22 [Ausgabeordner]

QUELLE: piebro/deutsche-bahn-data auf Hugging Face (CC BY 4.0, Daten der
Deutschen Bahn). Dort liegen je Tag die Rohantworten der Timetables-API als
Parquet, rund 140 MB: `plan` (Sollfahrplan je Bahnhof und Stunde) und `fchg`
(alle bekannten Änderungen je Bahnhof), alle sechs Stunden abgefragt.

RECHNUNG:
  - Aus `plan`: je Halt die Kennung (s id), Zug (Gattung + Nummer) und ob
    der Zug dort endet - eine Ankunft ohne Abfahrt.
  - Aus `fchg`: je Halt die zuletzt gemeldete Ist-Ankunft (ct), Ausfall
    (cs="c") und die Gründe (m t="d", Codes wie bei DbApi::GRUENDE).
  - Verspätung = Ist minus Soll am Endbahnhof, in Minuten. Steht ein
    Endbahnhof in keiner Änderung, war der Zug dort pünktlich (0).
  - Der Tag ist der Starttag des Zuges: die Kennung trägt ihn
    ("...-2611221418-15" = Start 22.11.26 14:18). So passt es zu
    Punctuality::record() in der App, die nach dem ersten Halt datiert.

AUSGABE: <Ausgabeordner>/<Tag>.json, klein (je Zug zwei Zahlen):
  {"date": "2026-11-22", "source": "...", "trains": {"ICE 618": [11, 36], ...}}
  [Minuten] oder [Minuten, Grund-Code]; ausgefallen: ["x"].

Läuft nachts als GitHub Action (.github/workflows/db-delays.yml) und legt
die Dateien in den Zweig `db-delays`. Die Website holt sie sich von dort.
"""
import json, os, re, sys, tempfile, urllib.request
from datetime import date, datetime, timedelta
import xml.etree.ElementTree as ET

import pyarrow.parquet as pq

HF = 'https://huggingface.co'
REPO = 'datasets/piebro/deutsche-bahn-data'
UA = {'User-Agent': 'train-maxxing (+https://github.com/Lemonatix/train-maxxing)'}


def files_for(tag: date) -> list[str]:
    """Die Rohdateien eines Tages und die ersten des Folgetags (späte Ankünfte)."""
    out = []
    for i, d in enumerate((tag, tag + timedelta(days=1))):
        url = f'{HF}/api/{REPO}/tree/main/raw_data/year%3D{d.year}/month%3D{d.month}/day%3D{d.day}'
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
                liste = json.load(r)
        except Exception:
            continue
        pfade = sorted(x['path'] for x in liste if x['path'].endswith('.parquet'))
        out += pfade if i == 0 else pfade[:1]
    return out


def download(pfad: str, ziel: str) -> None:
    url = f'{HF}/{REPO}/resolve/main/' + pfad.replace('=', '%3D') + '?download=true'
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=600) as r, open(ziel, 'wb') as f:
        while True:
            block = r.read(1 << 20)
            if not block:
                break
            f.write(block)


def ts(t: str | None) -> datetime | None:
    return datetime.strptime(t, '%y%m%d%H%M') if t and len(t) == 10 else None


def parse(dateien: list[str]):
    plan = {}    # id -> (zug, soll_ankunft, endet)
    aend = {}    # id -> (snapshot, ist_ankunft, ausgefallen, [gruende])
    for datei in dateien:
        tab = pq.ParquetFile(datei)
        for batch in tab.iter_batches(columns=['api_name', 'status_code', 'response_data', 'timestamp'], batch_size=2000):
            for row in batch.to_pylist():
                api = row['api_name']
                if row['status_code'] != '200' or api not in ('timetables/v1/plan', 'timetables/v1/fchg'):
                    continue
                try:
                    doc = ET.fromstring(row['response_data'])
                except ET.ParseError:
                    continue
                snap = row['timestamp']
                for s in doc.iter('s'):
                    sid = s.get('id')
                    if not sid:
                        continue
                    ar = s.find('ar')
                    if api == 'timetables/v1/plan':
                        tl = s.find('tl')
                        if tl is None or ar is None:
                            continue
                        zug = f"{tl.get('c', '').strip()} {tl.get('n', '').strip()}".strip()
                        plan[sid] = (zug, ar.get('pt'), s.find('dp') is None)
                    elif ar is not None:
                        alt = aend.get(sid)
                        if alt is not None and alt[0] > snap:
                            continue
                        gruende = [m.get('c') for m in ar.iter('m') if m.get('t') == 'd' and m.get('c')]
                        aend[sid] = (snap, ar.get('ct'), ar.get('cs') == 'c', gruende)
    return plan, aend


def compute(tag: date, plan: dict, aend: dict) -> dict:
    kennung = tag.strftime('%y%m%d')
    zuege = {}
    for sid, (zug, soll, endet) in plan.items():
        if not endet or not zug:
            continue
        teile = sid.rsplit('-', 2)
        if len(teile) != 3 or not teile[1].startswith(kennung):
            continue
        a = aend.get(sid)
        if a is not None and a[2]:
            wert = ['x']
        else:
            p, i = ts(soll), ts(a[1]) if a else None
            if p is None:
                continue
            minuten = int(round((i - p).total_seconds() / 60)) if i else 0
            wert = [minuten] + ([int(a[3][-1])] if a and a[3] and a[3][-1].isdigit() and minuten > 0 else [])
        # Dieselbe Nummer zweimal am Tag (selten): die größere Verspätung.
        alt = zuege.get(zug)
        if alt is None or (isinstance(wert[0], int) and isinstance(alt[0], int) and wert[0] > alt[0]):
            zuege[zug] = wert
    return zuege


def main() -> None:
    tag = date.fromisoformat(sys.argv[1]) if len(sys.argv) > 1 else date.today() - timedelta(days=1)
    ausgabe = sys.argv[2] if len(sys.argv) > 2 else 'out'
    os.makedirs(ausgabe, exist_ok=True)
    pfade = files_for(tag)
    if not pfade:
        sys.exit(f'Keine Rohdaten für {tag} im Archiv.')
    with tempfile.TemporaryDirectory() as tmp:
        dateien = []
        for p in pfade:
            ziel = os.path.join(tmp, os.path.basename(p))
            download(p, ziel)
            dateien.append(ziel)
        plan, aend = parse(dateien)
    zuege = compute(tag, plan, aend)
    with open(os.path.join(ausgabe, f'{tag.isoformat()}.json'), 'w', encoding='utf8') as f:
        json.dump({'date': tag.isoformat(), 'trains': zuege,
                   'source': 'Deutsche Bahn (CC BY 4.0) via piebro/deutsche-bahn-data'},
                  f, ensure_ascii=False, separators=(',', ':'))
    print(f'{tag}: {len(zuege)} Züge aus {len(pfade)} Dateien')


if __name__ == '__main__':
    main()
