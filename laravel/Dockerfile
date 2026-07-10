# Creates container that runs the application in

# Stage 1: Install and cache PHP deps
#FROM composer:lts AS composer

#WORKDIR /app

# RUN --mount=type=bind,source=composer.json,target=composer.json \
#     --mount=type=bind,source=composer.lock,target=composer.lock \
#     --mount=type=cache,target=/tmp/cache \
#     composer install --no-dev --no-interaction

# FPM = FastCGI Process Manager - this image is optimized for web server deployment
# Debian version pinning recommended to minimize breakage
# Using Apache image to include a web server
FROM php:8.4-apache-trixie

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
    libfreetype-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libpq-dev \
&& rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) calendar gd pdo_pgsql exif zip \
    && docker-php-ext-enable gd pdo_pgsql exif zip

COPY . /var/www/html

# required for autoload scripts to run (not sure if this is ok yet)
ENV APP_URL "http://localhost"

RUN --mount=type=bind,source=composer.json,target=composer.json \
    --mount=type=bind,source=composer.lock,target=composer.lock \
    --mount=type=bind,source=artisan,target=artisan \
    --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-interaction

# use the development configuration file 
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY .docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

RUN a2enmod headers rewrite

WORKDIR /var/www/html

RUN mkdir -p storage/app/public/ \
    storage/framework/sessions/ \
    storage/framework/views/ \
    storage/framework/cache/

# RUN php artisan optimize # TODO: Figure out what this shit is lmao
# RUN php artisan config:cache # TODO: Figure out why this is causing an error!
# RUN php artisan config:publish --all
# RUN php artisan storage:link

RUN chown -R www-data bootstrap/cache/ storage/
RUN chmod -R 700 bootstrap/cache/ storage/

USER www-data