FROM php:8.2-apache

# Enable mysqli extension
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy all project files
COPY . .

# Create uploads directory and set permissions
RUN mkdir -p /var/www/html/uploads/resources \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

EXPOSE 80

CMD ["apache2-foreground"]
