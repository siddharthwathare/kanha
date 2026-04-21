FROM php:8.2-apache

# Install mysqli
RUN docker-php-ext-install mysqli

# Set working directory explicitly
WORKDIR /var/www/html

# Copy ONLY your API files here
COPY api.php /var/www/html/
