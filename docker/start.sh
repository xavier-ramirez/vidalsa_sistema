#!/bin/bash

echo "=========================================="
echo "  SFS Maquinaria - Iniciando aplicación  "
echo "=========================================="

# ── PÁGINA DE MANTENIMIENTO PRIMERO ──────────────────────────────────────────
# Todo lo que viene debajo (esperar la BD, migrar, cachear) tarda cerca de un
# minuto, y antes NADIE escuchaba en el puerto 80 durante ese rato: el proxy de
# EasyPanel se quedaba sin backend y enseñaba su pantalla genérica de "página no
# disponible". Ahora nginx arranca de una con docker/nginx-mantenimiento.conf y
# responde 503 + el aviso con el logo de la empresa desde el primer segundo.
#
# supervisord va al FONDO (no exec) para poder recargar nginx al final sin soltar
# el puerto. El trap le pasa la señal de apagado: sin él, `docker stop` mataría a
# este bash y el contenedor tardaría los 10 s del SIGKILL en cerrar.
echo "[1/10] Publicando página de mantenimiento..."
cp docker/nginx-mantenimiento.conf /etc/nginx/sites-available/default
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &
SUPERVISOR_PID=$!
trap 'kill -TERM "$SUPERVISOR_PID" 2>/dev/null' TERM INT

# Esperar a que la base de datos esté disponible
echo "[2/10] Esperando conexión a la base de datos..."
sleep 20

# Verificar conexión a la base de datos
echo "[3/10] Verificando conexión a MySQL..."
php artisan db:show --counts 2>/dev/null || echo "Advertencia: No se pudo verificar la base de datos"

# Generar APP_KEY solo si NO viene ya por entorno (easypanel inyecta las env
# vars, incluida APP_KEY) y existe un .env físico donde escribirla. Evita el
# error ruidoso "file_get_contents(.env): No such file" cuando la key ya viene
# del entorno del contenedor.
echo "[4/10] Verificando APP_KEY..."
if [ -z "$APP_KEY" ] && [ -f .env ]; then
    php artisan key:generate --force --no-interaction || true
else
    echo "APP_KEY provista por el entorno (easypanel) — se omite key:generate."
fi

# Limpiar caches
echo "[5/10] Limpiando caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Ejecutar migraciones
echo "[6/10] Ejecutando migraciones..."
php artisan migrate --force --no-interaction

# Symlink public/storage -> storage/app/public. Necesario para servir los
# documentos de equipos AUXILIARES (se guardan en disco 'public' y se sirven en
# /storage/...). NO viene en el repo (public/storage está en .gitignore), así
# que hay que crearlo en cada arranque o el PDF subido da 404 en el servidor.
# `|| true`: si el enlace ya existe (redeploy con volumen persistente) no es error.
echo "[7/10] Creando symlink storage..."
php artisan storage:link --no-interaction || true

# Optimizar para producción
echo "[8/10] Optimizando para producción..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Ajustar permisos finales (Crítico para evitar Error 500 en logs)
echo "[9/10] Asegurando permisos de storage y cache..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "=========================================="
echo "  Aplicación lista - Iniciando servidor  "
echo "=========================================="

# ── QUITAR LA PÁGINA DE MANTENIMIENTO ────────────────────────────────────────
# La app ya puede responder: se pone la config real y se recarga nginx EN CALIENTE.
# `reload` no cierra el socket del puerto 80 —el proceso maestro sigue siendo el
# mismo y solo cambia sus workers—, así que no hay ni un segundo sin backend y el
# proxy de EasyPanel nunca llega a enseñar su pantalla de error.
echo "[10/10] Retirando página de mantenimiento..."
cp docker/nginx.conf /etc/nginx/sites-available/default

# nginx lo arranca supervisord en paralelo; el pid tarda un instante en aparecer.
# En la práctica ya está listo (arriba pasó un minuto largo), pero si el arranque
# fuera más rápido que supervisord no queremos recargar contra un proceso que no
# existe todavía.
for _ in $(seq 1 30); do
    [ -f /run/nginx.pid ] && break
    sleep 1
done

if nginx -t && nginx -s reload; then
    echo "nginx recargado con la configuración de la aplicación."
else
    # La config real no valida o nginx no respondió: se deja el aviso de
    # mantenimiento puesto (mejor el cartel de la empresa que un 502 pelado) y
    # se avisa fuerte en el log del despliegue.
    echo "ERROR: no se pudo activar la configuración de la aplicación." >&2
    cp docker/nginx-mantenimiento.conf /etc/nginx/sites-available/default
    nginx -s reload || true
fi

# Ceder el control a supervisor (php-fpm + nginx), que ya está corriendo.
wait "$SUPERVISOR_PID"
