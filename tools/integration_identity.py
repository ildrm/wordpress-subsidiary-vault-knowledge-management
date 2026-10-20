"""Real Keycloak protocol tests using only generated synthetic identities in a new test realm."""
import base64
import hashlib
import http.cookiejar
import json
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

def run(root,work,state,env):
    base=f"http://127.0.0.1:{state['IT_IDENTITY_PORT']}"
    counts=0
    def check(value,reason):
        nonlocal counts
        counts+=1
        if not value:raise RuntimeError(reason)
    def request(url,data=None,admin=None,method=None):
        headers={}
        if admin:headers['Authorization']='Bearer '+admin
        if isinstance(data,dict):body=json.dumps(data).encode();headers['Content-Type']='application/json'
        elif data is not None:body=data.encode();headers['Content-Type']='application/x-www-form-urlencoded'
        else:body=None
        req=urllib.request.Request(url,data=body,headers=headers,method=method or ('POST' if data is not None else 'GET'))
        try:r=urllib.request.urlopen(req,timeout=15)
        except urllib.error.HTTPError as e:r=e
        raw=r.read()
        try:payload=json.loads(raw) if raw else {}
        except ValueError:payload={}
        return r.status,payload
    status,result=request(base+'/realms/master/protocol/openid-connect/token',urllib.parse.urlencode({'grant_type':'password','client_id':'admin-cli','username':'integration-admin','password':state['IT_IDENTITY_SECRET']}))
    check(status==200,'Keycloak test administration authentication failed');admin=result['access_token']
    realm='svkms-it-'+secrets.token_hex(5);issuer=base+'/realms/'+realm;password=secrets.token_urlsafe(24);secret=secrets.token_urlsafe(32)
    redirect='http://127.0.0.1:8088/auth/callback'
    status,_=request(base+'/admin/realms',{'realm':realm,'enabled':True,'sslRequired':'none','registrationAllowed':False,'resetPasswordAllowed':False,'revokeRefreshToken':True,'refreshTokenMaxReuse':0,'accessTokenLifespan':60},admin)
    check(status==201,'create isolated Keycloak realm')
    api=base+'/admin/realms/'+realm
    for data in [
        {'clientId':'svkms-web','enabled':True,'publicClient':True,'standardFlowEnabled':True,'directAccessGrantsEnabled':False,'redirectUris':[redirect],'webOrigins':['http://127.0.0.1:8088'],'attributes':{'pkce.code.challenge.method':'S256'},'protocolMappers':[{'name':'vault-audience','protocol':'openid-connect','protocolMapper':'oidc-audience-mapper','config':{'included.custom.audience':'svkms-api','access.token.claim':'true','id.token.claim':'false'}}]},
        {'clientId':'svkms-api','enabled':True,'publicClient':False,'secret':secret,'standardFlowEnabled':False,'directAccessGrantsEnabled':False},
    ]:
        status,_=request(api+'/clients',data,admin);check(status==201,'register scoped identity client')
    username='synthetic-custodian'
    status,_=request(api+'/users',{'username':username,'enabled':True,'firstName':'Synthetic','lastName':'Custodian','email':'synthetic@example.invalid','emailVerified':True,'credentials':[{'type':'password','value':password,'temporary':False}]},admin)
    check(status==201,'create synthetic identity')
    status,users=request(api+'/users?username='+username,admin=admin);check(status==200 and len(users)==1,'resolve independent subject');subject=users[0]['id']
    status,discovery=request(issuer+'/.well-known/openid-configuration');check(status==200,'identity discovery')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,req,fp,code,msg,headers,newurl):return None
    class LoginForm(HTMLParser):
        def __init__(self):super().__init__();self.action=None;self.hidden={}
        def handle_starttag(self,tag,attrs):
            attrs=dict(attrs)
            if tag=='form' and attrs.get('id')=='kc-form-login' and 'action' in attrs:self.action=attrs['action']
            if tag=='input' and attrs.get('type')=='hidden' and 'name' in attrs:self.hidden[attrs['name']]=attrs.get('value','')
    def authorization_code():
        verifier=secrets.token_urlsafe(48);challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=');csrf=secrets.token_urlsafe(24)
        params=urllib.parse.urlencode({'client_id':'svkms-web','response_type':'code','scope':'openid profile','redirect_uri':redirect,'state':csrf,'code_challenge':challenge,'code_challenge_method':'S256'})
        jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect())
        with opener.open(discovery['authorization_endpoint']+'?'+params,timeout=15) as response:html=response.read().decode()
        form=LoginForm();form.feed(html);check(bool(form.action),'PKCE login form is present')
        check(form.action.startswith(issuer+'/'),'login form remains on the configured test issuer')
        # Browsers treat loopback as potentially trustworthy and send Keycloak's Secure cookies there.
        # urllib does not implement that loopback exception. This affects only this generated HTTP fixture;
        # application/staging TLS verification is never disabled.
        for cookie in jar:
            if cookie.domain in ['127.0.0.1','127.0.0.1.local']:cookie.secure=False
        payload=urllib.parse.urlencode({**form.hidden,'username':username,'password':password,'credentialId':''}).encode()
        try:response=opener.open(urllib.request.Request(form.action,data=payload),timeout=15)
        except urllib.error.HTTPError as error:response=error
        location=response.headers.get('Location','');p=urllib.parse.urlparse(location);q=urllib.parse.parse_qs(p.query)
        if not(response.status==302 and location.startswith(redirect+'?') and q.get('state')==[csrf] and q.get('iss')==[issuer] and 'code' in q):
            error_page=response.read().decode(errors='replace')
            (work/'identity-error.html').write_text(error_page)
            raise RuntimeError('PKCE login did not finish: HTTP '+str(response.status)+'; cookies '+str([(c.name,c.secure) for c in jar])+'; inspect ignored local identity-error.html')
        check(True,'PKCE redirect is issuer/state bound')
        return q['code'][0],verifier
    code,verifier=authorization_code()
    payload={'grant_type':'authorization_code','client_id':'svkms-web','code':code,'redirect_uri':redirect,'code_verifier':verifier}
    status,tokens=request(discovery['token_endpoint'],urllib.parse.urlencode(payload));check(status==200 and tokens.get('token_type')=='Bearer','real PKCE code exchange')
    # Provisioning is an explicit privileged issuer/subject binding, not email or WordPress account matching.
    operator={**env,'SV_ALLOW_INSECURE_TEST_ENDPOINTS':'1'}
    subprocess.run(['php','tools/identity.php','bind',issuer,subject,'tenant-01-custodian'],cwd=root,env={**operator,'SV_DATABASE_USER':'postgres','SV_DATABASE_PASSWORD':state['IT_DB_SECRET']},check=True)
    runtime={**env,'SV_ENV':'integration','SV_AUTH_MODE':'oidc-introspection','SV_ALLOW_INSECURE_TEST_ENDPOINTS':'1','SV_OIDC_ISSUER':issuer,'SV_OIDC_AUDIENCE':'svkms-api','SV_OIDC_WEB_CLIENT_ID':'svkms-web','SV_OIDC_INTROSPECTION_CLIENT_ID':'svkms-api','SV_OIDC_INTROSPECTION_CLIENT_SECRET':secret}
    tokenfile=work/'oidc-test-tokens.json';tokenfile.write_text(json.dumps(tokens));tokenfile.chmod(0o600)
    check_request=urllib.request.Request(discovery['introspection_endpoint'],data=urllib.parse.urlencode({'token':tokens['access_token']}).encode(),headers={'Content-Type':'application/x-www-form-urlencoded','Authorization':'Basic '+base64.b64encode(('svkms-api:'+secret).encode()).decode()})
    with urllib.request.urlopen(check_request,timeout=15) as reply:shape=json.load(reply)
    check(shape.get('active') is True,'fresh identity token is active before replay tests')
    (work/'identity-runtime.json').write_text(json.dumps({k:v for k,v in runtime.items() if k.startswith('SV_')}));(work/'identity-runtime.json').chmod(0o600)
    subprocess.run(['php','tests/oidc_test.php',str(tokenfile)],cwd=root,env=runtime,check=True)
    status,refreshed=request(discovery['token_endpoint'],urllib.parse.urlencode({'client_id':'svkms-web','grant_type':'refresh_token','refresh_token':tokens['refresh_token']}));check(status==200,'real identity refresh')
    status,_=request(discovery['token_endpoint'],urllib.parse.urlencode({'client_id':'svkms-web','grant_type':'refresh_token','refresh_token':tokens['refresh_token']}));check(status==400,'rotated refresh replay rejected')
    # Replay defenses may revoke the originating provider session. Use a separate session to prove explicit revocation.
    revoke_code,revoke_verifier=authorization_code()
    status,revoke_tokens=request(discovery['token_endpoint'],urllib.parse.urlencode({**payload,'code':revoke_code,'code_verifier':revoke_verifier}));check(status==200,'independent session for provider revocation')
    status,_=request(discovery['revocation_endpoint'],urllib.parse.urlencode({'client_id':'svkms-web','token':revoke_tokens['refresh_token'],'token_type_hint':'refresh_token'}));check(status==200,'identity refresh revocation')
    status,_=request(discovery['token_endpoint'],urllib.parse.urlencode({'client_id':'svkms-web','grant_type':'refresh_token','refresh_token':revoke_tokens['refresh_token']}));check(status==400,'revoked refresh rejected')
    status,_=request(discovery['token_endpoint'],urllib.parse.urlencode(payload));check(status==400,'authorization code replay rejected')
    code2,_=authorization_code();status,_=request(discovery['token_endpoint'],urllib.parse.urlencode({**payload,'code':code2,'code_verifier':'wrong-verifier-'+secrets.token_urlsafe(32)}));check(status==400,'wrong PKCE verifier rejected')
    tokenfile.unlink()
    print(f'Live Keycloak PKCE/refresh protocol: {counts} assertions passed')
    return runtime
