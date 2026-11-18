#!/bin/bash

# Runs lint and the smoke tests (testing/main.php) on every supported version of PHP, each in its own Docker
# container, against the newest supported PostgreSQL, then PHPStan once. This catches code that only works on newer
# versions of PHP than the minimum this package supports. run-all-postgresql-versions.sh tests every PostgreSQL.
#
# Usage:
#   testing/run-all-php-versions.sh                # test every supported version
#   testing/run-all-php-versions.sh 8.3 8.5        # test only these versions
#   testing/run-all-php-versions.sh --rebuild      # rebuild the Docker images first (e.g. for the latest patch release)
#
# Requires Docker, and "composer install" to have been run. Set PGSQL_LOGGER_TEST_ONLY to run only the tests whose name
# contains it. Exits with 0 if every version passed, or 1 if any failed.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/docker-helpers.sh"

SUPPORTED_VERSIONS="8.2 8.3 8.4 8.5"
POSTGRESQL_VERSION=18
rebuild=0
versions=""

for arg in "$@"
do
    if [ "$arg" = "--rebuild" ]
    then
        rebuild=1
    else
        versions="$versions $arg"
    fi
done

if [ -z "$versions" ]
then
    versions=$SUPPORTED_VERSIONS
fi

requireVendor
trap cleanUp EXIT
failedVersions=""
phpstanFailed=0

if ! startDatabase "${POSTGRESQL_VERSION}"
then
    echo "FAILED to start PostgreSQL ${POSTGRESQL_VERSION}"
    exit 1
fi

for version in $versions
do
    echo
    echo "################ PHP ${version}"

    if ! buildPhpImage "$version" "$rebuild"
    then
        echo "FAILED to build the image for PHP ${version}"
        failedVersions="$failedVersions $version"
    elif ! runTests "$version"
    then
        failedVersions="$failedVersions $version"
    fi
done

# Static analysis only needs to run once. It runs on the lowest version tested, as that is the most likely to differ.
lowestVersion=$(echo $versions | tr ' ' '\n' | sort -V | head -n 1)
echo
echo "################ PHPStan (PHP ${lowestVersion})"

if ! runPhpStan "${lowestVersion}"
then
    phpstanFailed=1
fi

echo
if [ -z "$failedVersions" ] && [ "$phpstanFailed" -eq 0 ]
then
    echo "All passed:" $versions
    exit 0
else
    [ -z "$failedVersions" ] || echo "FAILED on PHP:" $failedVersions
    [ "$phpstanFailed" -eq 0 ] || echo "FAILED PHPStan"
    exit 1
fi
