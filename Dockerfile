FROM php:8.1-apache

ENV TZ=America/Manaus

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        unzip \
        git \
        tzdata \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip calendar \
    && a2enmod rewrite \
    && echo "date.timezone = ${TZ}" > /usr/local/etc/php/conf.d/timezone.ini \
    && { \
        echo "upload_max_filesize = 25M"; \
        echo "post_max_size = 26M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
