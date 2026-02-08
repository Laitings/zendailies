FROM php:8.4-apache

# 1. Install MySQL extensions needed for Zendailies
RUN docker-php-ext-install pdo pdo_mysql

# 2. Enable Apache modules for your .htaccess and headers
RUN a2enmod rewrite headers

# 3. Ensure we use the standard 'prefork' for PHP module compatibility
RUN a2dismod mpm_event && a2enmod mpm_prefork

WORKDIR /var/www/html
