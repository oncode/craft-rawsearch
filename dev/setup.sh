#!/usr/bin/env bash
# Creates a Craft project in dev/site, installs RawSearch from the parent directory and adds demo content.
# Usage: ./setup.sh (port: RAWSEARCH_PORT=8090 ./setup.sh)
set -euo pipefail

cd "$(dirname "$0")"
PORT="${RAWSEARCH_PORT:-8088}"
run() { docker compose run --rm -T php "$@"; }

if [ -e site ]; then
    echo "dev/site exists already. Remove it (and the database: docker compose down -v) to set up again."
    exit 1
fi

docker compose build
docker compose up -d db

echo "Creating the Craft project ..."
mkdir site
run sh -c 'composer create-project craftcms/craft /tmp/craft --no-interaction --quiet && cp -a /tmp/craft/. /app/site/'

# the demo templates and the router for PHP's built-in server live in dev/
rm -rf site/templates
ln -s ../templates site/templates
cp router.php site/web/router.php

cat > site/.env <<ENV
CRAFT_APP_ID=rawsearch-dev
CRAFT_ENVIRONMENT=dev
CRAFT_SECURITY_KEY=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)
CRAFT_DB_DRIVER=mysql
CRAFT_DB_SERVER=db
CRAFT_DB_PORT=3306
CRAFT_DB_DATABASE=craft
CRAFT_DB_USER=craft
CRAFT_DB_PASSWORD=craft
CRAFT_DB_SCHEMA=
CRAFT_DB_TABLE_PREFIX=
CRAFT_DEV_MODE=true
CRAFT_ALLOW_ADMIN_CHANGES=true
CRAFT_DISALLOW_ROBOTS=true
PRIMARY_SITE_URL=http://localhost:${PORT}
ENV

echo "Waiting for the database ..."
until docker compose exec -T db mysqladmin ping -ucraft -pcraft --silent >/dev/null 2>&1; do sleep 2; done
sleep 3

echo "Installing Craft and RawSearch ..."
run composer config repositories.rawsearch '{"type":"path","url":"/plugins/rawsearch","options":{"symlink":true}}'
run composer config minimum-stability dev
run composer config prefer-stable true
run composer require "oncode/craft-rawsearch:@dev" --no-interaction --quiet
run composer require --dev "phpunit/phpunit:^11" --no-interaction --quiet
run php craft install --interactive=0 --username=admin --password=password123 --email=admin@example.com \
    --site-name="RawSearch Dev" --site-url="http://localhost:${PORT}" --language=en
run php craft plugin/install rawsearch

echo "Adding demo content ..."
run php /app/seed.php
run php craft queue/run >/dev/null

docker compose up -d php

echo
echo "Done: http://localhost:${PORT}/search"
echo "Control panel: http://localhost:${PORT}/admin (admin / password123)"
echo "Tests: ./test.sh"
