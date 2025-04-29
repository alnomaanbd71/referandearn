FROM php:apache

# Enable Apache rewrite module
RUN a2enmod rewrite

# Create and set permissions for writable files before copying code
RUN touch /var/www/html/users.json && \
    touch /var/www/html/error.log && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod 666 /var/www/html/users.json && \
    chmod 666 /var/www/html/error.log

# Copy application files
COPY . /var/www/html/

# Configure Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Final permission fix
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod 666 /var/www/html/users.json && \
    chmod 666 /var/www/html/error.log
