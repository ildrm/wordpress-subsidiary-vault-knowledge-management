.PHONY: setup serve test lint validate package
setup:
	php tools/vault.php migrate
	php tools/vault.php seed
serve:
	php -S 127.0.0.1:8088 -t services/core-api/public services/core-api/public/router.php
test:
	php tests/run.php
	php tests/plugin_test.php
	php tests/crypto_backup_test.php
	python3 tests/http_test.py
	python3 tools/validate_requirements.py
lint:
	python3 tools/lint.py
validate: lint test
package:
	python3 tools/package.py
