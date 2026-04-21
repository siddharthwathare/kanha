FROM php:8.2-apache

# Install mysqli
RUN docker-php-ext-install mysqli

# Enable PHP in Apache explicitly
RUN a2enmod php8.2

# Copy files
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html
