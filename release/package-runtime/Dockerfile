ARG PHP_IMAGE=php:8.2-apache-bookworm
FROM ${PHP_IMAGE}

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    SYNDICATUM_AVATAR_DIR=/var/lib/syndicatum/avatars \
    SYNDICATUM_STAGING_DIR=/var/lib/syndicatum/staging \
    SYNDICATUM_BACKUP_DIR=/var/lib/syndicatum/backups

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates libcurl4 libfreetype6 libjpeg62-turbo libonig5 libpng16-16 libzip4 libwebp7 libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev libonig-dev libpng-dev libzip-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" curl gd mbstring opcache pdo_mysql zip; \
    a2enmod headers rewrite; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf; \
    apt-get purge -y --auto-remove curl libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev libonig-dev libpng-dev libzip-dev libwebp-dev linux-libc-dev; \
    rm -rf /var/lib/apt/lists/*

COPY app/docker/apache-syndicatum.conf /etc/apache2/conf-available/syndicatum.conf
COPY app/docker/php-production.ini /usr/local/etc/php/conf.d/zz-syndicatum-production.ini
RUN a2enconf syndicatum

WORKDIR /var/www/html
COPY --chown=www-data:www-data app/ /var/www/html/
COPY --chown=www-data:www-data schema/baselines/mysql84/ /var/www/html/schema/mysql84/
# Both paths are materialized from the authenticated legacy-upgrade entries
# in this same canonical package, not from a host migration checkout.
COPY --chown=www-data:www-data schema/legacy-upgrade/plan.json /var/www/html/schema/mysql84/legacy-upgrade-plan.json
COPY --chown=www-data:www-data schema/legacy-upgrade/migrations/ /var/www/html/migrations/
COPY --chown=www-data:www-data plugins/ /var/www/html/plugins/
COPY --chown=www-data:www-data skills/ /var/www/html/skills/
COPY --chmod=755 app/docker/entrypoint.sh /usr/local/bin/syndicatum-entrypoint
COPY --chmod=755 app/docker/worker-loop.sh /usr/local/bin/syndicatum-worker

RUN set -eux; \
    mkdir -p /var/lib/syndicatum/avatars /var/lib/syndicatum/staging /var/lib/syndicatum/backups /var/www/html/runtime; \
    chmod 0700 /var/lib/syndicatum/staging; \
    chmod 0700 /var/lib/syndicatum/backups; \
    chown -R www-data:www-data /var/lib/syndicatum /var/www/html/runtime

EXPOSE 8080
USER www-data
ENTRYPOINT ["syndicatum-entrypoint"]
CMD ["apache2-foreground"]
