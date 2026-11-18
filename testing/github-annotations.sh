#!/bin/bash

# Writes GitHub Actions annotations for a run of testing/run-all-php-versions.sh or run-all-postgresql-versions.sh,
# which (unlike the logs) can be read without logging in to GitHub: a notice with the results of each version and
# PHPStan, and errors listing the failed tests (grouped into at most 9, as a step can only show 10 error annotations),
# or the last lines of output if it failed without listing any (e.g. a fatal error, or a PHPStan error).
# Usage: testing/github-annotations.sh <title> <file with the test output> <the tests' exit code>

set -u

TITLE="$1"
OUTPUT_FILE="$2"
STATUS="$3"
WORK_DIR="$(mktemp -d)"
ESCAPE="$(printf '\033')"


# Print stdin as a single annotation message: GitHub reads %0A as a new line, so % itself must be escaped first.
encodeForAnnotation() {
    sed 's/%/%25/g' | awk '{printf "%s%%0A", $0}'
}


# Remove any colours.
sed -e "s/${ESCAPE}\[[0-9;]*m//g" "${OUTPUT_FILE}" > "${WORK_DIR}/clean.txt"

# Each version's tests, any lint failures, PHPStan's result, and the overall result.
RESULTS_PATTERN="^PHP [0-9.]+, PostgreSQL [^:]+: [0-9]+ passed|^lint failures: [1-9]|\[OK\] No errors|Found [0-9]+ error"
RESULTS_PATTERN="${RESULTS_PATTERN}|^All passed|^FAILED"
RESULTS="$(grep -E "${RESULTS_PATTERN}" "${WORK_DIR}/clean.txt" | encodeForAnnotation)"

echo "::notice title=${TITLE} results::${RESULTS:-no results were found}"

# Each failed test is listed under its PHP or PostgreSQL version's heading, so keep the version with it.
HEADING_AWK='/^################ (PHP|PostgreSQL) / { version = $2 " " $3 ": " }'
awk "${HEADING_AWK}"' /^  FAIL / { sub(/^  FAIL /, ""); print version $0 }' \
    "${WORK_DIR}/clean.txt" | sort -u > "${WORK_DIR}/failures.txt"

TOTAL="$(wc -l < "${WORK_DIR}/failures.txt" | tr -d ' ')"

if [ "${TOTAL}" -gt 0 ]; then
    PER_GROUP="$(( (TOTAL + 8) / 9 ))"
    split -l "${PER_GROUP}" "${WORK_DIR}/failures.txt" "${WORK_DIR}/failure-group-"

    for GROUP in "${WORK_DIR}"/failure-group-*; do
        echo "::error title=${TITLE} failures::$(encodeForAnnotation < "${GROUP}")"
    done
elif [ "${STATUS}" -ne 0 ]; then
    LAST_LINES="$(tail -n 30 "${WORK_DIR}/clean.txt" | encodeForAnnotation)"
    echo "::error title=${TITLE} failed without listing a test::${LAST_LINES}"
fi

rm -rf "${WORK_DIR}"
