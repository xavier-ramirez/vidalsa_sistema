<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store de caché de la copia local
    |--------------------------------------------------------------------------
    |
    | Si los triggers del registro de cambios están creados (RegistroCambios::activo)
    | se pregunta en CADA sondeo de CADA cliente, así que debe leerse SIN tocar MySQL. Con el store
    | por defecto del proyecto (CACHE_STORE=database) cada lectura sería una
    | consulta, justo lo que se quiere evitar; por eso aquí se usa 'file'.
    |
    | Válido porque el despliegue es de un solo host (supervisord con php-fpm +
    | nginx, ver docker/supervisord.conf). Si algún día se escala a varios
    | contenedores, el caché de fichero deja de ser coherente entre ellos: hay
    | que cambiar esto a 'redis' (o al store compartido que se use).
    |
    */

    'store' => env('OFFLINE_CACHE_STORE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Administrador para crear los triggers del registro de cambios
    |--------------------------------------------------------------------------
    |
    | En MySQL 8 con registro binario, crear triggers exige SUPER y el usuario de la
    | app puede no tenerlo. Si se da la clave de root (la del servicio MySQL de
    | EasyPanel), el arranque los crea con ella pero A NOMBRE del usuario de la app
    | (RegistroCambios::asegurar). Opcional: sin clave, el sistema sigue en el modo
    | de respaldo y el log del arranque lo dice.
    |
    */

    'triggers_admin' => [
        'username' => env('DB_ADMIN_USERNAME', 'root'),
        'password' => env('DB_ADMIN_PASSWORD'),
    ],

];
