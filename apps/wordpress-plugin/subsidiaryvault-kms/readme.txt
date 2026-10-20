=== SubsidiaryVault KMS ===
Tags: document management, knowledge management, multi-tenant, governance
Requires at least: 6.8
Requires PHP: 8.3
Stable tag: 0.2.0-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A low-privilege WordPress document management connector for the independent SubsidiaryVault KMS development service.

== Description ==
SubsidiaryVault supplies navigation to a separately authenticated enterprise document and knowledge workspace. WordPress capabilities never confer vault entitlements. This is a development release, not a production-ready service or an audited zero-knowledge client.

== External services ==
No automatic outbound requests are made. After an administrator configures a console URL and acknowledges the disclosure, users may open that URL in a new tab. Their browser then connects directly to that independent service. Configure and review that deployment's actual privacy notice and terms before use. No third-party provider is bundled or preconfigured. No document tokens or keys belong in this plugin.

== Installation ==
1. Upload subsidiaryvault-kms to wp-content/plugins or install the generated ZIP.
2. Activate the plugin.
3. Configure SubsidiaryVault > Connection settings with your independent HTTPS console URL.
4. Acknowledge the service disclosure and save.
5. Open the independent vault and authenticate there.

For a loopback-only development console, enable WP_DEBUG and explicitly define SVKMS_ALLOW_LOCAL_CONSOLE as true in a disposable WordPress development environment. Never use this exception for a production console.

== Frequently Asked Questions ==
= Does a WordPress administrator see subsidiary documents? =
No automatic document rights are granted. The backend verifies its own identities, memberships and resource permissions.
= Does this provide audited zero-knowledge access? =
No. The independent signed client, recovery protocol and cryptographic assessment remain release gates.
= What happens on uninstall? =
Only the WordPress connection option is removed. Independent vault data and preservation policies are untouched.

== Changelog ==
= 0.1.0 =
Development connector with consented external navigation, safe settings and permission-protected connection diagnostics.
