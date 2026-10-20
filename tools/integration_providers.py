"""Initialize least-privilege OpenBao fixtures and run real key/object/scanner checks."""
import hashlib
import json
import secrets
import socket
import subprocess
import time
import urllib.error
import urllib.request

def run(root,work,state,env):
    base=f"http://127.0.0.1:{state['IT_BAO_PORT']}";admin=state['IT_BAO_SECRET']
    def request(path,body,token=admin):
        req=urllib.request.Request(base+'/v1/'+path,data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-Vault-Token':token},method='POST')
        try:r=urllib.request.urlopen(req,timeout=15)
        except urllib.error.HTTPError as e:r=e
        raw=r.read()
        return r.status,json.loads(raw) if raw else {}
    status,_=request('sys/mounts/transit',{'type':'transit'})
    if status not in [204,400]:raise RuntimeError('transit mount failed')
    policy=[]
    for i in range(1,11):
        name='svkms-'+hashlib.sha256(f'tenant-{i:02d}'.encode()).hexdigest()
        status,_=request('transit/keys/'+name,{'type':'aes256-gcm96','exportable':False,'allow_plaintext_backup':False})
        if status not in [200,204]:raise RuntimeError('tenant transit key creation failed')
        policy.extend([f'path "transit/{op}/{name}" {{ capabilities = ["{cap}"] }}' for op,cap in [('encrypt','update'),('decrypt','update'),('keys','read')]])
    name='it-'+secrets.token_hex(6)
    status,_=request('sys/policies/acl/'+name,{'policy':'\n'.join(policy)})
    if status!=204:raise RuntimeError('scoped key policy failed')
    status,result=request('auth/token/create',{'policies':[name],'no_default_policy':True,'ttl':'30m'})
    if status!=200:raise RuntimeError('scoped key token creation failed')
    tokenfile=work/'key-service.token';tokenfile.write_text(result['auth']['client_token']);tokenfile.chmod(0o600)
    provider={**env,'SV_KEY_PROVIDER':'openbao-transit','SV_KEY_SERVICE_URL':base,'SV_KEY_SERVICE_TOKEN_FILE':str(tokenfile),'SV_ALLOW_INSECURE_TEST_ENDPOINTS':'1','SV_OBJECT_PROVIDER':'s3','SV_S3_ENDPOINT':f"http://127.0.0.1:{state['IT_S3_PORT']}",'SV_S3_REGION':'us-east-1','SV_S3_BUCKET':'svkms-it-'+secrets.token_hex(5),'SV_S3_ACCESS_KEY':state['IT_S3_ACCESS'],'SV_S3_SECRET_KEY':state['IT_S3_SECRET'],'SV_UPLOAD_PROFILE':'scanned','SV_CLAMD_ADDRESS':f"tcp://127.0.0.1:{state['IT_CLAMD_PORT']}"}
    deadline=time.monotonic()+120
    while time.monotonic()<deadline:
        try:
            with socket.create_connection(('127.0.0.1',int(state['IT_CLAMD_PORT'])),timeout=3) as s:
                s.sendall(b'zPING\x00')
                if b'PONG' in s.recv(256):break
        except OSError:pass
        time.sleep(1)
    else:raise RuntimeError('real scanner readiness failed')
    (work/'provider-runtime.json').write_text(json.dumps({k:v for k,v in provider.items() if k.startswith('SV_')}));(work/'provider-runtime.json').chmod(0o600)
    subprocess.run(['php','tests/providers_test.php'],cwd=root,env=provider,check=True)
    # Rotate the independent wrapping root; old envelopes must still decrypt at their recorded version.
    key='svkms-'+hashlib.sha256(b'tenant-01').hexdigest()
    status,_=request('transit/keys/'+key+'/rotate',{})
    if status not in [200,204]:raise RuntimeError('key rotation failed')
    subprocess.run(['php','tests/providers_test.php','verify-rotation'],cwd=root,env=provider,check=True)
    print('Live managed providers: real OpenBao transit, signed S3 requests and ClamAV INSTREAM exercised; integration fixtures are not a production KMS/HSM or storage certification.')
    return provider
