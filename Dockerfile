# Base image: official PHP 8.3 with Apache already built in
FROM php:8.3-apache

# System packages + PHP extensions the app actually needs:
# - pdo_mysql: database access (src/Database.php)
# - curl: all outbound API calls (FootballDataClient, ApiFootballClient)
# - cron: runs the background sync jobs (bin/*.php), same as crontab on the VM
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev cron \
    && docker-php-ext-install pdo_mysql curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Apache's document root needs to be public/, exactly like the vhost
# config on the real Oracle server (see ORACLE_DEPLOYMENT.md step 9).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . .

# config/config.php is excluded from the build context (see .dockerignore) so
# a real secret can never end up baked into an image layer. The app runs
# entirely on the safe example file plus environment variables supplied by
# docker-compose.yml at container start.
RUN cp config/config.example.php config/config.php

RUN mkdir -p storage/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage/cache

# Background sync jobs — same four jobs as the real crontab on Oracle,
# just running inside this container instead.
COPY docker/crontab /etc/cron.d/naijascores-cron
RUN chmod 0644 /etc/cron.d/naijascores-cron \
    && touch /var/www/html/storage/sync.log \
    && chown www-data:www-data /var/www/html/storage/sync.log

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
