# RAFEEQ — a deterministic image.
#
# 🔴 Why this exists rather than Nixpacks inference. Three builds failed in a row, each for a
# different reason inside a build we did not control: a Node phase OOM-killed on a package.json
# that buys nothing, a PHP version picked from a constraint that was wrong, and `artisan
# config:cache` run during BUILD. That last one is not just a failure — it is wrong even when it
# succeeds, because at build time the platform has not injected the service variables yet, so the
# cached config bakes in an empty database password and the wrong APP_URL.
#
# Everything here is explicit. Nothing is inferred, and `config:cache` runs at START, when the
# environment actually exists.
#
# Railway, Coolify, Fly and a plain VM all build this the same way. `nixpacks.toml` is kept for
# platforms that have no Docker builder; where both exist, this wins.

FROM php:8.4-fpm-alpine

# 🔴 Every one of these is needed, and the non-obvious ones say why:
#   gd      — document upload re-encodes images to strip EXIF (pitfall #24: an ID photo carries
#             where it was taken). Without it, uploads fail at runtime, not at build.
#   bcmath  — pulled in by a dependency; missing it fails the install, not the request.
#   pcntl   — the queue worker and `reverb:start` need signal handling to shut down cleanly.
#   intl    — Arabic collation and date formatting.
RUN apk add --no-cache \
        nginx supervisor \
        icu-dev oniguruma-dev libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring bcmath intl zip gd pcntl opcache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so a code change does not re-resolve them.
COPY composer.json composer.lock ./

# 🔴 `--no-scripts`: the post-autoload-dump hook runs `artisan package:discover`, which boots the
# application — and the application is not here yet, only its lock file. Discovery happens after
# the code is copied, below.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# 🔴 What is deliberately NOT here: `config:cache`, `route:cache`, `view:cache`, `migrate`. All
# four need the real environment — the first two would freeze build-time values into the image,
# and migrate needs a database that does not exist during a build. They run in the entrypoint.

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Alpine's php-fpm image runs as root by default; the app's own files belong to the web user so a
# runtime write (a log, a cached view, an uploaded document) is possible without widening
# permissions on the whole tree.
RUN chown -R www-data:www-data storage bootstrap/cache

# OPcache, because every request otherwise recompiles the framework. `validate_timestamps=0` is
# safe here and only here: the code in an image never changes, and a deploy is a new image.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Documents are capped at 8 MB in config; leave headroom for the multipart envelope.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=12M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/rafeeq.ini

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
