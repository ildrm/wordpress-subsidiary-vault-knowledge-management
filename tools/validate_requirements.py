#!/usr/bin/env python3
import csv
import hashlib
import json
import re
from collections import Counter
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
D=ROOT/'docs/requirements'
c=json.loads((D/'canonical-requirements.json').read_text())
source=json.loads((ROOT/'docs/source/SubsidiaryVault_KMS_346_Requirements.json').read_text())
assert c==source,'Canonical contracts changed'
features=[f for m in c['modules'] for f in m['features']]
ids={f['id'] for f in features}
expected={'TEN':14,'IAM':17,'AUT':13,'DOC':22,'MET':16,'COL':16,'KNO':16,'WFL':19,'REC':18,'ENC':20,'SEC':16,'EXT':13,'RPT':14,'AIX':17,'L10':15,'API':17,'UX':13,'MOB':12,'PRV':14,'OPS':21,'MIG':13,'VRP':10}
assert Counter(f['code'] for f in features)==expected
assert len(features)==len(ids)==346 and len(c['modules'])==22
for file in ['traceability.csv','mode-compatibility.csv']:
    rows=list(csv.DictReader((D/file).open()));assert len(rows)==346 and {r['id'] for r in rows}==ids
rows=list(csv.DictReader((D/'traceability.csv').open()))
allowed={'NOT_STARTED','IN_PROGRESS','IMPLEMENTED_UNVERIFIED','VERIFIED','BLOCKED','DEFERRED'}
for row in rows:
    assert row['status'] in allowed
    if row['status']=='VERIFIED':assert row['verification_evidence'] and row['tests']
    for path in row['code_paths'].split(';'):
        if path:assert (ROOT/path).is_file(),path
assert len({r['persona_id'] for r in csv.DictReader((D/'permission-matrix.csv').open())})==26
assert len(list((ROOT/'docs/decisions').glob('D-*.md')))==22
for item in json.loads((ROOT/'docs/source/manifest.json').read_text()):
    assert hashlib.sha256((ROOT/'docs/source'/item['file']).read_bytes()).hexdigest()==item['sha256']
catalogs=json.loads((ROOT/'packages/i18n/catalogs.json').read_text())
assert len(catalogs)==13 and all(set(v)==set(catalogs['en']) for v in catalogs.values())
assert catalogs==json.loads((ROOT/'apps/admin-web/locales.json').read_text())
print('Registry valid: 346 immutable contracts, 22 modules, 26 personas, 22 ADRs, 13 draft catalogs; source hashes match.')
