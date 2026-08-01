<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store de caché para las versiones del snapshot offline
    |--------------------------------------------------------------------------
    |
    | Las versiones por dominio (App\Support\OfflineVersion) se consultan en CADA
    | sondeo de CADA cliente, así que deben leerse SIN tocar MySQL. Con el store
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

];
