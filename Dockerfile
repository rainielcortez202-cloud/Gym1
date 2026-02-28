FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxpm-dev \
        libonig-dev \
        libicu-dev \
        libcurl4-openssl-dev \
        unzip \
        git \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install pdo pdo_pgsql pgsql gd calendar curl mbstring intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set Brevo API Key as environment variable
ENV BREVO_API_KEY=your_brevo_api_key_here

# Copy project files
COPY . /var/www/html/

WORKDIR /var/www/html

# Install Composer dependencies (if composer.json exists)
RUN if [ -f composer.json ]; then composer install; fi

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
