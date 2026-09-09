#!/usr/bin/env bash
# Lint un fichier PHP avec le PHP du CONTENEUR quand il tourne.
#
# Le PHP de l'hote et celui du conteneur ne sont pas de la meme version : 8.3
# contre 8.4. Une syntaxe valide en 8.4 (`new Foo()->bar()`) est une erreur de
# parse en 8.3. Linter avec l'hote produirait des faux positifs bloquants sur
# du code parfaitement correct pour le runtime reel.
tsw_php_lint() {
    local file="$1"
    local container="topsweepstakes_php"
    local root="${CLAUDE_PROJECT_DIR:-/data/www/top-sweepstakes.com}"

    if docker exec "$container" true >/dev/null 2>&1; then
        # Le projet est monte au meme chemin dans le conteneur qu'a l'exterieur.
        local in_container="$file"
        case "$file" in
            /*) ;;
            *) in_container="$root/$file" ;;
        esac
        docker exec "$container" php -l "$in_container" 2>&1
        return $?
    fi

    command -v php >/dev/null 2>&1 || return 0
    php -l "$file" 2>&1
}
