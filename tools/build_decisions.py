#!/usr/bin/env python3
"""Persist the 22 supplied decision questions with bounded, explicit development choices."""
import re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
master=(ROOT/'docs/source/SubsidiaryVault_KMS_MASTER_IMPLEMENTATION_PROMPT.md').read_text()
block=master.split('# APPENDIX D')[1].split('# APPENDIX E')[0]
choices=[
('Treat WordPress PHP, administrators, database, themes and scripts as hostile. The connector has no vault token, document proxy or decrypt route.','Plugin harness and cross-tenant API tests; full malicious WordPress infrastructure exercise pending.'),
('Ship only an explicitly unsigned offline PHP/Sodium file utility now. Gate zero-knowledge repository mode until independently signed client distribution, enrollment, key verification and review exist.','Offline utility roundtrip, AAD and truncation tests; signed distribution unavailable.'),
('Only managed-local synthetic text is enabled. Reject every request for zero-knowledge mode. Classification 0–3 is configured per document; production classification mandates remain a customer decision.','Unsupported mode and clearance denial tests.'),
('No trustee recovery or escrow API is enabled. Local managed tenant keys and offline file keys are distinct custody objects. Recovery policy requires named trustees and an audited protocol.','Restore requires separately held tenant key directory; missing-key failure tested.'),
('Treat each entity as a separate authorization scope. Controller, processor, counsel and records owners remain UNASSIGNED; do not infer a legal jurisdiction from developer location.','Membership and holding oversight tests; legal accountability not approved.'),
('Verify independent bearer identity and current account; active tenant/membership; capabilities and privileged MFA; resource tenant, classification, explicit deny then ACL allow; preservation and separation of duties at mutation. Serialize local transactions.','tests/run.php negative authorization, revocation, workflow and preservation tests.'),
('No break-glass content or key override exists. Emergency recovery requires a separately designed and reviewed workflow; WordPress administration never confers it.','Forged/mis-audienced identity and tenant-administrator content denials.'),
('Managed-local service sees titles, metadata, comments, identity graph and decrypted text on explicitly authorized transfer. Object files are encrypted; key envelopes and hashes are in independent SQLite. No metadata confidentiality claim against this service.','Ciphertext and authorized digest checks; metadata leakage is documented.'),
('Search only current-authorized managed titles and string metadata using parameterized scoped queries and a fresh policy decision for each result. No full-text, vector or zero-knowledge index exists.','Clearance, explicit deny and search-trimming tests.'),
('Accept UTF-8 text only in the local proof. Reject binary/control-byte payloads and the EICAR marker. This is not malware scanning. Binary uploads, conversion and high-assurance E2EE scanning await reviewed pipelines.','Content-type, malformed base64 and signature tests.'),
('Disable external sharing and recipient-key envelopes. Internal ACL changes require an entitled custodian, an existing tenant subject and no self-grant; cross-subsidiary sharing is not inferred.','Foreign references and unauthorized ACL grants fail; external feature not implemented.'),
('No eDiscovery plaintext export or legal recovery is enabled. Document holds and dual-officer release are available for development. Actual discovery authority must be specified by counsel.','Hold, separation of duties and disposition tests; lawful discovery review pending.'),
('Record locks, holds and monotonic retention block deletion and relevant content edits. No physical purge or cryptographic erasure endpoint exists. Production retention schedules and exemptions require legal approval.','Database preservation triggers and API denial tests.'),
('Provide 13 initial navigation/status catalogs; use pt-PT for Portuguese and map zh-Hans to zh_CN. RTL uses logical CSS and bidirectional isolates. Complete form translation and native review remain unfinished.','Catalog parity validation; browser desktop English seen; comprehensive RTL/a11y acceptance pending.'),
('Internal approval records a pinned version digest, actor, verified-session MFA, decision/comment, time and policy version. It is not an electronic signature service or qualified signature.','Pinned-version, self-approval and immutable evidence tests.'),
('Holding governance sees names, statuses, counts and byte totals only. It has no tenant content membership by default. Small-count inference remains a privacy consideration for future production policy.','Posture excludes titles and holding cannot read documents.'),
('Use 10 synthetic entities, 8 identities each and one independent governor for local tests. Limit content to 8 MiB UTF-8 per version, 100 MiB default tenant quota and 120 requests/user/tenant/minute. No SLO/RTO/RPO claim.','Executed test profile and isolated restore; production load and recovery targets unknown.'),
('No external processing, AI, cloud editors, federation provisioning or cloud-storage provider is preapproved. The pinned RS256 verifier is an experimental identity adapter; MFA claims are deliberately untrusted until mapped.','JWT audience/issuer/subject/expiry/algorithm tests; actual provider integration pending.'),
('Do not support implicit tenant ID reuse, merges, transfers or split migrations. Any future relocation must preserve legal holds, verify keys and obtain separate origin/recipient authorization.','Foreign resource denial tests; reorganization engine not implemented.'),
('No AI provider calls or plaintext AI processing occur. AI APIs are absent and settings cannot enable them. A tenant/provider/classification consent and regional policy review must precede implementation.','Unknown configuration fields and routes are rejected; AI evaluation not implemented.'),
('Deliver GPL-2.0-or-later development source and a connector ZIP. Choose dependency-light PHP 8.3+ extensions and vanilla web UI for a reproducible local proof; SQLite is not the proposed enterprise data plane. Production is explicitly gated by SV_ENV.','Local PHP 8.5 tests and reproducible ZIP; Docker and live WordPress unverified.'),
('No production go/no-go approval is supplied. Gate production in code and track named external security, cryptography, privacy/legal, records, accessibility, UAT and DR sign-offs as missing.','SV_ENV production startup rejection and READINESS.md; INTERNAL/AI REVIEW ONLY.'),
]
questions=re.findall(r'\*\*(D-\d{2}) — (.+?)\*\* Owner: (R\d{2}); modules: ([A-Z0-9,]+)\.',block)
assert len(questions)==len(choices)==22
for (number,title,owner,modules),(choice,evidence) in zip(questions,choices):
    text=f'''# {number}: {title}

Status: INTERNAL/AI DESIGN DECISION for the development profile; human acceptance pending.
Date: 2026-10-20. Accountable specialist function: {owner}. Named human owner: UNASSIGNED.
Requirements: canonical {modules} module contracts; no canonical contract is removed by this decision.

## Context and decision

{choice}

## Alternatives and consequences

The preferred enterprise architecture remains independent identity, a reviewed policy plane, PostgreSQL with defense-in-depth isolation, private object storage, KMS/HSM, independently controlled audit and a signed trusted client. Enabling incomplete enterprise paths or placing confidential-client trust in WordPress was rejected. Local adapters make the current testbed executable but do not establish enterprise assurance. No legal jurisdiction, production vendor or human authority is chosen here.

## Evidence and residual risk

{evidence}

See [test results](../implementation/TEST-RESULTS.md) and [gaps](../requirements/gap-register.md). Self-review is not an independent professional approval. Unimplemented paths remain disabled or absent.

## Review and revisit trigger

Reviewers: INTERNAL/AI REVIEW ONLY; independent accountable reviewers must be appointed. Revisit before production, whenever scope or trust boundaries change, on relevant security findings, or when actual provider/custody/legal policies are supplied.
'''
    (ROOT/'docs/decisions'/f'{number}.md').write_text(text)
index='# Decision log\n\nAll decisions are internal development choices; legal and external assurance sign-offs remain open.\n\n'
index+='\n'.join(f'- [{n}: {title}](../decisions/{n}.md)' for n,title,_,_ in questions)
(ROOT/'docs/requirements/decision-log.md').write_text(index+'\n')
print('Wrote 22 internal ADRs')
