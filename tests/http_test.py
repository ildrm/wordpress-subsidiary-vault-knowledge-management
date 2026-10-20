#!/usr/bin/env python3
"""Real HTTP transport checks against a disposable loopback server, never the user's development DB."""
import base64
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='svkms-http-') as tmp:
    env={**{k:v for k,v in os.environ.items() if not k.startswith('SV_')},'SV_ENV':'local','SV_AUTH_MODE':'development','SV_DATA_DIR':tmp+'/data','SV_KEY_DIR':tmp+'/keys'}
    for command in ['migrate','seed']:
        subprocess.run(['php','tools/vault.php',command],cwd=ROOT,env=env,check=True,stdout=subprocess.DEVNULL)
    tokens=json.loads(Path(tmp+'/data/demo-credentials.json').read_text())['tokens']
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
    log=open(tmp+'/http.log','w')
    process=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','services/core-api/public','services/core-api/public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
    origin=f'http://127.0.0.1:{port}'
    count=0
    def request(path,method='GET',user=None,data=None,raw=None,content_type='application/json'):
        headers={}
        if user:headers['Authorization']='Bearer '+tokens[user]
        body=json.dumps(data).encode() if data is not None else raw
        if body is not None:headers['Content-Type']=content_type
        req=urllib.request.Request(origin+path,data=body,headers=headers,method=method)
        try:r=urllib.request.urlopen(req,timeout=5)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.headers,r.read()
    def check(condition,message):
        global count
        count+=1
        assert condition,message
    try:
        for attempt in range(50):
            if process.poll() is not None:raise RuntimeError('Loopback HTTP server failed to start: '+Path(tmp+'/http.log').read_text())
            try:
                if request('/healthz')[0]==200:break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('HTTP startup timeout')
        status,h,body=request('/')
        check(status==200 and b'SubsidiaryVault' in body,'HTML entry')
        check(h.get('X-Frame-Options')=='DENY','frame defense')
        check("script-src 'self'" in h.get('Content-Security-Policy',''),'CSP')
        check('no-store' in h.get('Cache-Control',''),'no private caching')
        check(h.get('X-Robots-Tag')=='noindex, nofollow, noarchive','no indexing')
        for path in ['/app.js','/style.css','/locales.json']:check(request(path)[0]==200,'asset '+path)
        for path in ['/v1/me','/var/data/vault.sqlite','/.env','/docs/source/manifest.json','/../README.md']:
            check(request(path)[0] in [400,401],'nonpublic path '+path)
        status,_,body=request('/v1/tenants/tenant-01/documents',user='tenant-01-custodian')
        check(status==200,'list');doc=json.loads(body)['items'][0];path='/v1/tenants/tenant-01/documents/'+doc['id']
        check(request(path,user='tenant-02-custodian')[0]==403,'cross-tenant HTTP')
        check(request(path+'/versions/1',user='tenant-01-viewer')[0]==403,'view without download')
        status,_,body=request(path+'/versions/1',user='tenant-01-custodian')
        check(status==200 and b'Synthetic development' in base64.b64decode(json.loads(body)['content_base64']),'download decrypt')
        check(request('/v1/tenants/tenant-01/documents','POST','tenant-01-custodian',raw=b'{')[0]==400,'bad JSON')
        check(request('/v1/tenants/tenant-01/documents','POST','tenant-01-custodian',raw=b'[]')[0]==400,'JSON object required')
        check(request('/v1/tenants/tenant-01/documents','POST','tenant-01-custodian',raw=b'{}',content_type='text/plain')[0]==415,'JSON content type')
        check(request('/v1/me?token='+tokens['tenant-01-custodian'])[0]==401,'query token rejected')
        check(request('/v1/session','DELETE','tenant-01-viewer')[0]==200,'logout')
        check(request('/v1/me',user='tenant-01-viewer')[0]==401,'revoked session')
        print(f'HTTP transport: {count} assertions passed')
    finally:
        process.terminate()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait()
        log.close()
