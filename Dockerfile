FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-mysqli \
    php8.1-pdo \
    php8.1-mysql \
    libapache2-mod-php8.1 \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
RUN rm -f /var/www/html/index.html

COPY . .

RUN mkdir -p /var/www/html/uploads/resources \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/uploads

RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]
