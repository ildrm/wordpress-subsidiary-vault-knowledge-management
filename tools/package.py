#!/usr/bin/env python3
"""Reproducible source ZIP; no runtime data, keys, credentials or unrelated files."""
import hashlib
import json
import zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'apps/wordpress-plugin/subsidiaryvault-kms'
out=root/'dist';out.mkdir(exist_ok=True)
target=out/'subsidiaryvault-kms-0.1.0.zip'
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(source.rglob('*')):
        if path.is_file():
            info=zipfile.ZipInfo(str(path.relative_to(source.parent)),date_time=(2026,10,20,0,0,0))
            info.compress_type=zipfile.ZIP_DEFLATED
            info.external_attr=0o100644<<16
            archive.writestr(info,path.read_bytes())
digest=hashlib.sha256(target.read_bytes()).hexdigest()
(out/'SHA256SUMS').write_text(f'{digest}  {target.name}\n')
print(f'Created {target.relative_to(root)}; SHA256 {digest}')
