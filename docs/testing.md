# Testing

The tests are smoke tests (no PHPUnit), in `testing/tests/`, run by `testing/main.php` against a throwaway PostgreSQL
database. They only create and drop tables and types whose names start with `logger_test_` (and, for the docs'
examples, the default `log` table and `log_level` type), but use a database you don't mind losing anyway.

With Docker, run everything the GitHub workflow runs, after `composer install`:

```bash
# Lint and the tests on every supported version of PHP, against the newest PostgreSQL, then PHPStan.
testing/run-all-php-versions.sh

# Just some versions, or with the images rebuilt (e.g. for the latest patch releases).
testing/run-all-php-versions.sh 8.2 8.5
testing/run-all-php-versions.sh --rebuild

# The tests against every supported version of PostgreSQL, on the lowest supported PHP.
testing/run-all-postgresql-versions.sh
testing/run-all-postgresql-versions.sh 14 18

# Only the tests whose name contains some text.
PGSQL_LOGGER_TEST_ONLY=reconnect testing/run-all-php-versions.sh 8.5
```

Or run them directly, against a database of your own:

```bash
TEST_DB_HOST=localhost TEST_DB_NAME=test TEST_DB_USER=test TEST_DB_PASSWORD=test php -d zend.assertions=1 testing/main.php
```

A test fails if it throws, fails an `assert()`, or raises any warning, notice or deprecation, and `main.php` refuses
to run with `zend.assertions` off, so the asserts can't silently do nothing. The examples in the README and every page
in `docs/` are run too (`testing/tests/DocsTest.php`), so the documentation can't drift from what the code does.
