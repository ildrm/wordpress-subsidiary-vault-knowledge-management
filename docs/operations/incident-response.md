# Incident and support runbook

INTERNAL/AI DRAFT. Named on-call, security, privacy, legal, records and recovery owners are unassigned. No production service is authorized.

For a suspected WordPress compromise, suspend the connector link, verify the independent vault address through an independent channel, revoke affected vault sessions and preserve both systems' logs separately. WordPress administrators cannot grant themselves document rights through this connector. Assess phishing, copied browser tokens and any authorized downloads; absence of keys in WordPress is not proof that endpoint users were safe.

For vault identity compromise, suspend the relevant tenant membership through another authorized administrator, rotate local tokens, review minimal audit events and current ACL/group membership. Tenant suspension through holding oversight can stop all current tenant requests while preserving documents. Existing downloaded plaintext cannot be recalled. Real IdP/session revocation procedures must be supplied for federation.

For ciphertext corruption, unavailable objects or missing tenant keys, stop dependent operations and preserve the original bytes. Verify storage access, run the audit check, compare authenticated backup manifests and perform an isolated restore. Do not overwrite corrupt evidence, regenerate missing keys, remove holds or purge versions to make errors disappear. Faults return unavailable rather than plaintext or weaker cryptography.

For a preservation/deletion conflict, keep records and holds in place. Obtain counsel/records-owner instructions before any change; no automatic purge or cryptographic erasure exists. A hold's creator cannot release it through their own session. Any future migration or key destruction path must reuse the preservation rules.

For support, collect `php tools/diagnostics.php` output and non-sensitive request IDs/error codes. Manually inspect and redact any material before sharing it. Never send raw demo tokens, identity provider secrets, keys, snapshots, document titles or plaintext. No support impersonation or remote collection endpoint is present.

Record incident time, scope, containment, evidence location, authorized human decisions, customer/legal notification determination, remediation and retest. Do not invent legal deadlines or recipients. A named customer incident owner must define those according to actual jurisdiction and contracts. Preserve legally required evidence and respect independently governed subsidiary access.
