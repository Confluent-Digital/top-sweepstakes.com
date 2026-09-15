#!/usr/bin/env bash
# Genere le vhost nginx de l'HOTE, qui relaie vers le conteneur.
#
# Le site tourne derriere deux nginx : celui du conteneur, qui parle a PHP-FPM
# et n'ecoute que sur 127.0.0.1:<DOCKER_NGINX_PORT>, et celui de l'hote, qui
# porte le nom de domaine et relaie. Ce script ecrit le second.
#
#   ./bin/make-vhost.sh                  # HTTP seul, dans /data/nginx/
#   ./bin/make-vhost.sh --tls            # HTTPS, certificat detecte
#   ./bin/make-vhost.sh --print          # affiche sans rien ecrire
#   ./bin/make-vhost.sh --dir /etc/nginx/sites-enabled
#   ./bin/make-vhost.sh --force          # remplace sans demander
#   ./bin/make-vhost.sh --replace-tls    # accepte d'ecraser une conf TLS existante
#   ./bin/make-vhost.sh --canonical www  # www canonique plutot que l'apex
#   ./bin/make-vhost.sh --no-canonical   # sert les deux noms, sans rediriger
set -euo pipefail
cd "$(dirname "$0")/.."

DEFAUT=/data/nginx
DIR=$DEFAUT
FORCE=0
PRINT=0
REMPLACER_TLS=0
TLS=0
CANONIQUE=apex

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)   DIR="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --print) PRINT=1; shift ;;
        --tls)   TLS=1; shift ;;
        --replace-tls) REMPLACER_TLS=1; shift ;;
        --canonical) CANONIQUE="$2"; shift 2 ;;
        --no-canonical) CANONIQUE=aucun; shift ;;
        -h|--help) awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"; exit 0 ;;
        *) echo "Option inconnue : $1" >&2; exit 1 ;;
    esac
done

[ -f .env ] || { echo "!! .env introuvable : le domaine et le port s'y lisent." >&2; exit 1; }

read_env() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"'"'"' '; }

DOMAIN=$(read_env APP_DOMAIN)
PORT=$(read_env DOCKER_NGINX_PORT)
UPLOAD=$(grep -E '^post_max_size' .docker/php-fpm/uploads.ini | cut -d= -f2 | tr -d ' ')
UPLOAD=${UPLOAD:-12M}

[ -n "$DOMAIN" ] || { echo "!! APP_DOMAIN est vide dans .env." >&2; exit 1; }
[ -n "$PORT" ]   || { echo "!! DOCKER_NGINX_PORT est vide dans .env." >&2; exit 1; }

case "$CANONIQUE" in
    apex)  HOTE="$DOMAIN";     AUTRE="www.$DOMAIN" ;;
    www)   HOTE="www.$DOMAIN"; AUTRE="$DOMAIN" ;;
    aucun) HOTE="$DOMAIN www.$DOMAIN"; AUTRE="" ;;
    *) echo "!! --canonical attend « apex », « www » ou « aucun »." >&2; exit 1 ;;
esac
TOUS="$DOMAIN www.$DOMAIN"

# Identifiant unique pour les zones partagees et l'amont : deux sites du parc ne
# doivent pas se disputer le meme nom dans le contexte http.
SLUG=$(printf '%s' "$DOMAIN" | tr -c 'a-zA-Z0-9' '_')

# ------------------------------------------------------------------ Certificat
SSL=""
if [ "$TLS" = "1" ]; then
    # LE_BASE n'existe que pour les tests : il permet de valider le vhost
    # genere contre une arborescence de certificats fabriquee, sans toucher aux
    # vrais. Inerte en usage normal.
    for base in ${LE_BASE:-/data/letsencrypt/keys} /etc/letsencrypt; do
        if [ -r "$base/live/$DOMAIN/fullchain.pem" ] || [ -d "$base/live/$DOMAIN" ]; then
            CERT_DIR="$base/live/$DOMAIN"
            CONF_DIR="$base"
            break
        fi
    done
    if [ -z "${CERT_DIR:-}" ]; then
        echo "!! Aucun certificat trouve pour $DOMAIN." >&2
        echo "   Cherche dans /data/letsencrypt/keys/live/ et /etc/letsencrypt/live/." >&2
        echo "   L'obtenir d'abord :" >&2
        echo "     certbot certonly --webroot -w /var/www/html -d $DOMAIN -d www.$DOMAIN" >&2
        exit 1
    fi

    SSL="    ssl_certificate     $CERT_DIR/fullchain.pem;
    ssl_certificate_key $CERT_DIR/privkey.pem;"
    [ -r "$CONF_DIR/options-ssl-nginx.conf" ] && SSL="$SSL
    include $CONF_DIR/options-ssl-nginx.conf;"
    [ -r "$CONF_DIR/ssl-dhparams.pem" ] && SSL="$SSL
    ssl_dhparam $CONF_DIR/ssl-dhparams.pem;"

    # Reprise de session : evite une poignee de main complete a chaque nouvelle
    # connexion. Les tickets sont desactives — sans rotation de clef, ils
    # affaiblissent la confidentialite persistante.
    #
    # Mais le options-ssl-nginx.conf de certbot les definit DEJA. Les reecrire
    # par-dessus fait echouer nginx : « ssl_session_timeout directive is
    # duplicate ». On n'ajoute donc que ce que l'include ne fournit pas.
    INCLUDE="$CONF_DIR/options-ssl-nginx.conf"
    fournie() { [ -r "$INCLUDE" ] && grep -qE "^\s*$1" "$INCLUDE"; }

    fournie ssl_session_cache   || SSL="$SSL
    ssl_session_cache   shared:TLS_${SLUG}:10m;"
    fournie ssl_session_timeout || SSL="$SSL
    ssl_session_timeout 1d;"
    fournie ssl_session_tickets || SSL="$SSL
    ssl_session_tickets off;"
