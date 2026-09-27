#!/usr/bin/env bash
# Creates a Craft 6 project in dev/site, installs RawSearch from the parent directory and adds demo content.
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
# without scripts, the starter would run the interactive installer before the database is configured
run sh -c 'composer create-project "craftcms/craft:^6.0.0-alpha" /tmp/craft --stability=alpha --no-scripts --no-interaction --quiet && cp -a /tmp/craft/. /app/site/'
run sh -c 'cp .env.example .env && php artisan key:generate --ansi && composer run-script post-autoload-dump --quiet'

# the demo templates live in dev/templates
rm -rf site/resources/views
ln -s ../../templates site/resources/views

sed -i.bak \
    -e "s#^APP_URL=.*#APP_URL=http://localhost:${PORT}#" \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' \
    -e 's/^DB_HOST=.*/DB_HOST=db/' \
    -e 's/^DB_PORT=.*/DB_PORT=3306/' \
    -e 's/^DB_DATABASE=.*/DB_DATABASE=craft/' \
    -e 's/^DB_USERNAME=.*/DB_USERNAME=craft/' \
    -e 's/^DB_PASSWORD=.*/DB_PASSWORD=craft/' \
    site/.env
rm site/.env.bak

echo "Waiting for the database ..."
until docker compose exec -T db mysqladmin ping -ucraft -pcraft --silent >/dev/null 2>&1; do sleep 2; done
sleep 3

echo "Installing Craft and RawSearch ..."
run composer config repositories.rawsearch '{"type":"path","url":"/plugins/rawsearch","options":{"symlink":true}}'
run composer config minimum-stability alpha
run composer config prefer-stable true
run composer require "oncode/craft-rawsearch:@dev" --no-interaction --quiet
run php artisan craft:install -n --username=admin --password=password123 --email=admin@example.com \
    --siteName="RawSearch Dev" --siteUrl="http://localhost:${PORT}" --language=en
run php craft plugin/install rawsearch

echo "Adding demo content ..."
run php /app/seed.php
run php artisan queue:work --stop-when-empty --quiet

docker compose up -d php

echo
echo "Done: http://localhost:${PORT}/search"
echo "Control panel: http://localhost:${PORT}/admin (admin / password123)"
echo "Tests: ./test.sh"
