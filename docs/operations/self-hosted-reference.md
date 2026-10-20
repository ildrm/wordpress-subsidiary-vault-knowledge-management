# Self-hosted reference stack

Release: `0.2.0-rc.1`. This is a disposable, synthetic integration environment for the user's selected self-hosted target. It is not the persistent, hardened production deployment. Production startup remains disabled and the full product remains incomplete.

## Run the reference checks

Install PHP 8.3+ with `pdo_sqlite`, `pdo_pgsql`, `curl` (libcurl with AWS SigV4), `sodium`, `openssl`, `intl`, `mbstring`, `fileinfo`, `Phar`; Python 3.10+; Node.js; Make; and Docker with Compose v2. No Composer/npm application packages are required. Docker must permit loopback ports and local bind mounts. The ClamAV fixture uses the pinned amd64 image; ARM hosts need Docker emulation. Allow several minutes and sufficient RAM/disk for the images.

```sh
make release-verify
make release-candidate
python3 tools/integration.py down
```

`release-verify` runs local checks, actual service integrations and a source-only Gitleaks scan. `release-candidate` refuses stale/failing code verification; it packages an **unsigned** candidate. No command changes the production gate to approved. `make integration` runs just the reference services/tests; `python3 tools/integration.py wordpress` reruns the WordPress portion. Images and CI actions are pinned; image freshness/security still needs maintenance.

The composition is `infrastructure/integration/compose.yaml`, project `svkms-release-it`. Only that project is stopped by `down`; it never stops unrelated containers. PostgreSQL, identity/key/object services and MariaDB are disposable fixtures (including tmpfs). The synthetic WordPress named volume is retained by ordinary `down`. Do not treat any fixture data or transit roots as durable. Re-running creates fresh test databases, realms and buckets; it does not migrate a customer installation. Private generated credentials/configuration remain under ignored, mode-restricted `var/release-integration/`; do not publish that directory. Delete it only after stopping the stack when you no longer need its synthetic credentials/port assignments.

| Component | Executed scope | Reference-only restrictions |
|---|---|---|
| PostgreSQL | Forward migrations, forced tenant RLS, non-owner `NOBYPASSRLS` API role, ten-tenant isolation, preservation triggers and context reset | A single global transaction advisory lock serializes policy/mutations, so this is not a capacity claim. Fixture migration/role creation uses a separate superuser. |
| Keycloak | Actual authorization code + S256, refresh rotation/replay, revocation and API introspection | `start-dev`; synthetic password-based login, no approved privileged MFA flow or production identity federation. Protocol HTTP harness is not a browser acceptance test. |
| OpenBao | Non-exportable per-tenant transit roots, restricted encrypt/decrypt/read token, wrapping and old-envelope decrypt after root rotation | Dev/unsealed fixture with privileged setup token; no durable seal, HA, HSM, production workload authentication or trustee recovery. |
| SeaweedFS S3 | SigV4 requests, encrypted object roundtrip, conditional-create collision and tenant-prefix rejection | Synthetic admin-capable S3 fixture credential; production per-prefix IAM, delete denial, WORM, versioning and durability are **not** established. |
| ClamAV | Real `VERSION` freshness and `INSTREAM`, clean PNG and EICAR rejection, unavailable/inconclusive fail closed | Freshclam attempts refresh; this environment received CDN HTTP 403. Tests use bundled definitions while within the default 72-hour age limit. Successful automatic updates are not claimed. No quarantine queue or sandboxed previews/conversion. |
| WordPress/MariaDB/WP-CLI | Actual activation, Settings API sanitize/persistence/capabilities, REST admin/subscriber/anonymous checks, deactivation and uninstall | Not multisite, browser nonce/form testing, Plugin Check/WPCS, directory review or compromised-WordPress assessment. |

Ports are bound to `127.0.0.1`; fixture service traffic uses HTTP. Only `SV_ENV=local|integration` plus `SV_ALLOW_INSECURE_TEST_ENDPOINTS=1` allows that exception. The protocol harness adjusts only its generated loopback Secure cookies for Python's cookie-jar behavior; this does not change production cookies. Service-boundary protocol and direct API tests are separate, not proof of a deployed end-to-end production origin.

## Configure the managed adapters

Use [.env.example](../../.env.example) as a configuration inventory, not a ready credential file. Variables must be exported/injected; no dotenv parser is included. `Secrets` supports `NAME_FILE` for DB password, OIDC introspection credentials, key token and S3 credentials. File values take precedence over environment values. Files must be readable by the intended service only; never put real credentials in the repository or command-line arguments. HTTP clients require system-trusted certificates; install the appropriate private CA in that system trust store. PostgreSQL uses its separately configured `sslrootcert` and `sslmode=verify-full`.

`SV_ENV=staging` requires PostgreSQL, `oidc-introspection`, `openbao-transit`, S3 and the scanned profile, HTTPS issuer/key/object URLs, a non-root process and a configured DB username other than `postgres`. These startup checks do not inspect or certify actual DB privileges, TLS ingress, storage IAM or infrastructure hardening. `SV_ENV=production` is rejected. Changing the environment guard does not supply missing software or approval.

