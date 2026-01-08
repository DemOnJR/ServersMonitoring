FROM php:8.4-apache

# SQLite support
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev \
  && docker-php-ext-install pdo_sqlite \
  && rm -rf /var/lib/apt/lists/*

# Apache rewrite + .htaccess
RUN a2enmod rewrite \
  && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# App
COPY php/ /var/www/html/

# Permissions:
# 1) Make everything owned by www-data so Apache can write anywhere
# 2) Set sane default perms (dirs 755, files 644)
# 3) Lock down sensitive folders: storage/db and storage/sessions
RUN set -eux; \
  chown -R www-data:www-data /var/www/html; \
  find /var/www/html -type d -exec chmod 0755 {} \;; \
  find /var/www/html -type f -exec chmod 0644 {} \;; \
  mkdir -p /var/www/html/storage/db /var/www/html/storage/sessions; \
  chown -R www-data:www-data /var/www/html/storage; \
  chmod 0700 /var/www/html/storage/db /var/www/html/storage/sessions; \
  find /var/www/html/storage/db -type f -exec chmod 0600 {} \; || true; \
  find /var/www/html/storage/sessions -type f -exec chmod 0600 {} \; || true

EXPOSE 80
