#!/usr/bin/env bash
# Genere le vhost nginx de l'HOTE, qui relaie vers le conteneur.
#
# Le site tourne derriere deux nginx : celui du conteneur, qui parle a PHP-FPM
# et n'ecoute que sur 127.0.0.1:<DOCKER_NGINX_PORT>, et celui de l'hote, qui
# porte le nom de domaine et relaie. Ce script ecrit le second.
#
#   ./bin/make-vhost.sh                  # ecrit dans /data/nginx/
#   ./bin/make-vhost.sh --print          # affiche sans rien ecrire
#   ./bin/make-vhost.sh --dir /etc/nginx/sites-enabled
#   ./bin/make-vhost.sh --force          # remplace sans demander
set -euo pipefail
cd "$(dirname "$0")/.."

DEFAUT=/data/nginx
DIR=$DEFAUT
FORCE=0
PRINT=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)   DIR="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --print) PRINT=1; shift ;;
        -h|--help) awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"; exit 0 ;;
        *) echo "Option inconnue : $1" >&2; exit 1 ;;
    esac
done

[ -f .env ] || { echo "!! .env introuvable : le domaine et le port s'y lisent." >&2; exit 1; }

read_env() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"'"'"' '; }

DOMAIN=$(read_env APP_DOMAIN)
PORT=$(read_env DOCKER_NGINX_PORT)
# Valeur a DROITE du signe egal : un filtre sur les caracteres attraperait
# aussi le « m » de post_max_size.
UPLOAD=$(grep -E '^post_max_size' .docker/php-fpm/uploads.ini | cut -d= -f2 | tr -d ' ')
UPLOAD=${UPLOAD:-12M}

[ -n "$DOMAIN" ] || { echo "!! APP_DOMAIN est vide dans .env." >&2; exit 1; }
[ -n "$PORT" ]   || { echo "!! DOCKER_NGINX_PORT est vide dans .env." >&2; exit 1; }

FILE="$DIR/$DOMAIN.conf"

# La limite de taille du proxy doit au moins egaler celle de PHP : en dessous,
# nginx refuse l'envoi par un 413 avant que PHP ne voie le fichier, et le
# televersement d'un visuel de concours echoue sans message exploitable.
VHOST=$(cat <<EOF
# ${DOMAIN} — genere par bin/make-vhost.sh, ne pas editer a la main.
#
# Ce vhost ne sert aucun fichier : il relaie vers le nginx du conteneur, qui
# ecoute sur 127.0.0.1:${PORT} et parle a PHP-FPM. La racine du site vit dans le
# conteneur, pas ici.

server {
    listen 80;
    listen [::]:80;

    server_name ${DOMAIN} www.${DOMAIN};

    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log  /var/log/nginx/${DOMAIN}_error.log warn;

    # Au moins autant que post_max_size cote PHP (${UPLOAD}) : en dessous, nginx
    # repond 413 avant que PHP ne voie le fichier, et le televersement d'un
    # visuel de concours echoue sans message exploitable.
    client_max_body_size ${UPLOAD};

    # Validation Let's Encrypt, servie par l'hote : elle doit rester joignable
    # en clair meme une fois le 443 en place.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        access_log off;
    }

    location / {
        proxy_pass http://127.0.0.1:${PORT};
        proxy_http_version 1.1;

        # Le nom de domaine reel, sans quoi l'application se croit sur
        # 127.0.0.1 et fabrique des liens inutilisables.
        proxy_set_header Host              \$host;

        # L'IP du PARTICIPANT. Elle est archivee dans les preuves de
        # consentement (TCPA, CAN-SPAM) : sans ces en-tetes, chaque preuve
        # porterait l'adresse du proxy et ne prouverait rien.
        #
        # X-Real-IP est ECRASE avec \$remote_addr, jamais relaye : un client
        # peut envoyer ce qu'il veut, c'est la valeur vue par nginx qui fait
        # foi. L'application lit X-Real-IP en premier, precisement pour cela.
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host  \$host;

        proxy_connect_timeout 5s;
        proxy_send_timeout    60s;
        proxy_read_timeout    60s;

        # Redirections de sortie vers la regie : /out/<jeton> repond 302 vers
        # un domaine tiers. nginx ne doit surtout pas la reecrire.
        proxy_redirect off;
    }
}

# ---------------------------------------------------------------- TLS (a venir)
#
# Une fois le certificat obtenu (certbot certonly --webroot -w /var/www/html \\
#   -d ${DOMAIN} -d www.${DOMAIN}) :
#
#   1. dupliquer le bloc ci-dessus en \`listen 443 ssl;\` + ssl_certificate ;
#   2. y ajouter Strict-Transport-Security, comme le reste du parc ;
#   3. ne garder en 80 que la redirection vers 443 ET /.well-known ;
#   4. passer APP_URL en https:// dans le .env.
#
# Point de vigilance : le nginx du conteneur pose \`fastcgi_param HTTPS off\`.
# Tant qu'il n'est pas rendu conditionnel a X-Forwarded-Proto, PHP se croira en
# clair et les cookies de session n'auront pas l'attribut Secure.
EOF
)

if [ "$PRINT" = "1" ]; then
    printf '%s\n' "$VHOST"
    exit 0
fi

[ -d "$DIR" ] || { echo "!! $DIR n'existe pas." >&2; exit 1; }

if [ -f "$FILE" ] && [ "$FORCE" = "0" ]; then
    echo "!! $FILE existe deja."
    echo "   Le remplacer ? Une copie horodatee sera conservee. [o/N]"
    read -r reponse
    case "$reponse" in [oO]*) ;; *) echo "Abandon."; exit 1 ;; esac
fi

if [ -f "$FILE" ]; then
    SAUVEGARDE="$FILE.$(date +%Y%m%d%H%M%S).bak"
    cp -a "$FILE" "$SAUVEGARDE"
    echo "Copie de l'ancien fichier : $SAUVEGARDE"
fi

printf '%s\n' "$VHOST" > "$FILE"
echo "Ecrit : $FILE"
echo "  domaine : $DOMAIN (et www.$DOMAIN)"
echo "  relais  : 127.0.0.1:$PORT"
echo "  envois  : jusqu'a $UPLOAD"

# Valider AVANT de recharger : une configuration fautive laisse nginx sur
# l'ancienne, mais autant le savoir tout de suite.
#
# Uniquement si l'on a ecrit dans le repertoire que nginx lit vraiment : ailleurs,
# `nginx -t` validerait une configuration sans rapport avec le fichier produit.
# Les [warn] sont ecartes — ils portent sur les autres vhosts de la machine et
# noieraient la seule ligne qui compte.
echo
if [ "$DIR" != "$DEFAUT" ] || ! command -v nginx >/dev/null 2>&1; then
    echo "Verifier puis recharger :  sudo nginx -t && sudo systemctl reload nginx"
    exit 0
fi

if SORTIE=$(nginx -t 2>&1); then
    printf '%s\n' "$SORTIE" | grep -v '\[warn\]' | sed 's/^/  /'
    echo
    echo "Recharger nginx :  sudo systemctl reload nginx"
else
    printf '%s\n' "$SORTIE" | grep -v '\[warn\]' | sed 's/^/  /' >&2
    echo >&2
    echo "!! Configuration nginx invalide — NE PAS recharger avant correction." >&2
    exit 1
fi
