import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';

const code = readFileSync(new URL('../apps/admin-web/oidc.js',import.meta.url),'utf8');
let checks = 0;
const check = (value,message) => { checks++; assert.ok(value,message); };
function fixture() {
  const store = new Map(), calls = [], location = { origin:'https://vault.example.invalid',pathname:'/',search:'',assign(url) { this.assigned = url; } };
  const config = { enabled:true,issuer:'https://identity.example.invalid/realms/vault',client_id:'svkms-web',authorization_endpoint:'https://identity.example.invalid/auth',token_endpoint:'https://identity.example.invalid/token',revocation_endpoint:'https://identity.example.invalid/revoke',scope:'openid profile' };
  const sandbox = { window:{},URL,URLSearchParams,TextEncoder,crypto:webcrypto,btoa,Date,location,sessionStorage:{getItem:k => store.get(k) || null,setItem:(k,v) => store.set(k,v),removeItem:k => store.delete(k)},history:{replaceState(a,b,path) { location.pathname = path; location.search = ''; }},fetch:async (url,options = {}) => { calls.push({url,options});return {ok:true,json:async () => url === '/v1/auth/config' ? config : {token_type:'Bearer',access_token:'synthetic-access-token',refresh_token:'synthetic-refresh-token',expires_in:60}}; } };
  vm.runInNewContext(code,sandbox);
  return {client:sandbox.window.VaultOidc,sandbox,config,store,calls,location};
}
const f = fixture(); await f.client.start();
const authorization = new URL(f.location.assigned), pending = JSON.parse(f.store.get('svkms.pending-oidc'));
check(authorization.searchParams.get('code_challenge_method') === 'S256','PKCE S256 required');
check(authorization.searchParams.get('state') === pending.state && pending.state.length >= 40,'random state bound to pending transaction');
check(authorization.searchParams.get('redirect_uri') === 'https://vault.example.invalid/auth/callback','fixed current-origin callback');
const hash = Buffer.from(await webcrypto.subtle.digest('SHA-256',new TextEncoder().encode(pending.verifier))).toString('base64url');
check(hash === authorization.searchParams.get('code_challenge'),'actual SHA256 verifier challenge');
check(!JSON.stringify([...f.store]).includes('synthetic-access'),'no bearer token stored');
f.location.pathname = '/auth/callback'; f.location.search = new URLSearchParams({code:'synthetic-code',state:pending.state,iss:f.config.issuer}).toString();
check(await f.client.finish() === 'synthetic-access-token','valid callback exchanges code');
check(f.store.size === 0 && f.location.search === '' && f.location.pathname === '/','code and transaction removed after callback');
const request = f.calls.find(v => v.url === f.config.token_endpoint).options;
check(request.body.get('code_verifier') === pending.verifier && request.body.get('client_id') === f.config.client_id,'exact verifier and client used');
check(request.redirect === 'error' && request.credentials === 'omit','token transport rejects redirect and does not forward cookies');
for (const scenario of ['state','issuer','expired','future','missing']) {
  const x = fixture(); await x.client.start(); const p = JSON.parse(x.store.get('svkms.pending-oidc'));
  if (scenario === 'expired') p.created = Date.now()-300001;
  if (scenario === 'future') p.created = Date.now()+100000;
  x.store.set('svkms.pending-oidc',JSON.stringify(p)); if (scenario === 'missing') x.store.clear();
  x.location.pathname = '/auth/callback';x.location.search = new URLSearchParams({code:'code',state:scenario === 'state' ? 'wrong' : p.state,iss:scenario === 'issuer' ? 'https://attacker.invalid' : x.config.issuer}).toString();
  await assert.rejects(() => x.client.finish());check(!x.calls.some(v => v.url === x.config.token_endpoint),'invalid callback never contacts token endpoint: '+scenario);
  check(x.store.size === 0 && x.location.search === '','invalid callback also clears transaction/code');
}
f.location.pathname = '/auth/callback'; f.location.search = new URLSearchParams({code:'synthetic-code',state:pending.state,iss:f.config.issuer}).toString();
await assert.rejects(() => f.client.finish());check(f.store.size === 0,'callback replay rejected after transaction consumption');
await f.client.revoke();check(f.calls.some(v => v.url === f.config.revocation_endpoint && v.options.body.get('token_type_hint') === 'refresh_token'),'refresh token revoked separately');
check(await f.client.refresh() === null,'no refresh after logout');
console.log(`Browser PKCE state/issuer/storage checks: ${checks} assertions passed (DOM-independent unit harness).`);
