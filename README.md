# SubsidiaryVault KMS

**Enterprise Document & Knowledge Management for WordPress**

SubsidiaryVault separates organizational document access from WordPress administration. An independently authenticated service controls subsidiary memberships, document permissions, encrypted versions and governance. WordPress supplies a small connector for navigation and connection settings.

**Status: `0.2.0-rc.1` — unsigned engineering release candidate. Use synthetic data only.** This repository contains working, tested software, but it does **not** implement the entire 346-requirement enterprise product. Production deployment and high-assurance zero-knowledge repository access are unavailable. [Readiness](docs/release/READINESS.md) and [feature coverage](docs/requirements/feature-coverage.md) describe the exact limits.

## Contents

- [Working capabilities and scope](#working-capabilities-and-scope)
- [Quick start](#quick-start)
- [Self-hosted reference stack](#self-hosted-reference-stack)
- [Architecture and security modes](#architecture-and-security-modes)
- [Repository structure](#repository-structure)
- [Using the workspace](#using-the-workspace)
- [WordPress connector](#wordpress-connector)
- [Configuration and limits](#configuration-and-limits)
- [API and workers](#api-and-workers)
- [Offline file utility and backups](#offline-file-utility-and-backups)
- [Languages and accessibility](#languages-and-accessibility)
- [Verification and packaging](#verification-and-packaging)
- [Requirements, sources and remaining work](#requirements-sources-and-remaining-work)
- [Operations, contribution, support and license](#operations-contribution-support-and-license)

## Working capabilities and scope

The application provides these working components:

- Ten seeded subsidiaries with independent memberships, eight synthetic identities each and a separate holding governor.
- Current-membership checks, tenant capabilities, classification clearance and user/group/role document ACLs. Explicit denials win; privileged actions require session MFA. WordPress administrators gain no document rights.
- Tenant custom roles, static teams, department hierarchies, eligible settings, quotas, idempotent provisioning and audited suspension/reactivation.
- Text documents and knowledge articles, optional scanned PDF/PNG/JPEG/JSON/CSV uploads, encrypted immutable content versions, separate metadata revisions, permission-filtered title/metadata search, comments and bookmarks.
- Version-bound reviews with separate approvers; immutable record declaration; legal holds and dual-officer release; monotonic retention; separate disposition approval before soft deletion.
- Hash-linked audit, Ed25519 trusted-prefix checkpoint tooling, transactional outbox and current-authorized local inbox notifications.
- Optional PostgreSQL forced RLS, real Keycloak OIDC code/PKCE/refresh/revocation/introspection, non-exportable OpenBao transit wrapping and conditional S3 ciphertext objects.
- An independent browser console, installable WordPress connector, unsigned offline file-encryption utility, encrypted snapshots and isolated restoration.
- Draft navigation/status translations for 13 languages, Persian/Arabic direction handling, reproducible tests and requirement tracking.

These are partial implementations of broader canonical features. Folders/libraries, full business formats and resumable capture/quarantine, protected previews, rich coauthoring, complete metadata/workflow/records engines, directory/MFA lifecycle, external sharing/data rooms, AI, native/mobile clients, migrations and specialized modules remain unfinished. Persistent hardened infrastructure, managed coordinated recovery and an independent immutable audit service also remain missing. The [gap register](docs/requirements/gap-register.md) and [production exit criteria](docs/release/production-exit-criteria.md) retain the work; no feature is removed to obtain a release label.

## Quick start

Prerequisites:

| Tool | Requirement |
|---|---|
| PHP CLI | 8.3+ with `pdo_sqlite`, `sodium`, `openssl`, `intl`, `mbstring`, `fileinfo` and `Phar`; add `pdo_pgsql` and `curl` for the managed reference adapters |
| Python | 3.10+ for verification/build tools |
| Node.js | For JavaScript syntax/PKCE verification; no npm installation is needed |
| Make | Optional convenience targets; commands can be run individually |

No Composer or npm dependencies are required. The executed host environment was PHP 8.5.8, Python 3.14.5 and Node.js 26.12.0 on macOS ARM64. Other runtime versions are not claimed as locally tested.

From the repository root:

```sh
make setup
make serve
```

Open **http://127.0.0.1:8088**. In another terminal:

```sh
php tools/vault.php token tenant-01-custodian
```

Paste that synthetic token into the console and open the workspace. Decline browser password saving. Application tokens remain in page memory; refreshing requires signing in again.

Without Make:

```sh
php tools/vault.php migrate
php tools/vault.php seed
php -S 127.0.0.1:8088 -t services/core-api/public services/core-api/public/router.php
```

Setup creates ignored private data under `var/data` and separate tenant keys under `var/keys`. Raw synthetic credentials are written to the private, ignored `var/data/demo-credentials.json`; the database stores token hashes. Seeded tokens expire after eight hours. Running setup again preserves existing identities, keys and documents. To replace development sessions without deleting data:

```sh
php tools/vault.php rotate-demo-tokens
```

The built-in server binds loopback and is for development. Do not expose it publicly or upload company data. A managed environment may require permission to bind a local socket.

An optional [Docker development composition](infrastructure/dev/compose.yaml) is supplied:

```sh
docker compose -f infrastructure/dev/compose.yaml up --build
docker compose -f infrastructure/dev/compose.yaml exec vault php tools/vault.php token tenant-01-custodian
```

The pinned-base development image built and its required PHP extensions passed a container smoke check (PHP 8.5.11). This convenience composition still uses local SQLite/key volumes and a local image tag; it is not the production deployment. The separate actual service reference stack is below.

## Self-hosted reference stack

The selected target is self-hosted. A pinned, disposable reference composition runs actual PostgreSQL, Keycloak, OpenBao, SeaweedFS S3, ClamAV and WordPress/MariaDB/WP-CLI. It exercises the implemented adapters without using company data. Install Docker/Compose v2 and the managed PHP extensions above, then run:

```sh
make release-verify
make release-candidate
python3 tools/integration.py down
```

The full verification runs local tests, live service protocols and a pinned Gitleaks source scan. On ARM, the ClamAV fixture needs amd64 emulation. Images take time/RAM/disk to pull and start; orchestration waits for the actual scanner before identity tests. Definition refresh returned CDN HTTP 403 in this environment. Tests use bundled signatures only while within the enforced age limit; successful automatic updates and operational update access remain unverified. Generated credentials and random loopback ports live only in ignored private `var/release-integration/`; never distribute it. `down` stops only `svkms-release-it` and retains the synthetic WordPress volume. Fixtures are disposable; they are not durable key/data custody.

This is an integration environment, not the missing persistent production deployment. It uses dev-mode identity/key services, HTTP loopback, temporary storage and broader S3 fixture permissions. Actual OIDC, storage/key/scan and WordPress tests cover separate boundaries rather than a deployed production browser journey. [The complete reference guide](docs/operations/self-hosted-reference.md) explains topology, configuration, identities, grants, key policy, scan freshness, cleanup and unverified controls. `make integration` reruns just the live reference checks.

## Architecture and security modes

```mermaid
flowchart LR
    WP[WordPress: URL and consent] -->|Open independent console| WEB[Vault console]
    WEB -->|Independent bearer identity| API[PHP core API]
    API --> POLICY[Membership + capabilities + MFA + clearance + ACL]
    POLICY --> DB[(SQLite or PostgreSQL metadata / audit / outbox)]
    POLICY --> ENC[Sodium envelope encryption]
    ENC --> OBJECTS[(Encrypted local or S3 objects)]
    ENC --> KEYS[Local roots or OpenBao transit]
    DB --> WORKER[Scoped local notifications]
```

The WordPress connector stores no vault credentials, documents or decryption keys, performs no automatic remote requests and cannot impersonate vault users. It opens the independently served console in a new tab. WordPress cookies, roles and nonces are not accepted as vault authentication.

Every core operation verifies independent identity and current membership. Resource access also checks tenant ownership, capability, classification, document ACL and explicit denials; relevant operations add MFA, preservation and separation-of-duties checks. SQLite transactions or a conservative global PostgreSQL advisory transaction lock serialize authorization/state changes, and document updates commit with audit evidence. PostgreSQL uses forced RLS with transaction-local principal/tenant context under restricted non-owner roles. RLS is an additional guard; it does not protect against a compromised service choosing arbitrary context. The global lock requires load testing and a justified scalable strategy before capacity promises. Content and metadata have separate revision checks. Workers and inbox delivery reauthorize current access.

| Profile | Current state | Trust and limits |
|---|---|---|
| Managed local | Implemented for synthetic UTF-8 text | Service can see authorized plaintext and metadata. Fresh per-version keys and authenticated context; tenant keys are readable by the local service. This is not KMS/HSM-backed protection. |
| Managed external reference | Implemented adapters; not production-approved | Real PostgreSQL/RLS, OIDC introspection, non-exportable transit roots, S3 and ClamAV. The service sees DEKs and authorized plaintext. Persistent seal/HA/IAM/WORM, approved privileged MFA, managed recovery and independent assurance remain. |
| High-assurance zero-knowledge vault | Unavailable; mode requests rejected | Requires reviewed recipient/device/recovery protocol and independently authenticated, signed client/update distribution. A WordPress-origin decrypting page does not satisfy this boundary. |
| Offline file utility | Implemented, unsigned development tool | Uses Sodium secretstream with a separately held file key; has no vault enrollment, verified recipient sharing, keychain or signed update integration. It is not the missing high-assurance client. |

Audit chains and DB immutability triggers protect normal application paths. Ed25519 checkpoints detect rewrite/truncation of an anchored prefix only when the latest checkpoint/public key are independently trusted; scheduling, immutable sink, freshness and unanchored-window protection remain missing. Root/database administrators can otherwise rewrite the chain. Record/hold guards are not production WORM storage. Previously downloaded plaintext cannot be recalled. No zero-vulnerability, certification, legal-compliance or production-readiness claim is made.

Read the [architecture](docs/architecture/overview.md), [threat model](docs/security/threat-model.md), [cryptographic profile](docs/security/crypto-profile.md), [privacy inventory](docs/privacy/data-inventory.md) and [22 internal decisions](docs/requirements/decision-log.md) before extending security-sensitive behavior.

## Repository structure

```text
apps/
  admin-web/                  Independent HTML/CSS/JavaScript console
  trusted-vault-client/       Unsigned offline PHP/Sodium file utility
  wordpress-plugin/
    subsidiaryvault-kms/      Installable WordPress connector
services/core-api/
  src/                        Authentication, policy, encryption, audit, API
  public/router.php           HTTP boundary and explicit console-asset allowlist
  migrations/                 SQLite migrations 1–4; PostgreSQL migrations 1–3
packages/
  contracts/openapi.json      Reference for 43 paths / 68 implemented operations
  crypto-test-vectors/        Upstream primitive-vector provenance
  i18n/                      Draft catalogs and WordPress locale mappings
infrastructure/
  dev/                        Local Docker recipe and tested pinned PHP base
  integration/                Pinned actual service fixtures and live tests
  production/                 Explicit production prerequisites and gate
tests/                        Domain/HTTP/crypto/PKCE/PG/provider/WordPress checks
tools/                        Identity, worker, checkpoints, recovery, verification, packaging
docs/
  source/                     Six preserved originals, extracted text and hashes
  requirements/               All 346 contracts, status and compatibility matrices
  decisions/                  D-01 through D-22
  architecture/ security/     Boundaries, references and cryptographic limitations
  privacy/ operations/       Processing inventory and operational/user guides
  implementation/ release/   Persistent progress, executed tests and release gates
.github/workflows/ci.yml      Pinned correctness + reference jobs; remote runs pending
var/                         Ignored private runtime data and keys
dist/                        Ignored candidate ZIPs, inventory, manifest and checksums
```

## Using the workspace

Tenant IDs are `tenant-01` through `tenant-10`. Obtain each user's token with `php tools/vault.php token USER_ID`.

| User ID example | Development role |
|---|---|
| `tenant-01-administrator` | Local settings/membership/role administration, without automatic document access |
| `tenant-01-custodian` | Seeded document owner, version creation, access grants and permitted governance |
| `tenant-01-reviewer` | Granted viewing/download/comment and independent approval |
| `tenant-01-records`, `tenant-01-records2` | Granted records/hold/disposition operations; separate officers for dual control |
| `tenant-01-viewer` | View granted metadata; download is denied |
| `tenant-01-contributor` | Create own content and edit explicitly granted materials |
| `tenant-01-lowclearance` | Classification-denial demonstration |
| `holding-governor` | Aggregate oversight, provisioning and suspension/reactivation; no subsidiary document access |

Create a document or knowledge article with a title, classification, category and text, or select a file permitted by the configured upload profile. Every new content version receives a fresh encrypted object. Metadata changes increment an independent revision. Search considers current-authorized titles and metadata, not file content. Document dialogs expose only effective actions; the server enforces those same checks independently.

A review example:

1. Sign in as the custodian and submit the seeded version for approval.
2. Sign out, sign in as that subsidiary's reviewer, and approve or reject the pinned version.
3. A permitted records officer can declare an approved version an immutable record. An owner, author or requester cannot approve their own version.

For preservation, one authorized officer places a hold and another releases it. Retention can only be extended. A non-record may be soft-deleted only after a separate disposition approval and another current preservation check. No purge/key-destruction, record-unlock or recycle restoration endpoint exists.

See the [full local/user guide](docs/operations/local-development.md) for detailed workflows, fixture roles, tokens and operational caveats. All 26 specification personas are retained in the [permission matrix](docs/requirements/permission-matrix.csv); they are not all configured as complete runtime role templates.

## WordPress connector

```sh
make package
```

Install `dist/subsidiaryvault-kms-0.2.0-rc.1.zip` through WordPress Plugins → Add New → Upload Plugin, or copy `apps/wordpress-plugin/subsidiaryvault-kms` into `wp-content/plugins`. Activate, open **SubsidiaryVault → Connection settings**, configure the independent HTTPS console URL and acknowledge the external-service disclosure.

Only the URL and consent boolean are stored. Settings and connection diagnostics require `manage_options`; navigation requires `read`. The connector uses the Settings API, escaping/sanitization and an explicit REST permission callback. Uninstall removes only its WordPress option, without altering independent vault data.

An explicit disposable-development exception permits HTTP loopback URLs only when both `WP_DEBUG` and `SVKMS_ALLOW_LOCAL_CONSOLE` are true. Credential-bearing URLs, query strings and fragments are rejected. Never paste vault tokens or keys into WordPress.

Compatibility metadata targets WordPress 6.8+ and PHP 8.3+. Syntax, connector harness, ZIP checks and actual WordPress activation/settings/capabilities/REST/deactivation/uninstall passed. Multisite, browser nonce/form automation, Plugin Check and WPCS have not run. There is no claimed WordPress.org approval or signed updater. Read the [connector guide](docs/operations/wordpress.md) and packaged [readme](apps/wordpress-plugin/subsidiaryvault-kms/readme.txt).

## Configuration and limits

Export environment variables in the invoking process. [.env.example](.env.example) is documentation; no dotenv loader is bundled.

| Variable | Default / purpose |
|---|---|
| `SV_ENV` | `local`, `integration` or constrained `staging`; `production` rejects startup |
| `SV_DATA_DIR`, `SV_KEY_DIR` | Private runtime data and separate local roots; defaults `var/data`, `var/keys` |
| `SV_DATABASE_DSN` | Defaults private SQLite file; `pgsql:...` selects PostgreSQL |
| `SV_DATABASE_USER`, `SV_DATABASE_PASSWORD_FILE` | Separate restricted runtime DB identity; never the schema owner/migrator |
| `SV_AUTH_MODE` | `development`, legacy `oidc-rs256`, or `oidc-introspection` |
| `SV_OIDC_ISSUER`, `SV_OIDC_AUDIENCE` | Exact independent issuer and API audience |
| `SV_OIDC_WEB_CLIENT_ID` | Public PKCE browser client |
| `SV_OIDC_INTROSPECTION_CLIENT_ID`, `SV_OIDC_INTROSPECTION_CLIENT_SECRET_FILE` | Independent confidential API verifier |
| `SV_OIDC_MFA_ACR`, `SV_OIDC_MFA_MAX_AGE` | Exact approved assurance plus recent authentication; omit ACR to deny privileged MFA |
| `SV_KEY_PROVIDER`, `SV_KEY_SERVICE_URL`, `SV_KEY_SERVICE_TOKEN_FILE` | Default `local-files`, optional `openbao-transit` with separately provisioned non-exportable tenant roots |
| `SV_OBJECT_PROVIDER`, `SV_S3_ENDPOINT`, `SV_S3_BUCKET`, `SV_S3_REGION` | Default `local-files`, optional fixed private S3 target |
| `SV_S3_ACCESS_KEY_FILE`, `SV_S3_SECRET_KEY_FILE` | File-injected object workload credentials |
| `SV_UPLOAD_PROFILE`, `SV_CLAMD_ADDRESS` | Default `text`, optional `scanned` via a private clamd socket/network |
| `SV_SCAN_MAX_DATABASE_AGE_SECONDS` | Default 259,200 seconds (72 hours); stale/unknown/inconclusive scanner fails closed |

The complete inventory and startup/credential rules are in [.env.example](.env.example) and the [reference guide](docs/operations/self-hosted-reference.md). Secret `_FILE` values take precedence. HTTPS service endpoints use the system CA trust store; staging PostgreSQL requires `sslmode=verify-full` and staging runs non-root. `SV_ALLOW_INSECURE_TEST_ENDPOINTS=1` is allowed only with local/integration, never staging. No existing SQLite/content/key-envelope migration is automated; changing providers is not a migration procedure.

OIDC code + S256 uses strict state/issuer/expiry and keeps access/rotating refresh tokens in page memory. The API introspects each access token, validates exact issuer/audience/client/subject and existing independent identity, then checks current membership/ACL. Only PKCE verifier/state temporarily use sessionStorage. There is no email/WordPress JIT identity trust. `tools/identity.php` creates/binds explicit identities under a controlled provisioner connection without adding entitlements. SAML/SCIM, session inventory, recovery and an accepted privileged MFA/step-up flow remain unfinished. The pinned-RS256 adapter has no browser federation/MFA mapping.

Limits: **8 MiB/version**, **12 MiB HTTP JSON envelope**, **100 MiB default tenant quota** (configurable 1 MiB–1 GiB), **120 requests/principal/tenant/minute**. Every retained historical/soft-deleted version counts. Local text mode accepts nonempty UTF-8 without prohibited controls; marker rejection there is not antivirus. Scanned mode uses real ClamAV freshness/INSTREAM for detected text/JSON/CSV/PDF/PNG/JPEG only. Office files, archives, resumable uploads, quarantine review and previews/conversions remain unavailable. Raw clamd TCP must be private; it is not an authenticated TLS protocol.

Encryption, explicit-deny precedence, privileged MFA and audit cannot be disabled in tenant settings. AI/external sharing cannot be enabled. Geographic residency, legal schedules, real providers and operational authorities are not inferred from `local`, the developer machine or the source documents.

## API and workers

The [API guide](docs/operations/api.md) documents authentication, request bodies, errors, revision checks and operation semantics; [OpenAPI JSON](packages/contracts/openapi.json) describes current routes with generic success schemas. A complete typed SDK is not supplied.

Protected calls require an independent bearer header. Public liveness is `/healthz` (`release_approved:false`); public `/v1/auth/config` exposes only non-secret sign-in/profile configuration. Private filesystem paths are never served, and private responses use no-store/noindex/frame/CSP headers. There is no WordPress-cookie authority or CORS grant.

```sh
php tools/worker.php
php tools/vault.php audit
php tools/diagnostics.php
```

Each worker invocation processes at most 100 outbox events. PostgreSQL needs the separate worker identity and bounded pending-event function grant described in the reference guide. Run repeatedly until zero events remain. It writes local inbox notifications only, authorizes recipients against current state and uses deterministic IDs for retries. Inbox reads recheck access after revocation. No external emails, chat messages or webhooks are sent. Diagnostics report runtime/extension/schema information without document contents, tokens, keys or private paths.

Audit events describe application authorization and service operations. They do not prove a person read a document or finished saving a download. An independent immutable sink, trustworthy timestamps and protected external checkpoints remain required.

## Offline file utility and backups

The unsigned local file utility has no network connection or repository identity integration:

```sh
php apps/trusted-vault-client/vault-client.php keygen /private/tmp/svkms-file.key
php apps/trusted-vault-client/vault-client.php encrypt input.txt output.svenc --key /private/tmp/svkms-file.key example-context
php apps/trusted-vault-client/vault-client.php decrypt output.svenc restored.txt --key /private/tmp/svkms-file.key example-context
```

Use new private paths; existing outputs are refused. Decryption requires the exact same key and context and publishes only after complete stream authentication. Keep keys separately from ciphertext. This utility does not provide verified recipients, enterprise recovery or signed native-client assurance.

Encrypted local backup and isolated restoration:

```sh
php apps/trusted-vault-client/vault-client.php keygen /private/tmp/svkms-backup.key
php tools/backup.php /private/tmp/svkms-backup.key /private/tmp/svkms-snapshot.svbackup
php tools/restore.php /private/tmp/svkms-backup.key /private/tmp/svkms-snapshot.svbackup /private/tmp/svkms-isolated-restore
```

Backups include a consistent metadata snapshot, referenced encrypted objects and an authenticated integrity manifest. **Tenant encryption keys are omitted and need separate protected backups.** Old sessions are revoked in snapshots and restores. Restore refuses existing destinations and produces an isolated directory. Before any reconnection, current identities, revocations, ACLs, legal holds and retention must be reconciled; that reconciliation is not automated. This snapshot command explicitly refuses PostgreSQL, S3 or transit configuration; coordinated managed backup/restore/current-policy reconciliation is still missing. No immutable/offline provider or RTO/RPO promise is supplied. Follow the [backup/recovery guide](docs/operations/backup-recovery.md).

## Languages and accessibility

Draft navigation/status catalogs cover English, Persian, French, Turkish, Russian, Simplified Chinese, German, Portuguese, Spanish, Hindi, Japanese, Arabic and Armenian. Catalog codes are `en`, `fa`, `fr`, `tr`, `ru`, `zh-Hans`, `de`, `pt`, `es`, `hi`, `ja`, `ar`, `hy`; Portuguese defaults to `pt-PT`. [Locale mappings](packages/i18n/locales.json) include corresponding WordPress locales.

Persian and Arabic select RTL logical layouts. Tables use bidirectional isolates for dynamic values and dates use `Intl.DateTimeFormat`. Semantic labels, a skip link, focus indicators and reduced-motion handling are present. Desktop English and Persian layout/document forms were inspected in actual browsers. **Forms, notices and errors remain partly English.** Complete translation, native terminology review, Arabic/mobile journeys, screen readers, keyboard workflows and independent WCAG 2.2 AA acceptance are unfinished. No translation certification or accessibility-conformance claim is made.

Rebuild draft catalogs with `python3 tools/build_locales.py`; do not represent key parity as complete language coverage.

## Verification and packaging

```sh
make validate                   # local correctness, syntax and requirement registry
make release-verify             # also actual services + source secret scan (Docker)
make release-candidate          # requires current passing code fingerprint
python3 tools/release_gate.py --development
```

Executed component/protocol checks total **1,413 assertions**:

| Scope | Assertions |
|---|---:|
| Domain/policy/tenant/security | 1,058 |
| Connector harness; crypto/worker/local recovery | 11 + 62 |
| Audit checkpoints; config/TLS guards; browser-independent PKCE units | 9 + 12 + 22 |
| Real loopback HTTP boundary | 23 |
| Actual PostgreSQL/RLS | 146 |
| Actual OIDC API and Keycloak protocol | 10 + 25 |
| Actual transit/S3/ClamAV and operator root rotation | 17 + 2 |
| Actual WordPress settings/REST/lifecycle | 13 + 3 |

Local subtotal is 1,197; reference subtotal 216. PHP syntax (41 files), console JS syntax, all 346 immutable contracts/source hashes/module totals, persona/ADR/catalog checks pass. The pinned Gitleaks worktree scan found no embedded secret; Git history/private ignored state are outside its scope. A pinned PHP development image builds and its required extensions pass. These are not 1,413 completed requirements, a penetration test, a deployed production browser journey or independent cryptographic certification. [Test results](docs/implementation/TEST-RESULTS.md) record precise limitations; [verification JSON](docs/release/verification.json) binds actual exits/timestamps to tested code. GitHub Actions jobs are defined/pinned for PHP 8.3–8.5 and live reference services; remote runs are not claimed.

Candidate output under `dist/`:

| Artifact | Purpose |
|---|---|
| `subsidiaryvault-kms-0.2.0-rc.1.zip` | Installable WordPress connector only; no core backend |
| `subsidiaryvault-kms-0.2.0-rc.1-source.zip` | Complete candidate source, docs and tests; private runtime state excluded |
| `component-inventory.json` | Reference image/runtime inventory; not a full transitive/OS SBOM |
| `release-manifest.json`, `SHA256SUMS` | Source/connector/inventory hashes and `production_approved:false` |

ZIP entries have deterministic timestamps. `release-candidate` refuses stale/failing code evidence; checksums establish local integrity, not an authenticated signing authority. Artifacts/provenance are **unsigned**, unpublished and not approved for real data. Full SAST/SCA/image/DAST, signed delivery, complete SBOM and update/rollback acceptance remain unfinished.

```sh
python3 tools/release_gate.py
```

This command **intentionally exits 1** and denies production. The [production exit criteria](docs/release/production-exit-criteria.md) list missing software, persistent deployment/recovery and independent assessments/operational approvals. Do not bypass it or use staging to claim production readiness.

## Requirements, sources and remaining work

All six supplied originals, extracted DOCX/XLSX text and checksums are preserved under [docs/source](docs/source/REVIEW.md). The immutable [canonical JSON](docs/requirements/canonical-requirements.json) contains **346 features in 22 modules**. The master also describes 44 specialist delivery functions, 26 runtime personas and 22 architecture questions; these are not 44 actual independent reviewers or signed human approvals.

Current complete-contract status:

| Status | Requirements |
|---|---:|
| `IN_PROGRESS` — working components, incomplete full acceptance | 116 |
| `NOT_STARTED` | 227 |
| `BLOCKED` — outside dependency plus disclosed unfinished work | 3 |
| `VERIFIED` / `IMPLEMENTED_UNVERIFIED` / `DEFERRED` | 0 / 0 / 0 |

The entire product remains incomplete. Component tests do not promote a partially addressed contract to full `VERIFIED` status.

| Module | Scope | Requirements |
|---|---|---:|
| TEN | Holding structure and tenant isolation | 14 |
| IAM | Membership, roles and authorization | 17 |
| AUT | Authentication, sessions and trusted devices | 13 |
| DOC | Document capture, storage and lifecycle | 22 |
| MET | Metadata, taxonomy and discovery | 16 |
| COL | Editing, collaboration and versioning | 16 |
| KNO | Knowledge, wiki and learning | 16 |
| WFL | Workflows and approvals | 19 |
| REC | Records, retention, holds and discovery | 18 |
| ENC | Encryption, keys and zero-knowledge | 20 |
| SEC | Threat defense and DLP | 16 |
| EXT | External collaboration and exchange | 13 |
| RPT | Audit, reporting and oversight | 14 |
| AIX | Privacy-aware optional AI | 17 |
| L10 | Languages and accessibility | 15 |
| API | APIs, connectors and interoperability | 17 |
| UX | Productivity and workspace experience | 13 |
| MOB | Mobile, desktop and offline | 12 |
| PRV | Privacy, compliance and residency | 14 |
| OPS | Reliability, deployment and quality | 21 |
| MIG | Migration, onboarding and support | 13 |
| VRP | Specialized enterprise modules | 10 |

Project records:

- [Traceability: every feature, status, partial code/evidence and residual risk](docs/requirements/traceability.csv)
- [Module coverage](docs/requirements/feature-coverage.md), [mode compatibility](docs/requirements/mode-compatibility.csv), [persona/action matrix](docs/requirements/permission-matrix.csv) and [compliance uncertainty](docs/requirements/compliance-matrix.csv)
- [Gap register](docs/requirements/gap-register.md), [risk register](docs/requirements/risk-register.md) and [internal ADRs](docs/requirements/decision-log.md)
- [Persistent progress and resume point](docs/implementation/PROGRESS.md), [release gates](docs/release/READINESS.md) and [production prerequisites](infrastructure/production/README.md)

The next core work is accepted privileged identity lifecycle/step-up, persistent least-privilege managed infrastructure and coordinated managed recovery, followed by folder-scoped lifecycle, quarantine, metadata schemas and broader workflow/records functionality. Actual managed reference adapters already exist; they do not close those broader contracts. High-assurance client development requires a public protocol, verified recipient/device identity, signing/update custody and qualified external review. Enterprise expansion must implement the remaining unchanged contracts rather than add placeholder controls.

Reference service software is selected and pinned; no real cloud account, customer service agreement, legal jurisdiction, production domain/signing authority, trademark clearance, privacy approval or deployment has been assumed. Independent human security, cryptographic, legal/privacy, records, accessibility, UAT and disaster-recovery evidence is still required.

## Operations, contribution, support and license

Operational documentation: [self-hosted reference](docs/operations/self-hosted-reference.md), [local/user guide](docs/operations/local-development.md), [API guide](docs/operations/api.md), [WordPress](docs/operations/wordpress.md), [backup/recovery](docs/operations/backup-recovery.md), [incident/support runbook](docs/operations/incident-response.md) and [processing inventory](docs/privacy/data-inventory.md).

Troubleshooting:

| Symptom | Check |
|---|---|
| `invalid_session` | Token expired, was rotated/revoked, or belongs to another test database; obtain a current synthetic token. |
| Empty document list / `acl_denied` | Current membership, classification and explicit document ACL; administrator status alone is insufficient. |
| `step_up_required` | Use an appropriately assured synthetic privileged identity; unconfigured IdP MFA claims are intentionally untrusted. |
| `version_conflict` / `metadata_revision_conflict` | Reload current metadata/version before retrying; do not overwrite another change. |
| `preservation_required` / disposition conflict | Respect holds, record locks, retention and separate approvals; never bypass them to remove an error. |
| `key_unavailable` / integrity error | Stop the affected operation, preserve evidence and follow recovery; do not generate replacement keys over originals. |
| `scanner_database_stale` / scan unavailable | Update actual definitions/check the private scanner; do not disable scanning or silently accept files. |
| `key_service_unavailable` / `object_store_unavailable` | Restore the configured service/credentials; there is no local fallback. Preserve ciphertext and metadata. |
| Empty inbox | Run the bounded worker to drain events, and confirm current document access. |
| Bind failure / port in use | Verify loopback socket permission or choose another local port in the serve command. |

Follow [CONTRIBUTING.md](CONTRIBUTING.md): preserve canonical contracts, add forward migrations and meaningful denial/preservation tests, document actual evidence, and keep credentials, keys, snapshots and real content out of Git. Never equate self-review with external approval. Changes and current release scope are in [CHANGELOG.md](CHANGELOG.md).

For non-sensitive defects, use the repository issue tracker with a synthetic reproduction. A verified private security contact, production support SLA and named maintainer roster have not been supplied; the owner must configure them before real use. See [SECURITY.md](SECURITY.md). Do not publish tokens, keys, company documents or unredacted diagnostics in reports.

Source is distributed under **GPL-2.0-or-later**; see [LICENSE](LICENSE). The WordPress ZIP includes the license. PHP/Sodium and other installed system components retain their respective upstream licenses; no third-party package source is vendored here.

This README was structured after researching [GitHub's README guidance](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes) and [Google's documentation guide](https://google.github.io/styleguide/docguide/READMEs.html), then checked against the implemented repository and executed commands. Additional primary-source engineering references are recorded in [research notes](docs/architecture/references.md).
