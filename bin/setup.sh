#!/usr/bin/env bash
# Premiere installation : conteneurs, dependances, schema.
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { cp .env.example .env; echo "!! .env cree depuis .env.example — renseigner APP_SECRET et les mots de passe avant de continuer"; exit 1; }

docker compose up -d --build
echo "Attente de MariaDB..."
until docker exec topsweepstakes_mariadb mariadb-admin ping --silent >/dev/null 2>&1; do sleep 2; done

docker exec topsweepstakes_php composer install --no-interaction
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev

PORT=$(grep -E '^DOCKER_NGINX_PORT=' .env | cut -d= -f2)
echo "Pret : http://127.0.0.1:${PORT}/health"
