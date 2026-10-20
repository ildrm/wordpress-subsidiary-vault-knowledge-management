#!/usr/bin/env python3
"""Produce an unsigned candidate source bundle, component inventory and file hashes."""
import hashlib
import json
import subprocess
import zipfile
from pathlib import Path
from verify_release import fingerprint
ROOT=Path(__file__).resolve().parents[1]
version=(ROOT/'VERSION').read_text().strip();out=ROOT/'dist';out.mkdir(exist_ok=True)
report=json.loads((ROOT/'docs/release/verification.json').read_text())
if not report.get('passed') or report.get('code_sha256')!=fingerprint():raise SystemExit('Candidate packaging denied: missing, failing or stale engineering verification.')
subprocess.run(['python3','tools/package.py'],cwd=ROOT,check=True)
files=[]
for directory in ['apps','services','tests','tools','infrastructure','packages','docs','.github']:
    files.extend(p for p in (ROOT/directory).rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.suffix not in ['.key','.pem','.sqlite','.log'])
files.extend(ROOT/n for n in ['README.md','LICENSE','VERSION','Makefile','SECURITY.md','CONTRIBUTING.md','CHANGELOG.md','.gitignore','.dockerignore','.env.example'])
archive=out/f'subsidiaryvault-kms-{version}-source.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
    for p in sorted(set(files)):
        if p.is_symlink():raise SystemExit('Symlinks require explicit package review')
        info=zipfile.ZipInfo('subsidiaryvault-kms/'+str(p.relative_to(ROOT)),(2026,10,20,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
images=sorted(set(line.split('image:',1)[1].strip() for line in (ROOT/'infrastructure/integration/compose.yaml').read_text().splitlines() if 'image:' in line))
inventory={'format':'development-component-inventory-v1','version':version,'scope':'Reference images and application source only; not a complete OS/transitive dependency SBOM','container_images':images,'php_runtime':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'framework_packages':[]}
(out/'component-inventory.json').write_text(json.dumps(inventory,indent=2)+'\n')
manifest={'version':version,'classification':'UNSIGNED RELEASE CANDIDATE; NOT APPROVED FOR PRODUCTION','code_sha256':report['code_sha256'],'verification_sha256':hashlib.sha256((ROOT/'docs/release/verification.json').read_bytes()).hexdigest(),'files':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in [archive,out/f'subsidiaryvault-kms-{version}.zip',out/'component-inventory.json']},'production_approved':False}
(out/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
(out/'SHA256SUMS').write_text(''.join(digest+'  '+name+'\n' for name,digest in manifest['files'].items()))
print(f'Packaged {version} candidate with current engineering evidence; external approval absent.')