fi

FILE="$DIR/$DOMAIN.conf"
PROTO=$([ "$TLS" = "1" ] && echo https || echo http)
ECOUTE=$([ "$TLS" = "1" ] && echo "    listen 443 ssl http2;
    listen [::]:443 ssl http2;" || echo "    listen 80;
    listen [::]:80;")

# Bloc de validation ACME, repete partout ou un nom peut etre valide.
ACME='    # Validation Let'"'"'s Encrypt. Presente sur CHAQUE nom et sur le port 80,
    # sinon le renouvellement echoue sur celui qui manque — et le certificat
    # entier avec lui.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        access_log off;
    }'

# ------------------------------------------------------------------ Redirections
REDIR_HTTP=""
if [ "$TLS" = "1" ]; then
    REDIR_HTTP=$(cat <<EOF
# Port 80 : validation ACME, puis tout part en HTTPS — les DEUX noms.
#
# La redirection vit dans un \`location\`, pas dans un \`return\` au niveau du
# serveur : celui-ci s'executerait AVANT le choix du location et court-circuiterait
# la validation ci-dessus.
server {
    listen 80;
    listen [::]:80;

    server_name ${TOUS};

    access_log /var/log/nginx/${DOMAIN}_access.log;

${ACME}

    location / {
        return 301 https://${HOTE}\$request_uri;
    }
}

EOF
)
fi

REDIR_HOTE=""
if [ -n "$AUTRE" ]; then
    REDIR_HOTE=$(cat <<EOF
# ${AUTRE} -> ${HOTE}, en 301 permanent.
#
# Sans elle, les deux noms servent le MEME site : contenu duplique pour les
# moteurs, et deux sessions distinctes pour un meme visiteur selon le lien
# clique.
#
# \$request_uri porte le chemin ET la query string. C'est non negociable ici :
# treize parametres d'acquisition (subid, utm_*, gclid, fbclid, clickid...) sont
# lus a la premiere page, figes en session, et le subid finit dans le sid envoye
# a la regie. Une redirection qui les perdrait rendrait le revenu inattribuable.
server {
${ECOUTE}

    server_name ${AUTRE};

${SSL}

${ACME}

    location / {
        return 301 ${PROTO}://${HOTE}\$request_uri;
    }
}

EOF
)
fi

HSTS=""
if [ "$TLS" = "1" ]; then
    HSTS='    # Un an. « preload » n'\''est PAS pose : l'"'"'inscription sur la liste des
    # navigateurs se defait tres difficilement, c'"'"'est un engagement a part.
    #
    # Les autres en-tetes de securite (X-Frame-Options, X-Content-Type-Options,
    # Referrer-Policy) sont poses par SecurityHeadersMiddleware cote PHP. Les
    # repeter ici donnerait deux valeurs pour un meme en-tete.
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
'
fi

