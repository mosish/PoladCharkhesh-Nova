# Nova development image: PHP + Apache (mod_php), so .htaccess behaves as on
# the production host. Development only; nothing in this image is deployed.
# Versions are recorded in docs/local-baseline.md (D25).

FROM composer:2.10.3 AS composer

FROM php:8.4.26-apache-trixie

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev \
      git unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" intl gd zip pdo_mysql exif opcache \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/nova-dev.ini "$PHP_INI_DIR/conf.d/zz-nova-dev.ini"
COPY docker/apache/nova.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/nova
