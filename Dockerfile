FROM php:8.2-cli

# Install mysqli extension
RUN docker-php-ext-install mysqli

WORKDIR /app

COPY . .

CMD ["php", "-S", "0.0.0.0:10000"]
