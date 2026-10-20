#!/usr/bin/env python3
"""Owns only the synthetic svkms-release-it Compose project. Never touches other containers/volumes."""
import argparse
import json
import os
import secrets
import socket
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
WORK=ROOT/'var/release-integration'
STATE=WORK/'state.json'
COMPOSE=ROOT/'infrastructure/integration/compose.yaml'

def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def setup():
    WORK.mkdir(parents=True,exist_ok=True,mode=0o700)
    state=json.loads(STATE.read_text()) if STATE.exists() else {}
    for k in ['IT_DB_SECRET','IT_APP_SECRET','IT_WORKER_SECRET','IT_IDENTITY_SECRET','IT_BAO_SECRET','IT_S3_SECRET','IT_S3_ACCESS','IT_WP_SECRET','IT_WP_ROOT_SECRET','IT_WP_ADMIN_SECRET']:
        state.setdefault(k,secrets.token_hex(24))
    for name in ['PG','IDENTITY','BAO','S3','CLAMD','WP']:state.setdefault(f'IT_{name}_PORT',str(port()))
    STATE.write_text(json.dumps(state,indent=2)+'\n');STATE.chmod(0o600)
    (WORK/'.env').write_text(''.join(k+'='+v+'\n' for k,v in state.items()));(WORK/'.env').chmod(0o600)
    (WORK/'s3.json').write_text(json.dumps({'identities':[{'name':'integration-service','credentials':[{'accessKey':state['IT_S3_ACCESS'],'secretKey':state['IT_S3_SECRET']}],'actions':['Admin','Read','Write','List','Tagging']}]},indent=2));(WORK/'s3.json').chmod(0o600)
    (WORK/'clamd.conf').write_text('LogTime yes\nForeground yes\nLocalSocket /run/clamav/clamd.sock\nTCPSocket 3310\nTCPAddr 0.0.0.0\nUser clamav\nDatabaseDirectory /var/lib/clamav\nTemporaryDirectory /tmp\nStreamMaxLength 12M\nMaxFileSize 8M\nMaxScanSize 32M\nMaxRecursion 8\nAlertExceedsMax yes\nScanArchive yes\n')
    return state

def compose(*args,**kwargs):
    return subprocess.run(['docker','compose','--project-name','svkms-release-it','--env-file',str(WORK/'.env'),'-f',str(COMPOSE),*args],cwd=ROOT,check=True,**kwargs)

def wait_http(url):
    deadline=time.monotonic()+120
    while time.monotonic()<deadline:
        try:
            with urllib.request.urlopen(url,timeout=3) as r:
                if r.status==200:return
        except (OSError,urllib.error.HTTPError):pass
        time.sleep(1)
    raise RuntimeError('integration service readiness timeout')

def wait_scanner(state):
    # On ARM, the amd64 signature engine startup is CPU-heavy. Do not race identity tests with it.
    deadline=time.monotonic()+240
    while time.monotonic()<deadline:
        try:
            with socket.create_connection(('127.0.0.1',int(state['IT_CLAMD_PORT'])),timeout=3) as stream:
                stream.settimeout(3);stream.sendall(b'zPING\0')
                if b'PONG' in stream.recv(256):return
        except OSError:pass
        time.sleep(1)
    raise RuntimeError('real scanner readiness timeout; inspect definitions and available Docker memory')

def environment(state):
    return {**{k:v for k,v in os.environ.items() if not k.startswith('SV_')},'SV_ENV':'integration','SV_DATA_DIR':str(WORK/'data'),'SV_KEY_DIR':str(WORK/'keys'),'SV_DATABASE_DSN':f"pgsql:host=127.0.0.1;port={state['IT_PG_PORT']};dbname={state.get('IT_DATABASE_NAME','svkms')}",'SV_DATABASE_USER':'postgres','SV_DATABASE_PASSWORD':state['IT_DB_SECRET']}

def initialize_postgres(state):
    state['IT_DATABASE_NAME']='svkms_test_'+secrets.token_hex(6)
    compose('exec','-T','postgres','createdb','-U','postgres',state['IT_DATABASE_NAME'])
    env=environment(state)
    subprocess.run(['php','tools/vault.php','migrate'],cwd=ROOT,env=env,check=True)
    subprocess.run(['php','tools/vault.php','seed'],cwd=ROOT,env=env,check=True)
    # Passwords are generated hex data; they never come from user input or enter logs.
    sql=f"""DO $$ BEGIN
      IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='svkms_app') THEN CREATE ROLE svkms_app LOGIN PASSWORD '{state['IT_APP_SECRET']}' NOSUPERUSER NOBYPASSRLS; END IF;
      IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='svkms_worker') THEN CREATE ROLE svkms_worker LOGIN PASSWORD '{state['IT_WORKER_SECRET']}' NOSUPERUSER NOBYPASSRLS; END IF;
    END $$;
    GRANT USAGE ON SCHEMA public TO svkms_app,svkms_worker;
    GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO svkms_app,svkms_worker;
    REVOKE ALL ON schema_migrations FROM svkms_app,svkms_worker;
    REVOKE INSERT,UPDATE,DELETE ON users,external_identities FROM svkms_app,svkms_worker;
    GRANT SELECT ON schema_migrations TO svkms_app,svkms_worker;
    GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO svkms_app,svkms_worker;
    GRANT EXECUTE ON FUNCTION svkms_posture() TO svkms_app;
    GRANT EXECUTE ON FUNCTION svkms_pending_events() TO svkms_worker;
    """
    compose('exec','-T','postgres','psql','-v','ON_ERROR_STOP=1','-U','postgres','-d',state['IT_DATABASE_NAME'],input=sql,text=True,stdout=subprocess.DEVNULL)
    env['SV_DATABASE_USER']='svkms_app';env['SV_DATABASE_PASSWORD']=state['IT_APP_SECRET']
    subprocess.run(['php','tests/postgres_test.php'],cwd=ROOT,env=env,check=True)
    return state,env

def main():
    parser=argparse.ArgumentParser();parser.add_argument('command',choices=['up','test','down','wordpress']);args=parser.parse_args();state=setup()
    if args.command=='down':compose('down','--remove-orphans');return
    if args.command=='wordpress':
        from integration_wordpress import run
        run(ROOT,WORK,state,compose,wait_http);return
    compose('up','-d','--wait','postgres')
    compose('up','-d','identity','key-service','objects','scanner')
    wait_http(f"http://127.0.0.1:{state['IT_IDENTITY_PORT']}/realms/master/.well-known/openid-configuration")
    wait_http(f"http://127.0.0.1:{state['IT_BAO_PORT']}/v1/sys/health")
    wait_scanner(state)
    print('Isolated PostgreSQL, Keycloak and OpenBao services are ready; secrets remain in ignored private state.')
    if args.command=='test':
        state,env=initialize_postgres(state)
        from integration_identity import run
        run(ROOT,WORK,state,env)
        from integration_providers import run as providers
        providers(ROOT,WORK,state,env)
        from integration_wordpress import run as wordpress
        wordpress(ROOT,WORK,state,compose,wait_http)

if __name__=='__main__':main()