VHOST=$(cat <<EOF
# ${DOMAIN} — genere par bin/make-vhost.sh, ne pas editer a la main.
#
# Ce vhost ne sert aucun fichier : il relaie vers le nginx du conteneur, qui
# ecoute sur 127.0.0.1:${PORT} et parle a PHP-FPM. La racine du site vit dans le
# conteneur, pas ici.

# Amont nomme, pour tenir des connexions ouvertes vers le conteneur : sans
# \`keepalive\`, chaque requete refait une poignee de main TCP.
upstream app_${SLUG} {
    server 127.0.0.1:${PORT};
    keepalive 32;
}

${REDIR_HTTP}${REDIR_HOTE}server {
${ECOUTE}

    server_name ${HOTE};

${SSL}

    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log  /var/log/nginx/${DOMAIN}_error.log warn;

    # Au moins autant que post_max_size cote PHP (${UPLOAD}) : en dessous, nginx
    # repond 413 avant que PHP ne voie le fichier, et le televersement d'un
    # visuel de concours echoue sans message exploitable.
    client_max_body_size ${UPLOAD};

${HSTS}
    # gzip_proxied vaut « off » par defaut : SANS cette ligne, rien de ce site
    # n'est compresse, puisque tout passe par le proxy. C'est le cas sur la
    # production, ou nginx.conf active gzip mais laisse gzip_proxied commente.
    #
    # \`gzip on\` est repete ici plutot que suppose : le vhost doit se suffire a
    # lui-meme, la configuration globale d'une machine n'etant pas celle d'une
    # autre.
    gzip on;
    gzip_proxied any;
    gzip_types text/plain text/css text/xml application/json application/javascript
               application/xml application/xml+rss text/javascript image/svg+xml;
    gzip_vary on;
    gzip_min_length 1024;

${ACME}

    location / {
        proxy_pass http://app_${SLUG};

        # 1.1 + Connection vide : indispensable pour que le \`keepalive\` de
        # l'amont serve a quelque chose.
        proxy_http_version 1.1;
        proxy_set_header Connection "";

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

        # Le conteneur en deduit \`fastcgi_param HTTPS\` : sans cet en-tete, PHP
        # croit le visiteur en clair alors qu'il est en TLS.
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host  \$host;

        proxy_connect_timeout 5s;
        proxy_send_timeout    60s;
        proxy_read_timeout    60s;

        # Redirections de sortie vers la regie : /out/<jeton> repond 302 vers un
        # domaine tiers. nginx ne doit surtout pas la reecrire.
        proxy_redirect off;
    }
}

# ------------------------------------------------------- Ce qui n'est PAS ici
#
# * Aucun \`proxy_cache\`. Les pages du tunnel dependent de la session : une page
#   d'offres mise en cache et resservie fausserait le comptage des impressions
#   et pourrait montrer a un visiteur le parcours d'un autre.
#
# * Aucun \`expires\` sur les statiques. Le nginx du CONTENEUR pose deja
#   \`expires 7d\`. L'allonger serait risque : les fichiers ne portent pas
#   d'empreinte dans leur nom, une CSS mise en cache un an le resterait apres
#   une mise en production.
EOF
)

if [ "$PRINT" = "1" ]; then
    printf '%s\n' "$VHOST"
    exit 0
fi

[ -d "$DIR" ] || { echo "!! $DIR n'existe pas." >&2; exit 1; }

# Certbot MODIFIE le vhost en place. Le regenerer effacerait le certificat, les
# chemins et sa redirection. Une sauvegarde horodatee n'est pas un garde-fou :
# personne ne la verifie avant de recharger nginx. Un `--force` pris par
# habitude doit donc rester sans effet ici.
if [ -f "$FILE" ] && [ "$REMPLACER_TLS" = "0" ] \
   && grep -qiE 'ssl_certificate|managed by Certbot|letsencrypt' "$FILE"; then
    echo "!! $FILE porte une configuration TLS." >&2
    echo >&2
    echo "   Comparer sans rien ecrire :" >&2
    echo "     ./bin/make-vhost.sh --tls --print | diff $FILE -" >&2
    echo >&2
    echo "   Remplacer en connaissance de cause :" >&2
    echo "     ./bin/make-vhost.sh --tls --replace-tls" >&2
    exit 1
fi

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
echo "  canonique : $HOTE${AUTRE:+  (301 depuis $AUTRE, chemin et parametres preserves)}"
echo "  relais    : 127.0.0.1:$PORT (keepalive)"
echo "  envois    : jusqu'a $UPLOAD"
[ "$TLS" = "1" ] && echo "  TLS       : ${CERT_DIR}" || echo "  TLS       : non (port 80 seul)"

echo
if [ "$DIR" != "$DEFAUT" ] || ! command -v nginx >/dev/null 2>&1; then
    echo "Verifier puis recharger :  sudo nginx -t && sudo systemctl reload nginx"
    exit 0
fi

if SORTIE=$(nginx -t 2>&1); then
    printf '%s\n' "$SORTIE" | grep -v '\[warn\]' | sed 's/^/  /'
    echo
    echo "Recharger nginx :  sudo systemctl reload nginx"
    exit 0
fi

# Configuration invalide : on REPREND l'etat precedent plutot que de laisser un
# fichier casse en place. nginx tourne encore sur son ancienne configuration —
# le site n'est donc pas tombe — mais le prochain rechargement, par qui que ce
# soit et pour quelque raison que ce soit, echouerait.
printf '%s\n' "$SORTIE" | grep -v '\[warn\]' | sed 's/^/  /' >&2
echo >&2
echo "!! Configuration nginx invalide." >&2
if [ -n "${SAUVEGARDE:-}" ] && [ -f "$SAUVEGARDE" ]; then
    cp -a "$SAUVEGARDE" "$FILE"
    echo "   Fichier precedent RESTAURE depuis $SAUVEGARDE." >&2
    echo "   nginx tourne toujours sur sa configuration actuelle, le site est debout." >&2
else
    rm -f "$FILE"
    echo "   Fichier retire (il n'y en avait pas avant)." >&2
fi
echo "   Voir ce que le script produit :  ./bin/make-vhost.sh --tls --print" >&2
exit 1
