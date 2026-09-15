#!/usr/bin/env bash
# Premiere installation : conteneurs, dependances, schema.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=bin/lib.sh
source "$(dirname "$0")/lib.sh"

# Ce script installe un environnement de DEVELOPPEMENT : il cree le .env, monte
# les conteneurs et migre. Une mise a jour de production passe par update.sh.
require_docker

# Le .env cree porte les UID/GID REELS de l'utilisateur, pas les 1000 du
# modele : c'est ce qui evite que le conteneur PHP, qui tourne sous ces
# identifiants, ne puisse ecrire ni dans logs/, ni dans cache/, ni creer vendor/.
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i "s/^UID=.*/UID=$(id -u)/; s/^GID=.*/GID=$(id -g)/" .env
    echo "!! .env cree depuis .env.example (UID=$(id -u) GID=$(id -g))"
    echo "   Renseigner APP_SECRET et les mots de passe avant de continuer."
    exit 1
fi

require_matching_uid

compose_up_build
echo "Attente de MariaDB..."
until docker exec topsweepstakes_mariadb mariadb-admin ping --silent >/dev/null 2>&1; do sleep 2; done

docker exec topsweepstakes_php composer install --no-interaction
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev

PORT=$(grep -E '^DOCKER_NGINX_PORT=' .env | cut -d= -f2)
echo "Pret : http://127.0.0.1:${PORT}/health"
