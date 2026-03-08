FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid
ENV APACHE_RUN_DIR=/var/run/apache2
ENV APACHE_LOCK_DIR=/var/lock/apache2

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-mysqli \
    php8.1-pdo \
    php8.1-mysql \
    libapache2-mod-php8.1 \
    && a2enmod rewrite php8.1 \
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

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
