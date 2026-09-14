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
