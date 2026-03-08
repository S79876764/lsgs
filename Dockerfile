FROM php:8.2-cli

RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html
COPY . .

RUN mkdir -p uploads/resources \
    && chmod -R 755 uploads

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]
