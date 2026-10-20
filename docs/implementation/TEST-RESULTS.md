# Executed verification — 2026-10-20

INTERNAL/AI TEST EVIDENCE ONLY. Local macOS ARM64; PHP 8.5.8; Python 3.14.5; Node.js 26.12.0. No actual customer data or externally supplied production secrets were used.

| Command / check | Actual result | Scope |
|---|---|---|
| `make validate` | Exit 0 | PHP/JS syntax, domain/security, connector harness, crypto/worker/recovery, real loopback HTTP and requirement registry |
| `php tests/run.php` | 21 groups, 1,058 assertions passed | Ten-tenant foreign-reference matrix; independent administration; view/download split; classification/search; explicit denies; AEAD version/context/tamper; stale versions; workflow self-approval; hold/record/retention; disposition separation; current membership/expiry; independent multi-tenant membership; role escalation; upload quotas; knowledge/comments; rate fairness; DB immutability; pinned RS256 confusion; metadata revisions; idempotent provisioning and suspension |
| `php tests/plugin_test.php` | 11 assertions passed | Small WordPress API harness: URL/credential rejection, consent, administrator capability, REST permission callback |
| `php tests/crypto_backup_test.php` | 62 assertions passed | Public Sodium known-answer vector; file sizes 0/1/65,535/65,536/65,537/200,000; wrong key/AAD; truncation/no overwrite/no partial published plaintext; notification retry/revocation; encrypted snapshot and isolated restored decrypt; old sessions revoked; key outage; restored audit chains |
| `python3 tests/http_test.py` | 23 assertions passed | Actual ephemeral loopback server: HTML/assets, CSP/frame/no-store/noindex, protected filesystem routes, authentication, BOLA, viewer download denial, real decrypt, invalid JSON/type, query-token rejection and revocation |
| `python3 tools/lint.py` | 23 PHP files and console JS syntax passed | Syntax only, not static security analysis |
| `python3 tools/validate_requirements.py` | Passed | 346 immutable contracts and source hash/equality; all module counts; traceability/mode coverage; 26 personas; 22 ADRs; 13 draft catalog parity |
| `make package` | Exit 0 | Installable connector directory ZIP, deterministic entries and checksum |
| `python3 tools/release_gate.py --development` | Exit 0 | ZIP scope and secret-file exclusions; does not approve production |
| `python3 tools/release_gate.py` | Exit 1, intentionally DENIED | All 346 full acceptance cases incomplete; external gates unresolved |

Total executed automated assertions across the four suites: **1,154**. They are not 1,154 completed features, full requirement acceptance, a penetration test or cryptographic certification. The traceability ledger deliberately keeps full canonical `VERIFIED` at 0/346.

Browser checks performed through native Firefox/Chrome: independent synthetic sign-in, scoped document listing and metadata, document details/permission/governance form rendering, saving metadata through the current API, and Persian navigation/table direction and locale date rendering. An actual browser caught the textarea `type` assignment error; it was corrected and the corrected document/forms rendered in Chrome. Persian was visually inspected on desktop. Complete mobile/Arabic/all-language approval workflows, assistive technology and automated browser regression coverage remain unperformed. Screenshots were inspected interactively; no screenshot artifact is falsely claimed as committed.

Not executed: live WordPress activation/uninstall/multisite/REST/nonce integration; WordPress Plugin Check/WPCS; Docker build/runtime; GitHub Actions matrix; PostgreSQL/RLS/S3/KMS/IdP integration; native signing or recipient protocol; SAST/SCA/secret/DAST/scanning pipeline; scanner/conversion threats; large-object/concurrency/performance/geographic failover benchmarks; independent security/cryptographic/a11y assessments; human UAT/legal/privacy/records/DR approvals.

The first local listen attempt was denied by the managed sandbox; the loopback development server and disposable HTTP tests subsequently ran with approved execution access. Docker's socket was inaccessible and no container result is claimed. Automated tests create private temporary fixtures and clean their own directories; they do not modify the user's local seeded workspace.
