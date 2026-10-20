#!/usr/bin/env python3
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
files=sorted(p for p in root.rglob('*.php') if not any(x in p.parts for x in ['var','vendor','.git']))
for path in files:
    subprocess.run(['php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
subprocess.run(['node','--check',str(root/'apps/admin-web/app.js')],check=True)
subprocess.run(['node','--check',str(root/'apps/admin-web/oidc.js')],check=True)
print(f'PHP syntax passed: {len(files)} files; JavaScript syntax passed')
