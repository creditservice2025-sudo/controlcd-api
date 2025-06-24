FROM php:8.1-fpm

# Set working directory
WORKDIR /var/www/html

# Install dependencies
RUN apt-get update && apt-get install --no-install-recommends -yq \
    curl zip unzip vim git locales nodejs npm \
    jpegoptim optipng pngquant gifsicle \
    build-essential \
    libpng-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libfreetype6-dev

# Install Xdebug
RUN pecl install xdebug

# Clear cache
RUN apt-get clean && apt-get autoclean \
    && rm -rf /var/lib/apt/lists/* && rm -rf /tmp/* && rm -rf /var/tmp/*

# Install extensions
RUN docker-php-ext-install pdo_mysql zip mbstring exif pcntl bcmath gd opcache exif \
    && docker-php-ext-enable xdebug

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set memory limit
RUN echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/docker-php-extra.ini

# Copy all files
COPY . ./

# Expose port 9000
EXPOSE 9000

# Start php-fpm server
CMD ["php-fpm"]
