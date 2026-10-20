# WordPress connector installation and operation

The connector's only persisted setting is the independent console URL and disclosure acknowledgment. It neither authenticates vault users nor forwards WordPress tokens, proxies content or automatically contacts any remote endpoint.

Build the reproducible plugin archive:

```sh
make package
```

Install `dist/subsidiaryvault-kms-0.1.0.zip` through Plugins → Add New → Upload Plugin, or copy `apps/wordpress-plugin/subsidiaryvault-kms` to `wp-content/plugins`. Activate the connector, open SubsidiaryVault → Connection settings, enter the actual independent HTTPS URL, review its real service/privacy terms, acknowledge the disclosure and save. Ordinary WordPress readers see only the navigation page; connection administration and diagnostics require `manage_options`. WordPress Settings API handles capability checks and CSRF nonces for saving; output is escaped. WP REST cookie authentication/nonces remain WordPress mechanisms only.

The connector rejects embedded credentials, query parameters, fragments, non-HTTPS external URLs and invalid values. For a disposable localhost WordPress setup only, defining both `WP_DEBUG=true` and `SVKMS_ALLOW_LOCAL_CONSOLE=true` permits an HTTP loopback console URL. This is an explicit local exception; never use it for production.

`GET /wp-json/subsidiaryvault-kms/v1/connection` returns configured/consented booleans and version metadata to authorized administrators, with no vault secrets or documents. It has an explicit permission callback. It does not proxy the core API. Deactivation leaves eligible settings intact; uninstall removes only `svkms_connector`. Independent records, holds, identity and keys are not touched.

Compatibility metadata targets WordPress 6.8+ and PHP 8.3+. PHP syntax, URL validation, consent logic, permission callback and ZIP structure have local tests. A live WordPress runtime was not executed: activation/uninstall, actual nonce behavior, REST integration, multisite, Plugin Check, WPCS and public directory acceptance remain unverified. There is no claimed WordPress.org listing, trademark clearance, registered domain, signed update feed or distribution approval.
