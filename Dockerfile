FROM php:8.2-apache

# Enable mysqli extension (required by the app)
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Enable Apache mod_rewrite (useful for future URL rewriting)
RUN a2enmod rewrite

# Copy application files into the web root
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
