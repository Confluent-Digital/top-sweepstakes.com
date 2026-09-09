# L'image du parc (Alpine, PHP 8.4) n'embarque pas pdo_mysql : elle est taillee
# pour des landing pages sans base. On l'etend au lieu de repartir de zero, pour
# rester aligne sur le reste du parc (version de PHP, locale, opcache, composer).
FROM docker-registry.confluent-digital.com/php:lp-8.4-fpm

USER root

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli bcmath \
    && docker-php-ext-enable pdo_mysql mysqli bcmath

# L'entrypoint de l'image de base chmod des chemins propres aux landing pages
# (public/__gestion/config, src/Controllers/user) qui n'existent pas ici : avec
# `set -e` il sortirait en erreur avant meme de lancer php-fpm.
COPY uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
COPY entrypoint.sh /usr/local/bin/topsweepstakes-entrypoint.sh
RUN chmod +x /usr/local/bin/topsweepstakes-entrypoint.sh

WORKDIR /data/www/top-sweepstakes.com

ENTRYPOINT ["/usr/local/bin/topsweepstakes-entrypoint.sh"]
CMD ["php-fpm"]
