FROM php:8.2-apache

RUN sed -i 's/^#\(.*mpm_prefork\)/\1/' /etc/apache2/mods-enabled/*.load 2>/dev/null || true
RUN if [ -f /etc/apache2/mods-enabled/mpm_event.load ]; then a2dismod mpm_event; fi
RUN if [ -f /etc/apache2/mods-enabled/mpm_worker.load ]; then a2dismod mpm_worker; fi
RUN a2enmod mpm_prefork rewrite

RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html
COPY . .

RUN mkdir -p uploads/resources \
    && chown -R www-data:www-data uploads \
    && chmod -R 755 uploads

EXPOSE 80
