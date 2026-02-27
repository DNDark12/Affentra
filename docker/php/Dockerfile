FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    gnupg

# Install Node.js 20 LTS
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Custom settings for PHP timezone, variables etc.
# RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini
RUN echo "upload_max_filesize = 12M" > /usr/local/etc/php/conf.d/uploads.ini
RUN echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/uploads.ini
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/memory.ini

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Adjust user privileges
RUN usermod -u 1000 www-data
USER www-data
