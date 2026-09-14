FROM php:8.4-fpm

# Set working directory
WORKDIR /var/www

# Set timezone
RUN ln -fs /usr/share/zoneinfo/Asia/Manila /etc/localtime && dpkg-reconfigure -f noninteractive tzdata

# Install system dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    vim \
    unzip \
    git \
    curl \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    imagemagick \
    ghostscript

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions with WebP support
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Configure PHP for 14MB upload limit (accommodates base64-encoded ~10MB images)
RUN echo 'upload_max_filesize = 14M' >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo 'post_max_size = 14M' >> /usr/local/etc/php/conf.d/uploads.ini

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy existing application directory permissions
COPY . /var/www
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
