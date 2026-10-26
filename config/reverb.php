<?php

/*
 * Servidor de WebSockets (php artisan reverb:start). Lo arranca supervisord dentro del
 * contenedor y escucha SOLO por dentro (127.0.0.1): afuera se llega por nginx en /app, así
 * que su API para publicar (/apps/...) no queda expuesta. La app es una sola y comparte la
 * clave con config/broadcasting.php.
 */

$conexion = config('broadcasting.connections.reverb');

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
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => null,
                'max_message_size' => 10_000,
                // Los navegadores solo escuchan: no se mandan avisos entre ellos.
                'accept_client_events_from' => 'none',
                'rate_limiting' => ['enabled' => false, 'max_attempts' => 60, 'decay_seconds' => 60, 'terminate_on_limit' => false],
            ],
        ],

    ],

];