Migration uses `php tools/vault.php migrate` under a separate owner/migrator connection. SQLite migrations are 1–4; PostgreSQL migrations are 1–3; version numbers are driver-specific. No automated SQLite-to-PostgreSQL or local-envelope-to-transit migration is supplied. Do not switch providers on existing content and expect automatic recovery. `seed`, `token` and demo rotation are synthetic-development tools, not enterprise provisioning.

Create independent identities with `php tools/identity.php create USER_ID DISPLAY_NAME`, then bind the exact OIDC issuer and subject with `php tools/identity.php bind ISSUER SUBJECT USER_ID`. Run this under a controlled provisioner connection, not the runtime identity. Binding does not grant a membership or document ACL. Runtime roles must not own schema, be superusers, have `BYPASSRLS`, create roles/functions or modify `users`/`external_identities`. See the fixture's explicit grants as a tested starting point, not an approved production policy. The worker alone requires `svkms_pending_events()`; the API can call only the explicitly guarded aggregate posture function. RLS supplements application authorization; a compromised service that can select arbitrary context is outside its boundary.

The independent browser client uses code + PKCE; state/verifier expire after five minutes, callback issuer must match, and codes are removed from the URL immediately. Access/rotating refresh tokens stay in page memory. No token is placed in WordPress or browser storage. The API introspects each token, checks issuer/audience/authorized client/expiry and exact pre-bound subject, then rechecks memberships and ACLs. There is no JIT email-to-user trust. Logout denies the current access hash locally and attempts provider refresh revocation. Reload loses the in-memory refresh credential; provider session/inventory/retention management is not complete.

Privileged OIDC MFA defaults to denied. Only an exact operator-approved `SV_OIDC_MFA_ACR` plus recent integer `auth_time` is considered assured (default five minutes, bounded 30–900 seconds). An actual provider MFA/passkey flow, step-up UI, recovery and signed policy acceptance remain unfinished. The legacy pinned-RS256 adapter is not a substitute.

Provision each transit root separately: `svkms-` plus lowercase SHA-256 of the immutable tenant ID; require `aes256-gcm96`, `exportable=false`, `allow_plaintext_backup=false`, deletion disabled. Runtime needs read of that key's metadata and update on its encrypt/decrypt endpoints, not create/rotate/export/delete/root. Every wrap/unwrap rechecks root policy. The service sees plaintext random data keys and authorized file bytes; this is managed encryption, not zero knowledge or an HSM claim. Rotation must preserve key versions needed by old envelopes.

Configure a private S3 bucket, fixed region/endpoint and narrowly scoped workload identity. Application objects are `tenants/SHA256(tenant)/objects/random-id.bin`; PUT includes `If-None-Match: *`, and there is no application remote-delete path. Fixture credentials are broader than this recommended deployment boundary; actual IAM/delete/WORM policies need independent verification. DB rollback/crash may leave an encrypted remote orphan; an approved preservation-aware reconciliation process is still needed.

`SV_UPLOAD_PROFILE=text` remains the default. `scanned` accepts detected text, JSON/CSV, PDF, PNG and JPEG only, max 8 MiB, after an independent ClamAV freshness/scan response. Other formats, archives, office files, large/resumable uploads, quarantine review, OCR and conversions are unavailable. Connect clamd by a private Unix socket or isolated trusted network; raw clamd TCP has no application TLS/authentication. Never expose its socket to an untrusted network.

## Audit and recovery boundaries

`tools/audit_checkpoint.php create TENANT RAW64_SIGNER_KEY NEW_OUTPUT` signs a canonical tenant head with Ed25519; `verify TENANT RAW32_PUBLIC_KEY CHECKPOINT` verifies the anchored prefix. Supply a separate signer/exporter connection and independent custody for the latest trusted checkpoint/public key. It detects an anchored-prefix rewrite or truncation even if the local chain is recomputed; it does not establish freshness or protect the unanchored window. Scheduling, immutable external sink, timestamping, signer rotation and rollback-resistant checkpoint retrieval remain to be built and operated.

The existing `backup.php` explicitly rejects PostgreSQL, S3 or transit-backed configuration. It cannot make a coordinated managed snapshot, restore Bao/KC state or reconcile current grants/holds. Implement and drill the complete managed backup/restore plan before any real data. See [production exit criteria](../release/production-exit-criteria.md), [readiness](../release/READINESS.md) and [recovery](backup-recovery.md).

Reference network finding: the ClamAV CDN denied definition refresh with HTTP 403 during this run. Resolve approved definition-update access and verify signed refresh before any operational deployment. The software retains the age gate and will refuse scanned uploads when definitions age out. The ARM emulated engine can take several minutes to initialize; reference orchestration now waits for actual scanner PONG before exercising identity protocols. No application timeout or scan control is weakened.
