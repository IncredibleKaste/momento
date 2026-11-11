# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.5-trixie AS php

COPY docker/patch-php-parser-download.php /tmp/patch-php-parser-download.php
COPY tests/Runtime/PhpParserDownloadPatchProbe.php /tmp/PhpParserDownloadPatchProbe.php
RUN stub_generator="$(php-config --prefix)/lib/php/build/gen_stub.php" \
    && cp -p "$stub_generator" /tmp/gen_stub.php.original \
    && php /tmp/PhpParserDownloadPatchProbe.php "$stub_generator" /tmp/patch-php-parser-download.php \
    && php /tmp/patch-php-parser-download.php "$stub_generator" \
    && php -l "$stub_generator" \
    && rm /tmp/patch-php-parser-download.php /tmp/PhpParserDownloadPatchProbe.php \
    && install-php-extensions pdo_pgsql redis imagick intl zip bcmath mbstring \
    && cp -p /tmp/gen_stub.php.original "$stub_generator" \
    && rm /tmp/gen_stub.php.original \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg util-linux libheif-plugin-libde265 libheif-plugin-x265 \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini /usr/local/etc/php/conf.d/momento.ini
COPY bin/photo-decoder /usr/local/bin/photo-decoder
COPY bin/photo-decoder.php /usr/local/bin/photo-decoder.php
COPY bin/conversation-video-decoder /usr/local/bin/conversation-video-decoder
COPY bin/conversation-video-decoder.php /usr/local/bin/conversation-video-decoder.php
COPY docker/photo-decoder-policy.xml /usr/local/share/momento-photo-decoder-policy/policy.xml
COPY docker/icc/sRGB2014.icc /usr/local/share/momento-photo-decoder/sRGB2014.icc
ENV MOMENTO_CONVERSATION_VIDEO_DECODER_EXECUTABLE=/usr/local/bin/conversation-video-decoder
WORKDIR /app

FROM node:22-trixie-slim AS node
FROM composer:2 AS composer

FROM php AS build
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY . .
RUN test ! -e .env && test ! -e .env.context-test && test ! -d .git \
    && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --classmap-authoritative \
    && npm ci --no-audit --no-fund \
    && npm run build

FROM php AS runtime
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql SESSION_DRIVER=database CACHE_STORE=redis \
    PGCONNECT_TIMEOUT=2 REDIS_MAX_RETRIES=0
COPY --from=build /app/artisan ./artisan
COPY --from=build /app/composer.json ./composer.json
COPY --from=build /app/app ./app
COPY --from=build /app/bootstrap ./bootstrap
COPY --from=build /app/config ./config
COPY --from=build /app/database ./database
COPY --from=build /app/public ./public
COPY --from=build /app/resources/views ./resources/views
COPY --from=build /app/routes ./routes
COPY --from=build /app/vendor ./vendor
COPY docker/Caddyfile /etc/caddy/Caddyfile
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy \
    && setcap -r /usr/local/bin/frankenphp
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=8s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/ready") === false ? 1 : 0);'
ENTRYPOINT ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
