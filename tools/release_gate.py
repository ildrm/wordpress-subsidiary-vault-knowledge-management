#!/usr/bin/env python3
"""Development checks are distinct from the fail-closed production release decision."""
import csv
import json
import sys
import zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
version=(ROOT/'VERSION').read_text().strip()
rows=list(csv.DictReader((ROOT/'docs/requirements/traceability.csv').open()))
incomplete=[r['id'] for r in rows if r['status']!='VERIFIED']
if '--development' in sys.argv:
    assert len(rows)==346
    with zipfile.ZipFile(ROOT/f'dist/subsidiaryvault-kms-{version}.zip') as z:
        names=z.namelist()
        assert all(n.startswith('subsidiaryvault-kms/') for n in names)
        assert not any(n.endswith(('.key','.pem','.sqlite','.env')) or '/var/' in n for n in names)
        assert 'subsidiaryvault-kms/subsidiaryvault-kms.php' in names
    print('Development package gate passed. Production release remains blocked.')
    sys.exit(0)
report={'release':'DENIED','version':version,'unverified_requirements':len(incomplete),'software_gaps':str(ROOT/'docs/requirements/gap-register.md'),'missing_external_gates':['independent security assessment','cryptographic protocol/client assessment','privacy/legal/records approvals','accessibility and localization acceptance','production infrastructure validation','disaster recovery and capacity evidence','subsidiary pilot and accountable human go/no-go']}
print(json.dumps(report,indent=2))
sys.exit(1)
