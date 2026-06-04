#!/bin/bash

echo "=========================================="
echo "  SFS Maquinaria - Iniciando aplicación  "
echo "=========================================="

# Esperar a que la base de datos esté disponible
echo "[1/6] Esperando conexión a la base de datos..."
sleep 20

# Verificar conexión a la base de datos
echo "[2/6] Verificando conexión a MySQL..."
php artisan db:show --counts 2>/dev/null || echo "Advertencia: No se pudo verificar la base de datos"

# Generar APP_KEY solo si NO viene ya por entorno (easypanel inyecta las env
# vars, incluida APP_KEY) y existe un .env físico donde escribirla. Evita el
# error ruidoso "file_get_contents(.env): No such file" cuando la key ya viene
# del entorno del contenedor.
echo "[3/6] Verificando APP_KEY..."
if [ -z "$APP_KEY" ] && [ -f .env ]; then
    php artisan key:generate --force --no-interaction || true
else
    echo "APP_KEY provista por el entorno (easypanel) — se omite key:generate."
fi

# Limpiar caches
echo "[4/6] Limpiando caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Ejecutar migraciones
echo "[5/6] Ejecutando migraciones..."
php artisan migrate --force --no-interaction

# Optimizar para producción
echo "[6/6] Optimizando para producción..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Ajustar permisos finales (Crítico para evitar Error 500 en logs)
echo "[7/7] Asegurando permisos de storage y cache..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "=========================================="
echo "  Aplicación lista - Iniciando servidor  "
echo "=========================================="

# Iniciar supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
