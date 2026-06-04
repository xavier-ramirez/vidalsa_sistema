FROM php:8.3-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    nginx \
    libicu-dev \
    supervisor

# Limpiar cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Configuración personalizada de PHP
COPY docker/php.ini /usr/local/etc/php/conf.d/app-php.ini

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Versiona el Service Worker en cada build: reemplaza el placeholder
# __CACHE_VERSION__ por un timestamp único para que el PWA invalide sus caches
# internos en cada deploy y nunca sirva assets viejos.
RUN sed -i "s/__CACHE_VERSION__/$(date +%s)/g" public/sw.js || true

# Instalar dependencias de PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Instalar Node.js y NPM para compilar assets
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Compilar assets
RUN npm install && npm run build

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copiar configuración de nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Copiar configuración de supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Exponer puerto
EXPOSE 80

# Script de inicio
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
