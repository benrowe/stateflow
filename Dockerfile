FROM php:8.2-cli AS base

# Install system dependencies in one layer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install pcov \
    && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure git to trust the /app directory
RUN git config --global --add safe.directory /app

# Set working directory
WORKDIR /app

# Add Composer configuration for better performance
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# Development stage with dependencies
FROM base AS dev

# Copy composer files first for better layer caching
COPY composer.json composer.lock* ./

# Install dependencies with optimizations
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    && composer clear-cache

# Default command
CMD ["/bin/bash"]
