#!/usr/bin/env python3
"""Scan the release worktree without mounting git history, private fixtures or deployment secrets."""
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
IMAGE='ghcr.io/gitleaks/gitleaks@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f'
paths=subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT).split(b'\0')
with tempfile.TemporaryDirectory(prefix='svkms-source-scan-') as directory:
    target=Path(directory)
    for name in paths:
        if not name:continue
        relative=Path(name.decode());source=ROOT/relative
        if relative.parts[0] in ['var','dist','.git'] or source.is_symlink() or not source.is_file():continue
        dest=target/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
    subprocess.run(['docker','run','--rm','--network','none','--cap-drop','ALL','--security-opt','no-new-privileges','--read-only','-v',str(target)+':/source:ro',IMAGE,'dir','/source','--redact=100','--no-banner','--verbose'],check=True)
print('Pinned Gitleaks release-worktree scan passed; git history and ignored deployment state were outside scope.')
