# Render serves this PHP app through Apache in a Docker web service.
FROM php:8.2-apache

# Enable the MySQL PDO driver and cURL used by the application.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && (getent group 1000 >/dev/null || groupadd --gid 1000 rendersecrets) \
    && usermod -a -G 1000 www-data \
    && rm -rf /var/lib/apt/lists/*

# Render's default web-service port is 10000.
RUN sed -i 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY .htaccess /var/www/html/.htaccess
COPY config.php auth_guard.php index.php login.php logout.php dashboard.php live_data.php \
    presence.js user_presence.php \
    historicaldatapage.php notificationspage.php profile.php usermanagement.php \
    api_update.php /var/www/html/
COPY biosync_ml/ml_widget.php /var/www/html/biosync_ml/ml_widget.php

EXPOSE 10000
