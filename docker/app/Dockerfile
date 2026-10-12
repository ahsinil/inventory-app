FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    linux-headers \
    build-base \
    autoconf \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    supervisor \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql exif pcntl bcmath gd sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
