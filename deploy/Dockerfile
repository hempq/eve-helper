# Standalone always-on image for EVE Helper — independent of DDEV.
# `ddev poweroff` never touches this stack (own compose project & network).
FROM php:8.3-cli

# Reliable multi-extension installer.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_sqlite intl bcmath zip bz2 pcntl sockets mbstring gd @composer \
    && apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
    && git config --global --add safe.directory /app

WORKDIR /app

# Code and the SQLite database are bind-mounted at runtime (see compose),
# so the image carries no app code — rebuilds are rare (only on ext changes).
EXPOSE 8000
