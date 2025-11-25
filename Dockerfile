FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN pecl install pcov \
    && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure git to trust the /app directory
RUN git config --global --add safe.directory /app

# Set working directory
WORKDIR /app

# Default command
CMD ["/bin/bash"]
