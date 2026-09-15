#!/usr/bin/env bash
# Premiere installation d'une PRODUCTION.
#
# setup.sh installe un environnement de developpement : il pose les outils de
# test et migre en -e dev. update.sh met a jour un environnement qui tourne
# deja. Ce troisieme cas — un serveur vierge qui doit servir du trafic reel —
# n'avait pas de chemin, et se bricolait donc a la main.
#
# Ce script ne seme AUCUNE donnee : les seeds publient des concours de
# demonstration avec des identifiants de regie factices. Les concours reels se
# creent depuis le back-office.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=bin/lib.sh
source "$(dirname "$0")/lib.sh"

echo "== Verifications prealables"
require_docker
[ -f .env ] || { echo "!! .env introuvable. Le creer depuis .env.example et le renseigner." >&2; exit 1; }
require_matching_uid

lire() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"'"'"' '; }

manquant=0
verifier() {
    if [ -z "$(lire "$1")" ]; then
        echo "!! $1 est vide dans .env — $2" >&2
        manquant=1
    fi
}

# APP_SECRET signe les jetons de sortie vers la regie (Signer::sign). Vide,
# Config::require leve — mais autant le dire ici, avant de monter quoi que ce
# soit.
verifier APP_SECRET   "genere avec : php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'"
verifier DB_PASSWORD  "mot de passe de l'utilisateur applicatif"
verifier DB_ROOT_PASSWORD "mot de passe root de MariaDB"
verifier APP_DOMAIN   "nom de domaine servi, utilise par le vhost et les liens"
[ "$manquant" = "0" ] || exit 1

APP_ENV=$(lire APP_ENV)
if [ "$APP_ENV" != "production" ]; then
    echo "!! APP_ENV vaut « ${APP_ENV:-vide} » et non « production »." >&2
    echo "   C'est cette valeur qui decide du mode composer (--no-dev) et du cache Twig." >&2
    echo "   La corriger AVANT le premier demarrage : le vendor/ installe ne sera pas refait." >&2
    exit 1
fi

echo "== Conteneurs"
compose_up_build

echo "== Attente de MariaDB"
until docker exec topsweepstakes_mariadb mariadb-admin ping --silent >/dev/null 2>&1; do sleep 2; done

# La base est creee par l'entrypoint de l'image a partir de MARIADB_DATABASE.
# Le verifier plutot que le supposer : un conteneur demarre une premiere fois
# sans cette variable garderait un volume sans base, et la migration echouerait
# sur un message peu parlant.
DB_NAME=$(lire DB_NAME)
if ! docker exec topsweepstakes_mariadb sh -c \
    "mariadb -u root -p\"\$MARIADB_ROOT_PASSWORD\" -N -B -e 'SHOW DATABASES' 2>/dev/null" | grep -qx "$DB_NAME"; then
    echo "!! La base $DB_NAME n'existe pas." >&2
    echo "   Elle est creee au PREMIER demarrage du conteneur a partir de DB_NAME." >&2
    echo "   Si le volume a ete initialise auparavant, la creer a la main :" >&2
    echo "     docker exec -it topsweepstakes_mariadb mariadb -u root -p" >&2
    echo "     CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >&2
    exit 1
fi
echo "   base $DB_NAME presente"

echo "== Dependances"
docker exec topsweepstakes_php composer install --no-interaction --no-dev --optimize-autoloader

echo "== Schema"
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e prod

echo "== Compte de back-office"
if [ "$(docker exec topsweepstakes_php php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$c = new App\Core\Config($_ENV);
$db = new App\Core\Database($c);
echo (int) $db->connection()->fetchOne("SELECT COUNT(*) FROM t_admin_user");
' 2>/dev/null)" = "0" ]; then
    echo "   aucun compte. En creer un (le mot de passe est demande, sans echo) :"
    echo "     docker exec -it topsweepstakes_php php bin/cli.php admin:create \\"
    echo "       --email=vous@confluent-digital.com --name='Votre nom'"
else
    echo "   un compte existe deja"
fi

PORT=$(lire DOCKER_NGINX_PORT)
cat <<FIN

== Installe.

  Sonde   : curl -s http://127.0.0.1:${PORT}/health
  Vhost   : ./bin/make-vhost.sh && sudo systemctl reload nginx
  Ouvrir  : /admin/readiness — ce qui reste a faire avant d'ouvrir au trafic

AUCUNE donnee n'a ete semee : les concours se creent depuis /admin/sweepstakes.
Les seeds publient des concours de demonstration et sont refuses en production.
FIN
