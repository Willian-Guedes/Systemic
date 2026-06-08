FROM php:8.2-apache

# Extensão do MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilita mod_rewrite e porta 8081 para a Flowgate
RUN a2enmod rewrite && \
    echo "Listen 8081" >> /etc/apache2/ports.conf

# Configuração dos VirtualHosts (Automax + Flowgate)
COPY apache.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80 8081