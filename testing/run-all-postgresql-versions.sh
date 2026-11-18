#!/bin/bash

# Runs the smoke tests (testing/main.php) against every supported major version of PostgreSQL, each in its own
# throwaway container, on the lowest supported version of PHP. run-all-php-versions.sh tests every PHP version, and
# PHPStan.
#
# Usage:
#   testing/run-all-postgresql-versions.sh           # test every supported version
#   testing/run-all-postgresql-versions.sh 16 17     # test only these versions
#
# Requires Docker, and "composer install" to have been run. Exits with 0 if every version passed, or 1 if any failed.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/docker-helpers.sh"

# PostgreSQL's supported major versions, see https://www.postgresql.org/support/versioning/
SUPPORTED_VERSIONS=(14 15 16 17 18)
PHP_VERSION=8.2

if [ "$#" -gt 0 ]
then
    VERSIONS=("$@")
else
    VERSIONS=("${SUPPORTED_VERSIONS[@]}")
fi

requireVendor
trap cleanUp EXIT
failedVersions=()

if ! buildPhpImage "${PHP_VERSION}" 0
then
    echo "FAILED to build the image for PHP ${PHP_VERSION}"
    exit 1
fi

for version in "${VERSIONS[@]}"
do
    echo
    echo "################ PostgreSQL ${version}"

    if ! startDatabase "${version}"
    then
        echo "FAILED to start PostgreSQL ${version}"
        failedVersions+=("${version}")
    elif ! runTests "${PHP_VERSION}"
    then
        failedVersions+=("${version}")
    fi
done

echo
if [ "${#failedVersions[@]}" -eq 0 ]
then
    echo "All passed: PostgreSQL ${VERSIONS[*]}"
    exit 0
else
    echo "FAILED on PostgreSQL: ${failedVersions[*]}"
    exit 1
fi
