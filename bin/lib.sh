#!/usr/bin/env bash
# Fonctions communes aux scripts de bin/. Ce fichier est source, jamais execute.

# Docker Compose, quelle que soit la facon dont il est installe.
#
# Trois installations coexistent dans la nature : le plugin v2+ (`docker
# compose`), le binaire autonome v1 (`docker-compose`), et rien du tout. Sans
# cette detection, un serveur ou le plugin manque produit une erreur du CLI
# Docker qui ne nomme ni compose ni sa cause — `unknown shorthand flag: 'd' in
# -d`, parce que Docker lit `-d` comme un drapeau de premier niveau — et fait
# chercher le probleme dans le script plutot que dans l'installation.
compose() {
    if docker compose version >/dev/null 2>&1; then
        docker compose "$@"
    elif command -v docker-compose >/dev/null 2>&1; then
        docker-compose "$@"
    else
        echo "!! Docker Compose est introuvable." >&2
        echo "   Ni le plugin (docker compose) ni le binaire autonome (docker-compose) ne repondent." >&2
        echo "   Verifier : docker --version && docker compose version" >&2
        echo "   Sur Debian/Ubuntu : apt install docker-compose-plugin" >&2
        exit 1
    fi
}

# Docker repond-il ? Un demon arrete ou des droits manquants donnent des
# erreurs deroutantes trois commandes plus loin.
require_docker() {
    command -v docker >/dev/null 2>&1 || { echo "!! docker est introuvable dans le PATH." >&2; exit 1; }
    docker info >/dev/null 2>&1 || {
        echo "!! Le demon Docker ne repond pas." >&2
        echo "   Verifier qu'il tourne (systemctl status docker) et que l'utilisateur est dans le groupe docker." >&2
        exit 1
    }
}

# UID/GID du .env alignes sur le proprietaire reel des fichiers ?
#
# Le conteneur PHP tourne sous `user: ${UID}:${GID}` et ecrit dans le repertoire
# monte : logs/, cache/, vendor/. Si les deux divergent — depot clone en root,
# UID different entre le poste et le serveur — le conteneur boucle sur
# « mkdir: Permission denied » et composer n'arrive pas a creer vendor/.
#
# Le verifier AVANT de monter les conteneurs evite de lire les logs pour
# comprendre.
require_matching_uid() {
    [ -f .env ] || return 0

    local env_uid env_gid owner_uid owner_gid
    env_uid=$(grep -E '^UID=' .env | tail -1 | cut -d= -f2)
    env_gid=$(grep -E '^GID=' .env | tail -1 | cut -d= -f2)
    owner_uid=$(stat -c '%u' .)
    owner_gid=$(stat -c '%g' .)

    # Non renseignes : docker-compose retombe sur 1000:1000 (valeur par defaut
    # du fichier compose).
    env_uid=${env_uid:-1000}
    env_gid=${env_gid:-1000}

    if [ "$env_uid" != "$owner_uid" ] || [ "$env_gid" != "$owner_gid" ]; then
        echo "!! UID/GID incoherents entre .env et les fichiers du projet." >&2
        echo "   .env        : UID=$env_uid GID=$env_gid   (sous lequel tournera le conteneur PHP)" >&2
        echo "   $(pwd) : $owner_uid:$owner_gid" >&2
        echo "" >&2
        echo "   Le conteneur ne pourra ecrire ni dans logs/, ni dans cache/, ni creer vendor/." >&2
        if [ "$owner_uid" != "0" ]; then
            echo "   Corriger l'un ou l'autre :" >&2
            echo "     sed -i 's/^UID=.*/UID=$owner_uid/; s/^GID=.*/GID=$owner_gid/' .env" >&2
            echo "     ou  $(chown_commands "$env_uid" "$env_gid")" >&2
        else
            echo "   Le depot appartient a root : le chown vers un utilisateur non privilegie" >&2
            echo "   plutot que de faire tourner PHP en root." >&2
            echo "     $(chown_commands "$env_uid" "$env_gid")" >&2
        fi
        echo "" >&2
        echo "   Puis RECREER le conteneur : le \`user:\` d'un service n'est lu qu'a sa creation." >&2
        echo "     compose up -d --force-recreate" >&2
        exit 1
    fi
}

# Commandes de chown sures pour ce projet.
#
# Le repertoire de donnees de MariaDB vit DANS l'arborescence
# (DOCKER_DB_DIRECTORY=./.docker/data/mariadb) et appartient au mysql de l'image,
# soit 999:999. Un `chown -R` global sur le projet le lui retirerait et MariaDB
# refuserait de demarrer. Le second chown le rend a son proprietaire.
chown_commands() {
    local uid="$1" gid="$2" datadir
    datadir=$(grep -E '^DOCKER_DB_DIRECTORY=' .env 2>/dev/null | tail -1 | cut -d= -f2)
    datadir=${datadir:-./.docker/data/mariadb}

    printf 'sudo chown -R %s:%s %s' "$uid" "$gid" "$(pwd)"
    # Uniquement si les donnees sont bien sous le projet : hors de l'arborescence,
    # le chown global ne les touche pas et le second geste n'a pas lieu d'etre.
    case "$datadir" in
        ./*|"$(pwd)"/*)
            printf '\n     sudo chown -R 999:999 %s   # MariaDB tourne en 999, ne pas le lui retirer' \
                "${datadir#./}"
            ;;
    esac
}

# `compose up -d --build`, avec le contournement du bug de docker-compose v1.
#
# docker-compose 1.29.2 plante en RECREANT un conteneur dont l'image vient
# d'etre reconstruite :
#
#   container.image_config['ContainerConfig'].get('Volumes') or {}
#   KeyError: 'ContainerConfig'
#
# Les images produites par un Docker recent ne portent plus la clef heritee
# `ContainerConfig`, que compose v1 suppose presente pour retrouver les volumes
# du conteneur existant. La creation initiale passe — il n'y a pas de conteneur
# a comparer — seule la recreation echoue. Le contournement est de supprimer les
# conteneurs d'abord.
#
# `down` ne touche NI aux volumes nommes, NI aux montages : les donnees MariaDB
# vivent dans un bind mount et survivent. Il coupe en revanche le service le
# temps de la recreation, d'ou l'avertissement.
compose_up_build() {
    local sortie
    if sortie=$(compose up -d --build 2>&1); then
        printf '%s\n' "$sortie"
        return 0
    fi

    printf '%s\n' "$sortie" >&2

    if ! printf '%s' "$sortie" | grep -q "ContainerConfig"; then
        return 1
    fi

    echo >&2
    echo "!! docker-compose v1 ne sait pas recreer un conteneur dont l'image vient" >&2
    echo "   d'etre reconstruite (bug connu : KeyError 'ContainerConfig')." >&2
    echo "   Contournement : suppression des conteneurs puis recreation." >&2
    echo "   Les donnees MariaDB ne sont PAS touchees (montage, pas volume nomme)." >&2
    echo >&2

    compose down
    compose up -d --build
}
