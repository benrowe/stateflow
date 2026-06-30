#!/bin/bash
set -euo pipefail

# Only run in remote (web) sessions
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# Install PHP (8.2+) if missing or below minimum version
if ! command -v php &>/dev/null || ! php -r "exit(version_compare(PHP_VERSION, '8.2.0', '>=') ? 0 : 1);"; then
  echo "Installing PHP..."
  DEBIAN_FRONTEND=noninteractive apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php-cli php-xml php-mbstring php-zip
fi

# Install Composer if missing
if ! command -v composer &>/dev/null; then
  echo "Installing Composer..."
  curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Attempt to install pcov (needed for coverage; best-effort, not fatal)
if ! php -m 2>/dev/null | grep -q pcov; then
  PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php${PHP_VER}-pcov" 2>/dev/null \
    || echo "Note: pcov could not be installed - coverage reporting will be skipped"
fi

# Persist env vars for the session
echo 'export COMPOSER_ALLOW_SUPERUSER=1' >> "$CLAUDE_ENV_FILE"

# Install project dependencies
cd "$CLAUDE_PROJECT_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader
