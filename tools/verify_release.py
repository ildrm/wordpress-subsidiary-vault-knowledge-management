#!/usr/bin/env python3
"""Capture actual reproducible checks and bind the report to the tested code; never emits approvals."""
import hashlib
import json
import re
import subprocess
import sys
from datetime import datetime,timezone
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def fingerprint():
    paths=[]
    for directory in ['apps','services','tests','tools','infrastructure','packages','.github']:
        paths.extend(p for p in (ROOT/directory).rglob('*') if p.is_file() and '__pycache__' not in p.parts)
    paths.extend(ROOT/n for n in ['VERSION','Makefile','.dockerignore','.env.example'])
    digest=hashlib.sha256()
    for p in sorted(paths):
        digest.update(str(p.relative_to(ROOT)).encode()+b'\0'+hashlib.sha256(p.read_bytes()).digest())
    return digest.hexdigest()

def main():
    before=fingerprint();report={'version':(ROOT/'VERSION').read_text().strip(),'scope':'INTERNAL ENGINEERING EVIDENCE; no independent approval','started_at':datetime.now(timezone.utc).isoformat(),'code_sha256':before,'checks':[]}
    commands=[['make','validate']]
    if '--integration' in sys.argv:commands.append(['python3','tools/integration.py','test'])
    if '--secret-scan' in sys.argv:commands.append(['python3','tools/secret_scan.py'])
    logs=[]
    for args in commands:
        result=subprocess.run(args,cwd=ROOT,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
        logs.append('$ '+' '.join(args)+'\n'+result.stdout)
        counts=[int(n) for n in re.findall(r'(\d+) assertions passed',result.stdout)]
        entry={'command':args,'exit_code':result.returncode,'assertions':sum(counts)};report['checks'].append(entry)
        print(json.dumps(entry),flush=True)
        if result.returncode!=0:break
    report['code_unchanged_during_checks']=before==fingerprint()
    report['passed']=report['code_unchanged_during_checks'] and len(report['checks'])==len(commands) and all(c['exit_code']==0 for c in report['checks'])
    report['completed_at']=datetime.now(timezone.utc).isoformat()
    out=ROOT/'docs/release';out.mkdir(exist_ok=True)
    (out/'verification.json').write_text(json.dumps(report,indent=2)+'\n')
    (out/'verification.log').write_text('\n'.join(logs))
    print('Captured verification; production approval is a separate gate.')
    sys.exit(0 if report['passed'] else 1)

if __name__=='__main__':main()
