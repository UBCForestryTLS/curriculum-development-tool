FROM php:8.4-apache-trixie

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
    libfreetype-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libpq-dev \
    postgresql-client-17 \
&& rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) calendar gd pdo_pgsql exif zip \
    && docker-php-ext-enable gd pdo_pgsql exif zip

# --no-autoloader required for composer install to succeed without
# APP_URL and without copying all project files. This also enables
# more consistent caching of this layer
RUN --mount=type=bind,source=composer.json,target=composer.json \
    --mount=type=bind,source=composer.lock,target=composer.lock \
    --mount=type=bind,source=artisan,target=artisan \
    --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-interaction --no-autoloader

# IMPORTANT: the excludes below prevent copying over sensitive/unnecessary
# files into container
COPY --exclude=**/.docker . /var/www/html

# TODO: Figure out if this needs to be modified to support volumes
# storage/app/public and storage/framework/sessions probably need
# to be persisted across builds
RUN mkdir -p storage/app/public/ \
    storage/framework/sessions/ \
    storage/framework/views/ \
    storage/framework/cache/

# Dummy value required for the autoload to run successfully (its value
# at this stage is seemingly not important)
ENV APP_URL ""

# required due to --no-autoloader option in the composer install layer
RUN composer dump-autoload --no-dev --optimize \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan vendor:publish --all

# TODO: Make necessary changes to php.ini (particularly for file uploads, see docs)
# use the production configuration file 
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY .docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

RUN a2enmod headers rewrite

WORKDIR /var/www/html

RUN chown -R www-data bootstrap/cache/ storage/ start-apache-app.sh
RUN chmod -R 700 bootstrap/cache/ storage/ start-apache-app.sh

USER www-data

# run migrations and start the apache process
# IMPORTANT: Seeding should not be run automatically as it will
# likely result in DB integrity errors that will stop the container
CMD ./start-apache-app.sh