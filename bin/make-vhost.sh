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
#   ./bin/make-vhost.sh --canonical www  # www canonique plutot que l'apex
#   ./bin/make-vhost.sh --no-canonical   # sert les deux noms, sans rediriger
#   ./bin/make-vhost.sh --replace-tls    # accepte d'ecraser une conf TLS existante
set -euo pipefail
cd "$(dirname "$0")/.."

DEFAUT=/data/nginx
DIR=$DEFAUT
FORCE=0
PRINT=0
REMPLACER_TLS=0
# Hote canonique : « apex » (top-sweepstakes.com) ou « www ». « aucun » sert les
# deux sans rediriger — a n'utiliser que si quelque chose d'autre s'en charge.
CANONIQUE=apex

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)   DIR="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --replace-tls) REMPLACER_TLS=1; shift ;;
        --print) PRINT=1; shift ;;
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
# Valeur a DROITE du signe egal : un filtre sur les caracteres attraperait
# aussi le « m » de post_max_size.
UPLOAD=$(grep -E '^post_max_size' .docker/php-fpm/uploads.ini | cut -d= -f2 | tr -d ' ')
UPLOAD=${UPLOAD:-12M}

[ -n "$DOMAIN" ] || { echo "!! APP_DOMAIN est vide dans .env." >&2; exit 1; }
[ -n "$PORT" ]   || { echo "!! DOCKER_NGINX_PORT est vide dans .env." >&2; exit 1; }

case "$CANONIQUE" in
    apex) HOTE="$DOMAIN";        REDIRIGE="www.$DOMAIN" ;;
    www)  HOTE="www.$DOMAIN";    REDIRIGE="$DOMAIN" ;;
    aucun) HOTE="$DOMAIN www.$DOMAIN"; REDIRIGE="" ;;
    *) echo "!! --canonical attend « apex », « www » ou « aucun »." >&2; exit 1 ;;
esac

FILE="$DIR/$DOMAIN.conf"

# Bloc de redirection, construit a part : imbriquer un heredoc dans une
# substitution de commande, elle-meme dans un heredoc, rend l'echappement des
# variables nginx (\$scheme, \$request_uri) illisible et fragile.
REDIRECTION=""
if [ -n "$REDIRIGE" ]; then
    REDIRECTION=$(cat <<EOF
# ${REDIRIGE} -> ${HOTE}, en 301 permanent.
#
# Sans elle, les deux noms servent le MEME site : contenu duplique pour les
# moteurs, et surtout deux sessions distinctes pour un meme visiteur selon le
# lien sur lequel il a clique.
#
# \$request_uri porte le chemin ET la query string. C'est non negociable ici :
# treize parametres d'acquisition (subid, utm_*, gclid, fbclid, clickid...) sont
# lus a la premiere page, figes en session, et le subid finit dans le sid envoye
# a la regie. Une redirection qui les perdrait rendrait le revenu inattribuable.
server {
    listen 80;
    listen [::]:80;

    server_name ${REDIRIGE};

    access_log /var/log/nginx/${DOMAIN}_access.log;

    # AVANT la redirection : certbot doit pouvoir valider ce nom aussi, sinon le
    # certificat ne couvrira pas les deux hotes.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        access_log off;
    }

    # Dans un location, et non un \`return\` au niveau du serveur : celui-ci
    # s'executerait AVANT le choix du location et court-circuiterait la
    # validation ACME ci-dessus.
    location / {
        return 301 \$scheme://${HOTE}\$request_uri;
    }
}

EOF
)
    # \$() supprime les sauts de ligne finaux : les remettre pour separer
    # les deux blocs serveur.
    REDIRECTION="$REDIRECTION"$'\n\n'
fi

# La limite de taille du proxy doit au moins egaler celle de PHP : en dessous,
# nginx refuse l'envoi par un 413 avant que PHP ne voie le fichier, et le
# televersement d'un visuel de concours echoue sans message exploitable.
VHOST=$(cat <<EOF
# ${DOMAIN} — genere par bin/make-vhost.sh, ne pas editer a la main.
#
# Ce vhost ne sert aucun fichier : il relaie vers le nginx du conteneur, qui
# ecoute sur 127.0.0.1:${PORT} et parle a PHP-FPM. La racine du site vit dans le
# conteneur, pas ici.

${REDIRECTION}server {
    listen 80;
    listen [::]:80;

    server_name ${HOTE};

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

# Certbot MODIFIE le vhost en place : il y ajoute `listen 443 ssl`, les chemins
# de certificat et sa propre redirection. Le regenerer effacerait tout cela et
# couperait HTTPS — le site repondrait en clair, ou pas du tout.
#
# La sauvegarde horodatee ne suffit pas comme garde-fou : personne ne verifie une
# sauvegarde avant de recharger nginx. Un `--force` pris par habitude doit donc
# rester sans effet ici ; il faut un drapeau qui ne s'utilise pas par reflexe.
if [ -f "$FILE" ] && [ "$REMPLACER_TLS" = "0" ] \
   && grep -qiE 'ssl_certificate|managed by Certbot|letsencrypt' "$FILE"; then
    echo "!! $FILE porte une configuration TLS (certbot)." >&2
    echo >&2
    echo "   La regenerer effacerait le certificat, les chemins et la redirection" >&2
    echo "   HTTPS poses par certbot : le site repondrait en clair, ou pas du tout." >&2
    echo >&2
    echo "   Pour voir ce que produirait le script sans rien ecrire :" >&2
    echo "     ./bin/make-vhost.sh --print" >&2
    echo >&2
    echo "   Pour reporter une nouveaute a la main, comparer :" >&2
    echo "     ./bin/make-vhost.sh --print | diff $FILE - " >&2
    echo >&2
    echo "   Si vous voulez vraiment repartir d'un vhost en clair et refaire le TLS :" >&2
    echo "     ./bin/make-vhost.sh --replace-tls" >&2
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
if [ -n "$REDIRIGE" ]; then
    echo "  domaine : $HOTE  (301 depuis $REDIRIGE, chemin et parametres preserves)"
else
    echo "  domaine : $HOTE  (aucune redirection)"
fi
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
