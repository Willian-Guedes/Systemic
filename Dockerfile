FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN a2enmod rewrite && \
    echo "Listen 8081" >> /etc/apache2/ports.conf

COPY apache.conf /etc/apache2/sites-available/000-default.conf

COPY flowgate/ /var/www/html/flowgate/

EXPOSE 80 8081