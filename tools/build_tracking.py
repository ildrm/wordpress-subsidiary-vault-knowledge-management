#!/usr/bin/env python3
"""Maintain all canonical IDs and explicitly bounded implementation evidence."""
import csv
import json
import re
from collections import Counter
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
D=ROOT/'docs/requirements'
canonical=json.loads((D/'canonical-requirements.json').read_text())
features=[f for m in canonical['modules'] for f in m['features']]
areas={
'TEN':'01 02 03 04 05 06 08 09 12',
'IAM':'01 02 04 05 06 10 11 17',
'AUT':'01 02 05 09',
'DOC':'01 03 06 10 11 13 14 15 19',
'MET':'01 05 14',
'COL':'01 07 10 14 15',
'KNO':'01 06 13',
'WFL':'02 05 07 09',
'REC':'02 05 07 14',
'ENC':'03 04 05 06 10 17 19 20',
'SEC':'01 03 04 08 11 12 15',
'RPT':'01 02 03 04 05 06',
'L10':'01 02 03 04 05 07 10 11 12 13',
'API':'01 03 12 15 16 17',
'UX':'01 02 03 04 07 13',
'MOB':'01 02 06',
'PRV':'01 02 05 06 09 12',
'OPS':'01 02 03 06 09 10 11 13 16 17 18 20',
'MIG':'01 12 13',
}
active={module+'-'+n for module,nums in areas.items() for n in nums.split()}
blocked={'OPS-12':'Independent penetration testing must be commissioned with an external human assessor.','ENC-02':'Independent signed client distribution, verified enrollment and recipient protocol are not implemented; signing custody and external review also required.','ENC-12':'Trustee identity, quorum policy, secure custody and recovery protocol are not implemented; actual trustee approvals are required.'}
paths={code:'services/core-api/src/Application.php' for code in areas}
paths.update({'TEN':'services/core-api/migrations/001_initial.sql;services/core-api/src/Application.php','IAM':'services/core-api/src/Policy.php;services/core-api/src/Application.php','RPT':'services/core-api/src/Audit.php;services/core-api/src/Application.php','AUT':'services/core-api/src/Auth.php','ENC':'services/core-api/src/Crypto.php;apps/trusted-vault-client/FileCipher.php','L10':'apps/admin-web/locales.json;apps/admin-web/style.css;packages/i18n/locales.json','API':'packages/contracts/openapi.json;apps/wordpress-plugin/subsidiaryvault-kms/subsidiaryvault-kms.php','OPS':'tools/backup.php;tools/restore.php;tools/worker.php;.github/workflows/ci.yml','UX':'apps/admin-web/app.js;apps/admin-web/style.css','MOB':'apps/admin-web/style.css;apps/trusted-vault-client/FileCipher.php','PRV':'docs/privacy/data-inventory.md;apps/wordpress-plugin/subsidiaryvault-kms/subsidiaryvault-kms.php','MIG':'tools/vault.php;docs/operations/local-development.md'})
fields=['id','module','priority','implementation_phase','architecture_owner','engineer','security_reviewer','code_paths','db_tables','api_endpoints','ui_screens','i18n_labels','tests','verification_evidence','vulnerabilities','residual_risk','status']
paths.update({'AUT':'services/core-api/src/Auth.php;services/core-api/src/Oidc.php;apps/admin-web/oidc.js;tools/identity.php','DOC':'services/core-api/src/Application.php;services/core-api/src/ObjectStore.php;services/core-api/src/FileScanner.php','ENC':'services/core-api/src/Crypto.php;services/core-api/src/KeyService.php;apps/trusted-vault-client/FileCipher.php','SEC':'services/core-api/src/Policy.php;services/core-api/src/FileScanner.php;tools/secret_scan.py','OPS':'services/core-api/src/Database.php;services/core-api/migrations/postgres/002_isolation.sql;infrastructure/integration/compose.yaml;tools/backup.php;tools/worker.php;tools/verify_release.py;.github/workflows/ci.yml','RPT':'services/core-api/src/Audit.php;services/core-api/src/AuditCheckpoint.php;tools/audit_checkpoint.php'})
rows=[]
for f in features:
    status='BLOCKED' if f['id'] in blocked else 'IN_PROGRESS' if f['id'] in active else 'NOT_STARTED'
    partial=status=='IN_PROGRESS'
    row=dict.fromkeys(fields,'')
    row.update(id=f['id'],module=f['code'],priority=f['priority'],implementation_phase=f['release'],architecture_owner='INTERNAL/AI REVIEW ONLY; human accountable owner unassigned',engineer='Codex implementation',security_reviewer='External reviewer NOT APPOINTED',status=status)
    row['code_paths']=paths.get(f['code'],'') if partial else ''
    row['tests']='tests/run.php;tests/http_test.py;tests/crypto_backup_test.php;tests/plugin_test.php;docs/release/verification.json (see exact suite scope)' if partial else ''
    row['verification_evidence']='docs/implementation/TEST-RESULTS.md (component evidence; full canonical acceptance NOT VERIFIED)' if partial else ''
    row['residual_risk']=blocked.get(f['id'],'Partial local/reference implementation; canonical behavior, both-mode coverage and independent acceptance remain incomplete. See gap register.' if partial else 'No implementation evidence; not available in this release.')
    rows.append(row)
