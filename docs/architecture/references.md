# Research and reference decisions

Primary documentation inspected 2026-10-20. Statements in these documents inform design; use is not evidence of compliance or certification.

| Source | Application in this repository |
|---|---|
| [GitHub: About READMEs](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes) | Purpose, practical getting started, support and contribution are placed in the root README; complex technical/operational detail has linked dedicated documents. |
| [Google documentation guide: READMEs](https://google.github.io/styleguide/docguide/READMEs.html) | Overview, setup, development/testing and links are organized for readers entering the repository without chat context. |
| [PHP supported versions](https://www.php.net/supported-versions.php) | Core requires PHP 8.3+ extension APIs; executed version is PHP 8.5.8. CI matrix is configured for 8.3–8.5 but has not run here. Upgrade the actual deployment to an appropriate maintained patch; no current-patch claim is made for the host. |
| [WordPress Settings API](https://developer.wordpress.org/plugins/settings/settings-api/) | Settings API form with capability enforcement, nonce handling by WordPress, sanitize callbacks and escaped output. |
| [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) | Connector route has a capability permission callback; WP cookie/nonces remain only WP controls and are not vault identity. |
| [WordPress custom endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/) | Explicit `permission_callback` for connector diagnostics. |
| [OWASP multi-tenant security](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html) | Verified tenant context, resource scope and scoped policy/worker processing; negative crossover tests. |
| [Sodium AEAD](https://libsodium.gitbook.io/doc/secret-key_cryptography/aead) and [secretstream](https://libsodium.gitbook.io/doc/secret-key_cryptography/secretstream) | Maintained AEAD/stream primitives; random keys/nonces; exact authenticated context; no invented primitive. Application envelopes/framing still need external protocol review. |
| [Sodium upstream tests](https://github.com/jedisct1/libsodium/tree/master/test/default) | Public XChaCha20-Poly1305 known-answer vector and expected ciphertext used for the PHP binding check. |

The source bundle also requests ASVS 5.0, NIST SP 800-63-4, WCAG 2.2, key-management/records standards and applicable laws. Their complete control mapping, current revision applicability, customer obligations and professional assessments are not finished. The compliance matrix explicitly records this uncertainty. No legal jurisdiction is selected by this research.

## Self-hosted reference implementation research

Additional primary documentation inspected 2026-10-20; these inform implementation, not certification:

| Source | Decision/evidence boundary |
|---|---|
| [PostgreSQL row security](https://www.postgresql.org/docs/current/ddl-rowsecurity.html) | FORCE RLS with separate owner/runtime roles, transaction-local context; superuser/BYPASSRLS and owner limitations disclosed. |
| [Keycloak OIDC layers](https://www.keycloak.org/securing-apps/oidc-layers), [OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html), [OAuth security BCP](https://www.rfc-editor.org/rfc/rfc9700.html) | Independent code/PKCE, refresh/revocation, strict issuer/audience/client/subject and live confidential-client introspection. |
| [OpenBao transit API](https://openbao.org/api-docs/secret/transit/) | Non-exportable tenant wrapping roots, restricted policy, version-preserving rotation; no HSM or production custody claim. |
| [libcurl AWS SigV4](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html) | Upstream signing implementation instead of hand-written signing; tested against actual S3-compatible fixture. |
| [SeaweedFS upstream](https://github.com/seaweedfs/seaweedfs) | Pinned actual S3-compatible reference service; production IAM/WORM/durability not implied. |
| [ClamAV scanning](https://docs.clamav.net/manual/Usage/Scanning.html) | Independent INSTREAM scan and version/definition freshness; no quarantine/conversion guarantee. |
| [Gitleaks upstream](https://github.com/gitleaks/gitleaks) | Pinned redacted worktree scan, private generated state and git history outside scope. |

README purpose/status/setup/support/contribution structure was rechecked against GitHub and Google guidance above after implementation. The root document links deeper operations, evidence and complete scope records rather than equating references with implemented features.
