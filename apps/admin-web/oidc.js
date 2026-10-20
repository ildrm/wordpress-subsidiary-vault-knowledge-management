'use strict';
// Code + S256 PKCE; only a five-minute pending transaction uses sessionStorage.
// Access/refresh tokens stay in memory and never enter WordPress or browser storage.
window.VaultOidc = (() => {
  let config = null, refreshToken = '', refreshPromise = null, epoch = 0;
  const storageKey = 'svkms.pending-oidc';
  const b64url = (bytes) => btoa(String.fromCharCode(...bytes)).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
  const random = () => b64url(crypto.getRandomValues(new Uint8Array(32)));
  async function configuration() {
    if (!config) { const r = await fetch('/v1/auth/config',{cache:'no-store',credentials:'omit',redirect:'error'}); if (!r.ok) throw new Error('Independent identity configuration unavailable'); config = await r.json(); }
    return config;
  }
  async function exchange(parameters) {
    const currentEpoch = epoch;
    const c = await configuration();
    const r = await fetch(c.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...parameters,client_id:c.client_id}),credentials:'omit',cache:'no-store',redirect:'error'});
    const result = await r.json();
    if (currentEpoch !== epoch) throw new Error('stale_context');
    if (!r.ok || result.token_type !== 'Bearer' || typeof result.access_token !== 'string' || typeof result.expires_in !== 'number') { refreshToken = ''; throw new Error('Independent identity authentication failed'); }
    refreshToken = typeof result.refresh_token === 'string' ? result.refresh_token : '';
    return result.access_token;
  }
  async function start() {
    const c = await configuration(); if (!c.enabled) throw new Error('Independent sign-in is not configured');
    const verifier = random(), state = random(), redirect = location.origin + '/auth/callback';
    const challenge = b64url(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(verifier))));
    sessionStorage.setItem(storageKey,JSON.stringify({state,verifier,redirect,issuer:c.issuer,created:Date.now()}));
    const url = new URL(c.authorization_endpoint);
    url.search = new URLSearchParams({response_type:'code',client_id:c.client_id,redirect_uri:redirect,scope:c.scope,state,code_challenge:challenge,code_challenge_method:'S256',max_age:'300'}).toString();
    location.assign(url.href);
  }
  async function finish() {
    if (location.pathname !== '/auth/callback') return null;
    const params = new URLSearchParams(location.search), pending = sessionStorage.getItem(storageKey);
    sessionStorage.removeItem(storageKey); history.replaceState(null,'','/');
    const p = pending ? JSON.parse(pending) : null, c = await configuration();
    if (!p || !c.enabled || p.issuer !== c.issuer || params.get('iss') !== c.issuer || params.get('state') !== p.state || Date.now()-p.created > 300000 || Date.now() < p.created || !params.get('code') || params.has('error')) throw new Error('Independent sign-in state or issuer validation failed');
    return exchange({grant_type:'authorization_code',code:params.get('code'),redirect_uri:p.redirect,code_verifier:p.verifier});
  }
  async function refresh() {
    if (!refreshToken) return null;
    if (!refreshPromise) refreshPromise = exchange({grant_type:'refresh_token',refresh_token:refreshToken}).finally(() => { refreshPromise = null; });
    return refreshPromise;
  }
  async function revoke() {
    const current = refreshToken; refreshToken = ''; epoch++;
    if (!current) return;
    const c = await configuration();
    const r = await fetch(c.revocation_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({client_id:c.client_id,token:current,token_type_hint:'refresh_token'}),credentials:'omit',redirect:'error'});
    if (!r.ok) throw new Error('Local session revoked; provider refresh revocation could not be confirmed');
  }
  function clear() { refreshToken = ''; epoch++; }
  return { configuration, start, finish, refresh, revoke, clear };
})();
