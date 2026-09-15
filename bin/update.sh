#!/usr/bin/env bash
# Mise a jour d'un environnement existant.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=bin/lib.sh
source "$(dirname "$0")/lib.sh"

require_docker
require_matching_uid

git pull --ff-only
docker exec topsweepstakes_php composer install --no-interaction --no-dev --optimize-autoloader
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e prod
rm -rf cache/twig/*
echo "Mise a jour terminee."
