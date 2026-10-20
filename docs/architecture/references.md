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
