'use strict';
const $ = (selector) => document.querySelector(selector);
const state = { token: '', tenant: '', page: 'documents', generation: 0, me: null, locale: 'en', catalogs: {}, doc: null, offset: 0 };
const names = { en:'English', fa:'فارسی', fr:'Français', tr:'Türkçe', ru:'Русский', 'zh-Hans':'简体中文', de:'Deutsch', pt:'Português', es:'Español', hi:'हिन्दी', ja:'日本語', ar:'العربية', hy:'Հայերեն' };
function el(tag, text, cls) { const node = document.createElement(tag); if (text !== undefined) node.textContent = String(text); if (cls) node.className = cls; return node; }
function t(key) { return state.catalogs[state.locale]?.[key] || state.catalogs.en?.[key] || key; }
function message(value, error = false) { $('#message').textContent = value; $('#message').className = error ? 'error' : ''; }
function button(text, action, cls) { const b = el('button', text, cls); b.type = 'button'; b.addEventListener('click', () => safely(action)); return b; }
async function safely(action) { try { await action(); } catch (e) { if (e.message === 'stale_context') return; message(e.message, true); } }
async function api(path, method = 'GET', body, retry = true) {
  const generation = state.generation;
  const response = await fetch(path, { method, headers: { Authorization: `Bearer ${state.token}`, ...(body === undefined ? {} : { 'Content-Type':'application/json' }) }, body: body === undefined ? undefined : JSON.stringify(body), cache: 'no-store', credentials: 'omit', redirect: 'error' });
  const result = await response.json();
  if (generation !== state.generation) throw new Error('stale_context');
  if (!response.ok) {
    if (response.status === 401 && retry) { const token = await VaultOidc.refresh(); if (generation !== state.generation) throw new Error('stale_context'); if (token) { state.token = token; return api(path,method,body,false); } }
    if (response.status === 401) logout(false); throw new Error(`${result.error} · ${result.request_id || response.status}`);
  }
  return result;
}
function base() { return `/v1/tenants/${encodeURIComponent(state.tenant)}`; }
function docPath(doc = state.doc) { return `${base()}/${doc.kind === 'article' ? 'knowledge' : 'documents'}/${encodeURIComponent(doc.id)}`; }
function date(seconds) { return new Intl.DateTimeFormat(state.locale, { dateStyle:'medium', timeStyle:'short' }).format(new Date(seconds * 1000)); }
function badge(text) { return el('span', t(text), `badge ${text}`); }
function clearContext() { state.generation++; state.detailGeneration = (state.detailGeneration || 0) + 1; state.doc = null; state.offset = 0; $('#detail').close(); $('#detail-body').replaceChildren(); $('#page-body').replaceChildren(); $('#page-actions').replaceChildren(); message(''); }
function logout(revoke = true) { if (revoke && state.token) { fetch('/v1/session', { method:'DELETE', headers:{Authorization:`Bearer ${state.token}`}, credentials:'omit' }).catch(() => message('Server session revocation could not be confirmed.',true)); VaultOidc.revoke().catch((e) => message(e.message,true)); } else VaultOidc.clear(); clearContext(); state.token = ''; state.tenant = ''; state.me = null; $('#token').value = ''; $('#login').hidden = false; $('#workspace').hidden = true; $('#logout').hidden = true; $('#tenant').replaceChildren(el('option', 'Sign in to select a subsidiary')); $('#tenant').disabled = true; $('#identity').textContent = 'Guest'; $('#role').textContent = 'Independent sign-in required'; }
$('#logout').addEventListener('click', () => logout());
$('#close-detail').addEventListener('click', () => { $('#detail').close(); state.doc = null; state.detailGeneration = (state.detailGeneration || 0) + 1; });
$('#login-form').addEventListener('submit', (e) => { e.preventDefault(); safely(async () => {
  clearContext(); state.token = $('#token').value.trim(); $('#token').value = '';
  await openSession();
}); });
async function openSession() {
  state.me = await api('/v1/me');
  $('#identity').textContent = state.me.name; $('#logout').hidden = false;
  const tenants = state.me.tenants.filter((v) => v.status === 'active');
  $('#tenant').replaceChildren(...tenants.map((tenant) => { const o = el('option', tenant.name); o.value = tenant.id; return o; }));
  state.tenant = tenants[0]?.id || ''; $('#tenant').disabled = !state.tenant;
  $('#login').hidden = true; $('#workspace').hidden = false;
  state.page = state.tenant === 'holding' ? 'posture' : 'documents';
  await render();
}
$('#oidc-login').addEventListener('click',() => safely(() => VaultOidc.start()));
safely(async () => { const c = await VaultOidc.configuration(); state.uploadProfile = c.upload_profile || 'text'; state.protectionMode = c.mode || 'managed-local'; $('#oidc-login').hidden = !c.enabled; if (c.enabled) $('#login-form').hidden = true; const token = await VaultOidc.finish(); if (token) { clearContext(); state.token = token; await openSession(); } });
$('#tenant').addEventListener('change', () => safely(async () => { clearContext(); state.tenant = $('#tenant').value; await render(); }));
$('#navigation').addEventListener('click', (e) => { const b = e.target.closest('button[data-page]'); if (!b) return; if (!state.token) { $('#token').focus(); return; } clearContext(); state.page = b.dataset.page; safely(render); });
$('#language').addEventListener('change', () => safely(async () => { state.locale = $('#language').value; applyLanguage(); if (state.token) { clearContext(); await render(); } }));
function applyLanguage() { document.documentElement.lang = state.locale; document.documentElement.dir = ['fa','ar'].includes(state.locale) ? 'rtl' : 'ltr'; document.querySelectorAll('[data-i18n]').forEach((n) => { n.textContent = t(n.dataset.i18n); }); }
function field(form, name, label, options = {}) { const id = `f-${Math.random().toString(36).slice(2)}`; const l = el('label', label); l.htmlFor = id; const input = options.multiline ? el('textarea') : options.choices ? el('select') : el('input'); input.id = id; input.name = name; if (options.choices) options.choices.forEach((c) => { const o = el('option', c.label || c); o.value = c.value || c; input.append(o); }); else if (!options.multiline) input.type = options.type || 'text'; input.required = options.required !== false; if (options.value !== undefined) input.value = options.value; if (options.max) input.maxLength = options.max; form.append(l, input); return input; }
function form(submitLabel, action) { const f = el('form'); const submit = el('button', submitLabel, 'primary'); submit.type = 'submit'; f.addEventListener('submit', (e) => { e.preventDefault(); safely(async () => { submit.disabled = true; try { await action(new FormData(f), f); } finally { submit.disabled = false; } }); }); f.submitButton = submit; return f; }
function section(title) { const s = el('details', undefined, 'section'); s.append(el('summary', title)); return s; }
function table(headers, rows) { const wrap = el('div', undefined, 'table-scroll'); const table = el('table'); const head = el('thead'); const tr = el('tr'); headers.forEach((h) => tr.append(el('th', h))); head.append(tr); table.append(head); const body = el('tbody'); rows.forEach((row) => { const tr = el('tr'); row.forEach((value) => { const td = el('td'); td.append(value instanceof Node ? value : el('bdi', value ?? '—')); tr.append(td); }); body.append(tr); }); table.append(body); wrap.append(table); return wrap; }
async function render() {
  $('#page-body').replaceChildren(); $('#page-actions').replaceChildren(); message('');
  $('#page-title').textContent = t(state.page); $('#crumb').textContent = t(state.page);
  $('#role').textContent = state.me?.tenants.find((v) => v.id === state.tenant)?.role_id || 'No active membership';
  document.querySelectorAll('nav button').forEach((b) => { const active = b.dataset.page === state.page; b.classList.toggle('active', active); if (active) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
  $('#eyebrow').textContent = state.tenant === 'holding' ? 'GROUP GOVERNANCE' : 'YOUR PRIVATE WORKSPACE';
  $('#page-description').textContent = ['documents','knowledge'].includes(state.page) ? 'Controlled versions. Explicit access. A reliable record.' : 'Scoped to your current membership and permissions.';
  if (!state.tenant) { message('No active subsidiary memberships.'); return; }
  if (['documents','knowledge'].includes(state.page)) return renderDocuments();
  if (state.page === 'posture') { const result = await api('/v1/posture'); $('#page-body').append(table(['Subsidiary','Status','Documents','Stored bytes','Access'], result.tenants.map((r) => [r.name,badge(r.status),r.documents,r.bytes,button(r.status === 'active' ? 'Suspend' : 'Reactivate', async () => { await api(`/v1/governance/tenants/${r.id}`, 'PATCH', {status:r.status === 'active' ? 'suspended' : 'active'}); await render(); })]))); return; }
  if (state.page === 'settings') return renderSettings();
  const result = await api(`${base()}/${state.page}`);
  if (state.page === 'audit') { $('#page-body').append(el('p', result.chain_valid ? 'Local audit chain verified. External anchoring is required to detect a privileged full-chain rewrite.' : 'Local audit verification FAILED.', 'hint')); $('#page-body').append(table(['Time','Action','Actor','Outcome','Resource'], result.items.map((r) => [date(r.at),r.action,r.actor_id,r.outcome,r.resource_id]))); if (result.items.length === 100) $('#page-body').append(button('Load later events', async () => { const next = await api(`${base()}/audit?after=${result.items.at(-1).sequence}`); $('#page-body').append(table(['Time','Action','Actor','Outcome'], next.items.map((r) => [date(r.at),r.action,r.actor_id,r.outcome]))); })); return; }
  if (state.page === 'notifications') { $('#page-body').append(table(['Time','Event','Document','Read'], result.items.map((r) => [date(r.created_at),r.event,r.resource_id,r.read_at ? 'Read' : button('Mark read', async () => { await api(`${base()}/notifications/${r.id}`, 'PATCH', {}); await render(); })]))); return; }
  if (state.page === 'members') { $('#page-body').append(table(['Person','Role','Clearance','Status','Membership'], result.items.map((r) => [r.name,r.role_id,r.clearance,badge(r.status),button(r.status === 'active' ? 'Suspend' : 'Reactivate', async () => { await api(`${base()}/members/${r.user_id}`, 'PATCH', {status:r.status === 'active' ? 'suspended' : 'active'}); await render(); })]))); return; }
  if (state.page === 'roles') {
    $('#page-body').append(table(['Role','Capabilities','Version'], result.items.map((r) => [r.name,JSON.parse(r.capabilities).join(', ') || 'None',r.version])));
    const f = form('Create role', async (data) => { await api(`${base()}/roles`, 'POST', {name:data.get('name'),capabilities:data.getAll('capabilities')}); await render(); }); field(f,'name','Role name',{max:120});
    const caps = el('div', undefined, 'checklist'); ['create','members','roles','groups','settings','audit','view','download','edit','comment','share','delete','approve','records','hold'].forEach((cap) => { const l = el('label'); const input = el('input'); input.type = 'checkbox'; input.name = 'capabilities'; input.value = cap; l.append(input,el('span',cap)); caps.append(l); }); f.append(caps,f.submitButton); f.className = 'form-card'; $('#page-body').append(f); return;
  }
  if (['groups','departments'].includes(state.page)) {
    $('#page-body').append(table(['Name','Identifier'], result.items.map((r) => [r.name,r.id])));
    const f = form(`Create ${state.page === 'groups' ? 'team' : 'department'}`, async (data) => { await api(`${base()}/${state.page}`, 'POST', {name:data.get('name')}); await render(); }); field(f,'name','Name',{max:120}); f.append(f.submitButton); f.className = 'form-card'; $('#page-body').append(f);
    if (state.page === 'groups' && result.items.length) { const m = form('Add member to team', async (data) => { await api(`${base()}/groups/${data.get('group')}/members`, 'POST', {user_id:data.get('user')}); message('Team membership updated.'); }); field(m,'group','Team',{choices:result.items.map((g) => ({label:g.name,value:g.id}))}); field(m,'user','Existing tenant user ID'); m.append(m.submitButton); m.className = 'form-card'; $('#page-body').append(m); }
  }
}
async function renderDocuments(query = '') {
  const result = await api(`${base()}/${state.page}?q=${encodeURIComponent(query)}&after=${state.offset}`);
  const root = $('#page-body'); root.replaceChildren(); $('#page-actions').replaceChildren(button(`+ ${t('create')}`, () => createDocument(), 'primary'));
  const stats = el('div', undefined, 'stats'); [['Visible on this page',result.items.length,'Authorization checked on every request'],['Current workspace',state.tenant,'Independent subsidiary membership'],['Protection profile',state.protectionMode === 'managed-external' ? 'Managed external' : 'Managed local',state.protectionMode === 'managed-external' ? 'Remote encrypted objects · external key custody' : 'Encrypted objects · separate key directory']].forEach(([label,value,note]) => { const box = el('div', undefined, 'stat'); box.append(el('small',label),el('strong',value),el('span',note)); stats.append(box); }); root.append(stats);
  const panel = el('div', undefined, 'panel'); const bar = el('form', undefined, 'panel-toolbar'); const input = el('input'); input.type = 'search'; input.placeholder = t('search'); input.setAttribute('aria-label',t('search')); input.value = query; const search = el('button',t('search')); search.type = 'submit'; bar.append(el('strong', t(state.page)),input,search); bar.addEventListener('submit', (e) => { e.preventDefault(); state.offset = 0; safely(() => renderDocuments(input.value)); }); panel.append(bar);
  if (!result.items.length) { const empty = el('div', undefined, 'empty'); empty.append(el('h2','A place for trusted knowledge'),el('p','Create a document or ask a custodian for an explicit access grant.')); panel.append(empty); }
  else panel.append(table([t('title'),t('state'),t('version'),t('classification'),t('updated')],result.items.map((doc) => { const link = button(doc.title, () => openDocument(doc), 'doc-link'); const title = el('span'); title.append(el('span',doc.kind === 'article' ? '◇' : '▤','doc-icon'),link); return [title,badge(doc.state),`v${doc.current_version}`,['Internal','Confidential','Restricted','Critical'][doc.classification],date(doc.updated_at)]; })));
  root.append(panel);
  if (result.next_offset !== null) root.append(button('Next page', async () => { state.offset = result.next_offset; await renderDocuments(query); }));
  if (state.offset) root.append(button('First page', async () => { state.offset = 0; await renderDocuments(query); }));
  root.append(el('p','Only explicitly authorized documents appear here. Search covers visible titles and metadata; document content is not indexed.','hint'));
}
function createDocument() {
  $('#detail-title').textContent = t('create'); const root = $('#detail-body'); root.replaceChildren();
  const f = form(t('create'), async (data) => {
    const file = data.get('file'); let bytes = new TextEncoder().encode(data.get('content') || '');
    if (file?.size) { if (file.size > 8388608) throw new Error('File exceeds 8 MiB limit'); bytes = new Uint8Array(await file.arrayBuffer()); } let binary = ''; for (const b of bytes) binary += String.fromCharCode(b);
    await api(`${base()}/${state.page}`, 'POST', { title:data.get('title'),classification:Number(data.get('classification')),metadata:{category:data.get('category') || 'General'},content_base64:btoa(binary) }); $('#detail').close(); await render(); message('Document created with an encrypted immutable version.');
  });
  field(f,'title',t('title'),{max:240}); field(f,'classification',t('classification'),{choices:[{label:'Internal',value:'0'},{label:'Confidential',value:'1'},{label:'Restricted',value:'2'},{label:'Critical',value:'3'}]}); field(f,'category','Category',{required:false,max:100}); field(f,'content','UTF-8 text content',{multiline:true,required:false}); const file = field(f,'file','Or select a file allowed by the upload profile (8 MiB maximum)',{type:'file',required:false}); file.accept = state.uploadProfile === 'scanned' ? '.txt,.md,.csv,.json,.pdf,.png,.jpg,.jpeg' : '.txt,.md,.csv,.json'; f.append(el('p','Uploads follow the server profile: text-only locally, or approved types scanned by the independent malware service. Zero-knowledge mode remains unavailable.','hint'),f.submitButton); root.append(f); $('#detail').showModal();
}
async function openDocument(doc) {
  const path = docPath(doc); const ticket = state.detailGeneration = (state.detailGeneration || 0) + 1; const current = await api(path); if (ticket !== state.detailGeneration) return; state.doc = current;
  const versions = await api(`${path}/versions`); const workflow = await api(`${path}/workflow`); const comments = await api(`${path}/comments`);
  if (ticket !== state.detailGeneration) return; $('#detail-title').textContent = current.title; const root = $('#detail-body'); root.replaceChildren();
  const meta = el('div',undefined,'detail-meta'); meta.append(badge(current.state),el('span',`Version ${current.current_version}`),el('bdi',current.id)); root.append(meta);
  const permissions = el('div',undefined,'permission-list'); Object.entries(current.permissions).filter(([,v]) => v).forEach(([k]) => permissions.append(el('span',k))); root.append(permissions);
  const actions = el('div',undefined,'detail-actions'); root.append(actions);
  async function reload() { await openDocument(current); }
  if (current.permissions.download) actions.append(button('Download current text', () => download(current.current_version)));
  if (current.permissions.edit && ['draft','rejected'].includes(current.state)) actions.append(button('Submit for approval', async () => { await api(`${path}/workflow`,'POST',{decision:'submit',expected_version:current.current_version}); await reload(); }));
  if (current.permissions.approve && current.state === 'in_review') ['approve','reject'].forEach((decision) => actions.append(button(decision === 'approve' ? 'Approve version' : 'Reject version', async () => { await api(`${path}/workflow`,'POST',{decision,expected_version:current.current_version}); await reload(); })));
  actions.append(button('Bookmark', async () => { await api(`${path}/bookmark`,'POST',{}); message('Bookmark saved.'); }));
  const history = section('Version history'); history.append(table(['Version','Created','Author','Bytes','Action'],versions.items.map((v) => [v.number,date(v.created_at),v.author_id,v.bytes,current.permissions.download ? button('Download',() => download(v.number)) : 'View only']))); root.append(history);
  if (workflow.items.length) { const w = section('Approval evidence'); w.append(table(['Version','State','Requested by','Decided by'],workflow.items.map((v) => [v.version,v.state,v.requested_by,v.decided_by]))); root.append(w); }
  if (current.permissions.edit && !current.is_record && ['draft','rejected'].includes(current.state)) {
    const edit = section('Edit metadata'); const f = form('Save metadata',async (data) => { await api(path,'PATCH',{title:data.get('title'),metadata:{...current.metadata,category:data.get('category')},expected_version:current.current_version,expected_metadata_revision:current.metadata_revision}); await reload(); }); field(f,'title','Title',{value:current.title,max:240}); field(f,'category','Category',{value:current.metadata.category || 'General',max:100}); f.append(f.submitButton); edit.append(f); root.append(edit);
    const s = section('Create next version'); const vf = form('Encrypt and save version',async (data) => { const bytes = new TextEncoder().encode(data.get('content')); let bin = ''; bytes.forEach((b) => { bin += String.fromCharCode(b); }); await api(`${path}/versions`,'POST',{content_base64:btoa(bin),expected_version:current.current_version}); await reload(); }); field(vf,'content','New text content',{multiline:true}); vf.append(vf.submitButton); s.append(vf); root.append(s);
  }
  const discussion = section('Version discussion'); comments.items.forEach((c) => { discussion.append(el('p',`${c.author_id} · v${c.version} · ${date(c.created_at)}`),el('div',c.body,'detail-text')); });
  if (current.permissions.comment) { const f = form('Add comment', async (data) => { await api(`${path}/comments`,'POST',{body:data.get('body'),expected_version:current.current_version}); await reload(); }); field(f,'body','Comment',{multiline:true,max:4000}); f.append(f.submitButton); discussion.append(f); } root.append(discussion);
  if (current.permissions.share) {
    const s = section('Document permissions'); const entries = await api(`${path}/permissions`); s.append(table(['Subject type','Subject','Action','Effect'],entries.items.map((r) => [r.subject_type,r.subject_id,r.action,r.effect])));
    const f = form('Save access rule',async (data) => { await api(`${path}/permissions`,'PUT',{subject_type:data.get('type'),subject_id:data.get('subject'),action:data.get('action'),effect:data.get('effect')}); await reload(); }); field(f,'type','Subject type',{choices:['user','role','group']}); field(f,'subject','Existing tenant subject ID'); field(f,'action','Action',{choices:Object.entries(current.permissions).filter(([,v]) => v).map(([k]) => k)}); field(f,'effect','Effect',{choices:['allow','deny']}); f.append(f.submitButton); s.append(f,el('p','Explicit deny overrides every grant. Grants require current document authority.','hint')); root.append(s);
  }
  if (current.permissions.hold) {
    const s = section('Legal holds'); const holds = await api(`${path}/holds`); holds.items.forEach((h) => { const row = el('div'); row.append(el('p',`${h.reason} · ${h.released_at ? 'Released' : 'Active'} · ${h.created_by}`)); if (!h.released_at) row.append(button('Release with separate officer',async () => { await api(`${path}/holds/${h.id}`,'DELETE',{}); await reload(); })); s.append(row); }); const f = form('Place hold',async (data) => { await api(`${path}/holds`,'POST',{reason:data.get('reason')}); await reload(); }); field(f,'reason','Reason',{max:1000}); f.append(f.submitButton); s.append(f); root.append(s);
  }
  if (current.permissions.records) {
    const s = section('Records and retention'); s.append(el('p',`Record declared: ${current.is_record ? 'Yes' : 'No'}. Retained until: ${current.retain_until ? date(current.retain_until) : 'No configured schedule'}.`));
    const f = form(current.state === 'approved' ? 'Declare immutable record' : 'Extend retention',async (data) => { await api(`${path}/${current.state === 'approved' ? 'record' : 'retention'}`,'POST',{retain_until:Math.floor(new Date(data.get('until')).getTime()/1000)}); await reload(); }); field(f,'until','Retain until (local time)',{type:'datetime-local'}); f.append(f.submitButton); s.append(f); root.append(s);
  }
  if (current.permissions.records && current.permissions.delete) {
    const s = section('Disposition review'); s.append(button('Request disposition',async () => { const r = await api(`${path}/disposition`,'POST',{}); s.append(el('p',`Approval request ID: ${r.id}. A separate authorized records officer must approve this ID.`)); }));
    const f = form('Approve another officer’s disposition request',async (data) => { await api(`${path}/disposition`,'POST',{approve_id:data.get('id')}); message('Disposition approved. Preservation is rechecked at deletion.'); }); field(f,'id','Disposition request ID'); f.append(f.submitButton); s.append(f); s.append(button('Delete approved document',async () => { await api(path,'DELETE',{}); $('#detail').close(); await render(); message('Soft deletion completed. Physical purge is unavailable.'); })); root.append(s);
  }
  if (ticket !== state.detailGeneration) return; if (!$('#detail').open) $('#detail').showModal();
}
async function download(version) { const doc = state.doc; const r = await api(`${docPath()}/versions/${version}`); const bin = atob(r.content_base64); const bytes = Uint8Array.from(bin,(c) => c.charCodeAt(0)); const url = URL.createObjectURL(new Blob([bytes],{type:'application/octet-stream'})); const a = el('a'); a.href = url; const extension = {'text/plain':'txt','application/pdf':'pdf','image/png':'png','image/jpeg':'jpg','application/json':'json','text/csv':'csv'}[r.mime] || 'bin'; a.download = `${doc.id}-v${version}.${extension}`; a.click(); setTimeout(() => URL.revokeObjectURL(url),1000); }
async function renderSettings() { const settings = await api(`${base()}/settings`); const panel = el('div',undefined,'panel form-card'); const f = form('Save workspace settings',async (data) => { await api(`${base()}/settings`,'PATCH',{name:data.get('name'),locale:data.get('locale'),quota_bytes:Number(data.get('quota'))}); await render(); message('Settings saved. Mandatory security controls remain enforced.'); }); field(f,'name','Subsidiary name',{value:settings.name,max:120}); field(f,'locale','Default locale',{value:settings.locale,choices:Object.entries(names).map(([value,label]) => ({value,label}))}); field(f,'quota','Storage quota in bytes',{type:'number',value:settings.quota_bytes}); f.append(f.submitButton); panel.append(f,el('h3','Mandatory group controls')); Object.entries(settings.locked_controls).forEach(([key,value]) => panel.append(el('p',`${key}: ${value ? 'Required' : 'Unavailable'}`,'hint'))); $('#page-body').append(panel); }
fetch('/locales.json',{cache:'no-store',credentials:'omit'}).then((r) => r.json()).then((catalogs) => { state.catalogs = catalogs; $('#language').replaceChildren(...Object.entries(names).map(([key,name]) => { const o = el('option',name); o.value = key; return o; })); applyLanguage(); }).catch(() => message('Translations unavailable. English labels remain visible.',true));
