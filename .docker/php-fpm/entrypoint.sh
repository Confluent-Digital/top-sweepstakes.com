#!/bin/sh
set -e

cd "$(printenv PWD)"

mkdir -p logs cache/twig
chmod -R 777 logs cache 2>/dev/null || true

if [ -f "composer.json" ] && [ ! -d "vendor" ]; then
    echo "[entrypoint] composer install"
    composer install --no-interaction --prefer-dist
fi

exec "$@"
