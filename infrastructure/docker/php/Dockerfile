# Development image for the Laravel platform (php-fpm + CLI tooling).
#
# Runs as the developer's UID/GID (set by compose `user:`), so no user is
# baked into the image and no per-developer rebuild is needed.
# Production does not use this image: production is cPanel shared hosting.
FROM composer:2 AS composer

FROM php:8.3-fpm-bookworm

COPY --from=mlocati/php-extension-installer:2.11.27 /usr/bin/install-php-extensions /usr/local/bin/

# Extensions match what the platform needs on cPanel PHP 8.3.
# Both pdo_mysql (MariaDB) and pdo_pgsql (PostgreSQL portability) are installed.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath intl pcntl pdo_mysql pdo_pgsql zip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-flow-dev.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-flow-dev.conf

# The container user has no passwd entry, so give tools a writable HOME.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache

WORKDIR /var/www/platform
