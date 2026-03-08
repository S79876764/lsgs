FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . .

RUN mkdir -p uploads/resources \
    && chown -R www-data:www-data uploads \
    && chmod -R 755 uploads

EXPOSE 80
