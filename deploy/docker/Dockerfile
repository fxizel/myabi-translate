# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libonig-dev libzip-dev python3 mariadb-client ca-certificates unzip \
    && docker-php-ext-install -j2 intl mbstring pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 myabi-web \
    && useradd --uid 10001 --gid 10001 --no-create-home myabi-web \
    && useradd --uid 10002 --gid 10001 --no-create-home myabi-cli
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-myabi.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-myabi.conf

FROM php-base AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database/migrations ./database/migrations
COPY lang ./lang
COPY public ./public
COPY resources/views ./resources/views
COPY routes ./routes
COPY scripts ./scripts
COPY artisan ./artisan
ARG APP_REVISION
ARG APP_VERSION=local-preparation
RUN composer dump-autoload --no-dev --no-scripts --classmap-authoritative \
    && composer check-platform-reqs --no-dev \
    && bash scripts/package-release.sh "$APP_VERSION" "$APP_REVISION" /tmp/release.tar.gz \
    && python3 scripts/inspect-release.py /tmp/release.tar.gz \
    && mkdir /release && tar -xzf /tmp/release.tar.gz -C /release

FROM php-base AS app
WORKDIR /var/www/html
COPY --from=build /release/ ./
COPY deploy/docker/entrypoint.sh /usr/local/bin/myabi-entrypoint
COPY deploy/docker/provision.php deploy/docker/migrate.php /opt/myabi/
COPY deploy/docker/backup.sh /opt/myabi/backup.sh
RUN chmod 0755 /usr/local/bin/myabi-entrypoint \
    && chmod -R a+rX /var/www/html \
    && mkdir -p storage bootstrap/cache \
    && chown 10001:10001 storage bootstrap/cache
USER 10001:10001
ENTRYPOINT ["myabi-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.30-alpine AS web
COPY --from=build /release/public/ /var/www/html/public/
COPY deploy/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
