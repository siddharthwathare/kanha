FROM php:8.2-apache

# Install mysqli
RUN docker-php-ext-install mysqli

# Enable Apache rewrite (good practice)
RUN a2enmod rewrite

# Copy files to Apache root
COPY . /var/www/html/