with (D/'traceability.csv').open('w',newline='') as file:
    writer=csv.DictWriter(file,fieldnames=fields,lineterminator="\n");writer.writeheader();writer.writerows(rows)
with (D/'mode-compatibility.csv').open('w',newline='') as file:
    writer=csv.writer(file,lineterminator="\n");writer.writerow(['id','managed_local','managed_kms','zero_knowledge','offline','external_share','reason','alternative'])
    for f in features:writer.writerow([f['id'],'PARTIAL' if f['id'] in active else 'UNAVAILABLE','PARTIAL REFERENCE ONLY' if f['id'] in active else 'UNAVAILABLE','UNAVAILABLE','file utility only' if f['id']=='ENC-04' else 'UNAVAILABLE','UNAVAILABLE','Synthetic local/reference profiles only; no claim of full feature support or production assurance','See canonical contract and gap register'])
master=(ROOT/'docs/source/SubsidiaryVault_KMS_MASTER_IMPLEMENTATION_PROMPT.md').read_text()
personas=re.findall(r'^### (UXROLE-\d{2})\. (.+)$',master,re.M)
with (D/'permission-matrix.csv').open('w',newline='') as file:
    writer=csv.writer(file,lineterminator="\n");writer.writerow(['persona_id','persona','action','scope','conditions','explicit_deny_precedence','tests','runtime_mapping'])
    for pid,name in personas:
        for action in ['view','download','edit','comment','share','delete','approve','records','hold','audit','members','roles','groups','settings']:
            writer.writerow([pid,name,action,'explicit tenant and resource','Active membership; role capability; clearance; matching ACL for document actions; privileged MFA; preservation and separation of duties','deny wins','tests/run.php (policy engine only; persona mapping pending)','NOT CONFIGURED: personas do not automatically confer rights'])
with (D/'compliance-matrix.csv').open('w',newline='') as file:
    writer=csv.writer(file,lineterminator="\n");writer.writerow(['control','applicability','jurisdiction','evidence','owner','exception'])
    for title in ['OWASP ASVS 5.0','NIST SP 800-63-4','WCAG 2.2 AA','ISO 15489','Privacy law and eSignature obligations']:
        writer.writerow([title,'ASSESSMENT REQUIRED','UNDETERMINED','docs/security/threat-model.md; no certification or legal sign-off','Human owner unassigned','No compliance claim'])
counts=Counter(r['status'] for r in rows)
text='# Feature coverage\n\nFull canonical acceptance: **0 / 346 VERIFIED**. Passing component tests do not establish full feature completion.\n\n'
text+='| Status | Count |\n|---|---:|\n'+''.join(f'| {status} | {counts[status]} |\n' for status in ['NOT_STARTED','IN_PROGRESS','IMPLEMENTED_UNVERIFIED','VERIFIED','BLOCKED','DEFERRED'])
text+='\n| Module | Total | Partial implementation | Not started | Blocked |\n|---|---:|---:|---:|---:|\n'
for module in canonical['modules']:
    group=[r for r in rows if r['module']==module['code']];c=Counter(r['status'] for r in group)
    text+=f"| {module['code']} — {module['title']} | {len(group)} | {c['IN_PROGRESS']} | {c['NOT_STARTED']} | {c['BLOCKED']} |\n"
text+='\nNo unimplemented requirement is silently marked deferred or satisfied. `BLOCKED` records name real outside dependencies and still disclose outstanding engineering. `NOT_STARTED` work remains actionable. Runtime modes are deliberately restricted.\n'
(D/'feature-coverage.md').write_text(text)
print(json.dumps({'total':len(rows),'counts':dict(counts),'runtime_personas':len(personas)}))
