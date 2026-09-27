#!/usr/bin/env bash
# Runs the RawSearch tests in the dev environment, arguments are passed to PHPUnit (e.g. --filter SearchTest).
set -euo pipefail

cd "$(dirname "$0")"

docker compose run --rm -T \
    -e CRAFT_BASE_PATH=/app/site \
    -e RAWSEARCH_TEST_URL=http://php:8080 \
    php vendor/bin/phpunit -c /plugins/rawsearch/phpunit.xml.dist "$@"
