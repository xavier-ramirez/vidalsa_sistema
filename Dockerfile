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

# El Service Worker ya NO se estampa en build: la plantilla vive en
# resources/sw.js y la ruta GET /sw.js (routes/web.php) reemplaza
# __CACHE_VERSION__ con el filemtime en cada request, con headers no-cache.

# Instalar dependencias de PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# NO hay paso de build de assets: el front no usa bundler. El CSS y el JS se
# sirven ya escritos desde public/css y public/js (versionados con ?v=filemtime
# en estructura_base.blade.php). Antes esta imagen instalaba Node 20 + npm y
# corria `npm run build`, pero ningun blade usaba @vite ni el manifest de
# public/build: el bundle se compilaba y se tiraba a la basura en cada build.

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
