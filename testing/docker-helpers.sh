#!/bin/bash

# Shared by run-all-php-versions.sh and run-all-postgresql-versions.sh: builds the PHP test images, and runs the tests
# in them against a throwaway PostgreSQL container, which keeps its data in memory. Both are on a Docker network of
# their own with no internet access (--internal), so the tests can only reach each other.

TESTING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "${TESTING_DIR}")"
NETWORK="pgsql-logger-test-$$"
DB_CONTAINER="pgsql-logger-test-db-$$"

# Stop Git Bash on Windows rewriting arguments that look like paths. Other systems ignore it.
export MSYS_NO_PATHCONV=1


# Remove the database container and the network, e.g. when the script exits.
cleanUp() {
    docker rm --force "${DB_CONTAINER}" > /dev/null 2>&1
    docker network rm "${NETWORK}" > /dev/null 2>&1
}


# Build the image for a version of PHP, unless it exists (or $2 is 1, to rebuild it). Returns non-zero if it fails.
buildPhpImage() {
    local version="$1"
    local rebuild="$2"
    local image="pgsql-logger-php${version}-test"

    if [ "${rebuild}" -eq 1 ] || ! docker image inspect "${image}" > /dev/null 2>&1
    then
        echo "building ${image}..."
        docker build -q --pull --build-arg PHP_VERSION="${version}" -t "${image}" "${TESTING_DIR}/php-versions" \
            > /dev/null
    fi
}


# Start a PostgreSQL container of the given major version on the network, replacing any from before, and wait up to a
# minute for it to accept queries. pg_isready alone isn't enough, as the image's entrypoint restarts the server once
# it has set up the database. Returns non-zero if it doesn't start.
startDatabase() {
    local version="$1"
    local attempts=0

    docker rm --force "${DB_CONTAINER}" > /dev/null 2>&1
    docker network inspect "${NETWORK}" > /dev/null 2>&1 || docker network create --internal "${NETWORK}" > /dev/null

    docker run --detach --rm --name "${DB_CONTAINER}" --network "${NETWORK}" \
        --env POSTGRES_USER=test --env POSTGRES_PASSWORD=test --env POSTGRES_DB=test \
        --tmpfs /var/lib/postgresql "postgres:${version}" > /dev/null || return 1

    until docker exec "${DB_CONTAINER}" psql -U test -d test -h 127.0.0.1 -c 'SELECT 1' > /dev/null 2>&1
    do
        attempts="$((attempts + 1))"
        [ "${attempts}" -lt 60 ] || return 1
        sleep 1
    done
}


# Run lint and the smoke tests on a version of PHP, against the database. Returns non-zero if they fail.
runTests() {
    local version="$1"

    docker run --rm --network "${NETWORK}" -v "${REPO_DIR}":/src:ro \
        --env TEST_DB_HOST="${DB_CONTAINER}" --env TEST_DB_NAME=test --env TEST_DB_USER=test \
        --env TEST_DB_PASSWORD=test --env PGSQL_LOGGER_TEST_ONLY="${PGSQL_LOGGER_TEST_ONLY:-}" \
        "pgsql-logger-php${version}-test" sh /src/testing/php-versions/run-in-container.sh
}


# Run PHPStan on a version of PHP. Returns non-zero if it finds any errors.
runPhpStan() {
    local version="$1"

    docker run --rm --network none -v "${REPO_DIR}":/src:ro "pgsql-logger-php${version}-test" \
        sh -c 'cp -r /src /app && cd /app && php vendor/bin/phpstan --no-progress --memory-limit=1G'
}


# Check composer install has been run, as the tests use the vendor/ directory.
requireVendor() {
    if [ ! -f "${REPO_DIR}/vendor/autoload.php" ]
    then
        echo "vendor/autoload.php is missing. Please run \"composer install\" first."
        exit 1
    fi
}
