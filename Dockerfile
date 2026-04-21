FROM php:8.2-apache

# Install mysqli
RUN docker-php-ext-install mysqli

# Copy files to Apache root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html
