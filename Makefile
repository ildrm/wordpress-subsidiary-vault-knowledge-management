.PHONY: setup serve test lint validate package integration secret-scan release-verify release-candidate
setup:
	php tools/vault.php migrate
	php tools/vault.php seed
serve:
	php -S 127.0.0.1:8088 -t services/core-api/public services/core-api/public/router.php
test:
	php tests/run.php
	php tests/plugin_test.php
	php tests/crypto_backup_test.php
	php tests/audit_checkpoint_test.php
	php tests/config_test.php
	node tests/oidc_browser_test.mjs
	python3 tests/http_test.py
	python3 tools/validate_requirements.py
lint:
	python3 tools/lint.py
validate: lint test
package:
	python3 tools/package.py
integration:
	python3 tools/integration.py test
secret-scan:
	python3 tools/secret_scan.py
release-verify:
	python3 tools/verify_release.py --integration --secret-scan
release-candidate:
	python3 tools/release_bundle.py
