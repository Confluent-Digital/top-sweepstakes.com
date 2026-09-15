#!/bin/sh
# Entrypoint du conteneur PHP.
#
# L'entrypoint de l'image de base chmod des chemins propres aux landing pages
# (public/__gestion/config, src/Controllers/user) qui n'existent pas ici : avec
# `set -e` il sortirait en erreur avant meme de lancer php-fpm.
set -e

cd "$(printenv PWD)"

# Le conteneur tourne sous l'UID:GID lus dans le .env (`user:` du service). Si
# les fichiers du projet appartiennent a quelqu'un d'autre — un depot clone en
# root, un UID different entre deux machines — rien n'est ecrivable, et le
# symptome brut est une pluie de « mkdir: Permission denied » suivie d'un
# composer qui n'arrive pas a creer vendor/. Avec `restart: unless-stopped`,
# cela tourne en boucle sans jamais nommer la cause.
if [ ! -w . ]; then
    OWNER="$(stat -c '%u:%g' . 2>/dev/null || echo 'inconnu')"
    MODE="$(stat -c '%a' . 2>/dev/null || echo '???')"
    ME="$(id -u):$(id -g)"
    {
        echo "[entrypoint] ERREUR : $(pwd) n'est pas accessible en ecriture."
        echo "[entrypoint]   le conteneur tourne en     $ME"
        echo "[entrypoint]   le repertoire appartient a $OWNER (droits $MODE)"
        echo "[entrypoint]"
        if [ "$OWNER" = "$ME" ]; then
            # Meme proprietaire : ce sont les droits qui manquent, pas
            # l'appartenance. Conseiller un chown ici enverrait sur une fausse
            # piste.
            echo "[entrypoint] Le proprietaire est le bon : ce sont les DROITS qui manquent."
            echo "[entrypoint] Sur l'HOTE :  chmod u+rwX ."
        else
            echo "[entrypoint] Les deux doivent coincider. Sur l'HOTE :"
            echo "[entrypoint]   sudo chown -R $ME .   # aligner les fichiers sur le conteneur"
            echo "[entrypoint]   sudo chown -R 999:999 .docker/data/mariadb   # SAUF les donnees MariaDB"
            if [ "${OWNER%%:*}" != "0" ]; then
                # L'inverse — aligner le conteneur sur les fichiers — n'est
                # propose que si le proprietaire n'est pas root : mettre UID=0
                # ferait tourner php-fpm en root, ce qu'on ne conseille pas pour
                # se sortir d'un probleme de droits.
                echo "[entrypoint]   ou renseigner UID=${OWNER%%:*} et GID=${OWNER##*:} dans .env,"
                echo "[entrypoint]      puis recreer le conteneur (compose up -d --force-recreate)."
            else
                echo "[entrypoint]   (le depot appartient a root : le cloner ou le chown vers un"
                echo "[entrypoint]    utilisateur non privilegie, plutot que de faire tourner PHP en root)"
            fi
        fi
    } >&2
    exit 1
fi

mkdir -p logs cache/twig cache/legal cache/readiness
chmod -R 777 logs cache 2>/dev/null || true

if [ -f "composer.json" ] && [ ! -d "vendor" ]; then
    echo "[entrypoint] composer install"
    composer install --no-interaction --prefer-dist
fi

exec "$@"
