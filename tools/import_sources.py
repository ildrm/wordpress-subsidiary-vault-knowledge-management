#!/usr/bin/env python3
"""Archive the supplied specifications, extract Office XML, reconcile IDs without Office dependencies."""
import csv
import hashlib
import json
import re
import shutil
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
source = Path(sys.argv[1])
out = ROOT / 'docs/source'
out.mkdir(parents=True, exist_ok=True)
manifest = []
for path in sorted(source.iterdir()):
    if path.suffix not in {'.docx', '.xlsx', '.json', '.md'}:
        continue
    shutil.copyfile(path, out / path.name)
    raw = path.read_bytes()
    entry = {'file': path.name, 'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw)}
    if path.suffix in {'.docx', '.xlsx'}:
        with zipfile.ZipFile(path) as archive:
            if path.suffix == '.docx':
                tree = ET.fromstring(archive.read('word/document.xml'))
                ns = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
                text = '\n'.join(''.join(p.itertext()) for p in tree.iter(ns + 'p'))
            else:
                ns = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
                strings = []
                if 'xl/sharedStrings.xml' in archive.namelist():
                    strings = [''.join(t.itertext()) for t in ET.fromstring(archive.read('xl/sharedStrings.xml')).iter(ns + 'si')]
                sheets = []
                for name in sorted(archive.namelist()):
                    if not re.fullmatch(r'xl/worksheets/sheet\d+.xml', name):
                        continue
                    rows = []
                    for row in ET.fromstring(archive.read(name)).iter(ns + 'row'):
                        cells = []
                        for cell in row:
                            value = cell.find(ns + 'v')
                            if cell.get('t') == 's' and value is not None:
                                cells.append(strings[int(value.text)])
                            else:
                                cells.append(''.join(cell.itertext()))
                        rows.append('\t'.join(cells))
                    sheets.append(name + '\n' + '\n'.join(rows))
                text = '\n\n'.join(sheets)
        (out / (path.stem + '.txt')).write_text(text)
    else:
        text = raw.decode()
    entry['requirement_ids'] = sorted(set(re.findall(r'\b[A-Z0-9]{2,3}-\d{2}\b', text)))
    manifest.append(entry)
canonical = json.loads((source / 'SubsidiaryVault_KMS_346_Requirements.json').read_text())
features = [f for m in canonical['modules'] for f in m['features']]
ids = {f['id'] for f in features}
assert len(features) == len(ids) == 346
assert len(canonical['modules']) == 22
target = ROOT / 'docs/requirements'
(target / 'canonical-requirements.json').write_text(json.dumps(canonical, ensure_ascii=False, indent=2) + '\n')
for entry in manifest:
    entry['canonical_ids_found'] = len(ids.intersection(entry['requirement_ids']))
(out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
master = (source / 'SubsidiaryVault_KMS_MASTER_IMPLEMENTATION_PROMPT.md').read_text()
assert len(re.findall(r'^### R\d{2}\. ', master, re.M)) == 44
assert len(re.findall(r'^### UXROLE-\d{2}\. ', master, re.M)) == 26
print(json.dumps({'features': len(ids), 'modules': len(canonical['modules']), 'specialist_functions': 44, 'runtime_personas': 26, 'sources': [{k:v for k,v in e.items() if k != 'requirement_ids'} for e in manifest]}, indent=2))
