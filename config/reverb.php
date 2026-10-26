<?php

/*
 * Servidor de WebSockets (php artisan reverb:start). Lo arranca supervisord dentro del
 * contenedor y escucha SOLO por dentro (127.0.0.1): afuera se llega por nginx en /app, así
 * que su API para publicar (/apps/...) no queda expuesta. La app es una sola y comparte la
 * clave con config/broadcasting.php.
 */

$conexion = config('broadcasting.connections.reverb');

// Solo se aceptan conexiones abiertas desde la propia app (el dominio de APP_URL): una página
// ajena no puede usar los navegadores de quien la visita para llenar el servidor. Con APP_URL
// local (localhost) no hay dominio que comparar y se acepta cualquiera. REVERB_ORIGENES
// (separados por coma) lo cambia sin tocar esto. Si no coincidiera con el dominio real, el
// tiempo real no conecta y las pantallas vuelven a preguntar cada 15 s: nada se rompe.
$dominioApp = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
$origenes = env('REVERB_ORIGENES')
    ? array_map('trim', explode(',', (string) env('REVERB_ORIGENES')))
    : ($dominioApp && !in_array($dominioApp, ['localhost', '127.0.0.1'], true) ? [$dominioApp] : ['*']);

return [

    'default' => 'reverb',

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => '',
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => 10_000,
            'scaling' => ['enabled' => false, 'channel' => 'reverb', 'server' => []],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => $conexion['key'],
                'secret' => $conexion['secret'],
                'app_id' => $conexion['app_id'],
                'options' => $conexion['options'],
                'allowed_origins' => $origenes,
                'ping_interval' => 60,
                'activity_timeout' => 30,
                // Tope de pantallas conectadas a la vez (cada pestaña abierta es una). Sobra para
                // la empresa y corta a quien abra conexiones sin parar.
                'max_connections' => 1000,
                'max_message_size' => 10_000,
                // Los navegadores solo escuchan: no se mandan avisos entre ellos.
                'accept_client_events_from' => 'none',
                'rate_limiting' => ['enabled' => false, 'max_attempts' => 60, 'decay_seconds' => 60, 'terminate_on_limit' => false],
            ],
        ],

    ],

];
