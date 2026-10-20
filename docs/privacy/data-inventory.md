# Development processing inventory

No actual customer controller, processor, subprocessor, jurisdiction or legal signatory is supplied. These remain deployment-specific decisions. The developer machine's timezone does not establish applicable law or approved residency. `local` is a development placement label, not a geographic compliance guarantee.

| Data | Location | Processing / visibility | Retention and gaps |
|---|---|---|---|
| Synthetic identity names, role memberships, token hashes | Private SQLite or PostgreSQL | Independent service authentication and policy | 8-hour seeded sessions; DB identity deletion not implemented |
| Raw demo tokens | Private ignored `var/data/demo-credentials.json`; browser memory on sign-in | Local testing only, no WordPress forwarding | Rotate with CLI; no application localStorage/sessionStorage; browser password manager should decline saving |
| Titles, classification, metadata, comments, workflows | Private SQLite or PostgreSQL | Service sees metadata; authorized UI receives scoped results | Monotonic document retention/record/hold guards; full artifact retention engine unfinished |
| File bytes | Encrypted local objects or private S3 | Authorized service decrypts bytes, returns base64; browser may download | Versions immutable; soft-delete only, no physical purge |
| Tenant keys | Separate private local roots or OpenBao transit | Local service can read file roots, or unwrap DEKs remotely; never returned over API | Transit policy tested in dev fixture; production custody/backups/trustee policy remain |
| Audit and notification data | Private SQLite or PostgreSQL | Tenant audit capability or current authorized recipient | No content/tokens in event payload; independent sink and retention rules pending |
| Encrypted snapshots | Operator-selected private destination | Snapshot utility includes metadata DB and ciphertext, not tenant keys | Isolated recovery only; offline/immutable copy policy is not automated |
| Connector settings | WordPress options | Console URL and explicit disclosure acknowledgment only | Uninstall removes this option; backend content unchanged |

No analytics trackers, remote fonts, AI providers, email delivery or cloud editors are bundled. Configured managed adapters contact the exact independent OIDC provider, transit service and S3 endpoint; the scanner receives plaintext transiently for malware inspection. These become deployment-specific processors/trust boundaries requiring actual approval. Opening a configured console link connects the user's browser to that chosen service. The operator must supply the actual service terms, privacy notice, lawful basis, geographic processing policy, breach process and support contact before real deployment.

Subject access, deletion requests, legal discovery, consent receipts beyond connector configuration, classification DPIAs, regional failover and subprocessor due diligence are not implemented product capabilities. Preserve this distinction in sales and operations documentation.

OIDC access/refresh credentials remain in page memory; PKCE verifier/state briefly use sessionStorage and are cleared on callback. Exact external issuer/subject mappings and revoked access hashes live in the independent DB; expiry pruning/session inventory is not complete. Reference provider fixtures are synthetic and disposable. Managed coordinated snapshots/restoration are unavailable; local backup refuses that configuration.
