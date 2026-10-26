<?php

/*
 * Avisos en TIEMPO REAL (ver App\Console\Commands\AvisarCambios y public/js/offline/tiempo-real.js).
 *
 * El servidor de WebSockets es Laravel Reverb, dentro del mismo contenedor (supervisord) y
 * detrás de nginx en /app. No hace falta ninguna variable en EasyPanel: la clave y el secreto
 * salen de APP_KEY, y HuboCambios va SIEMPRE por la conexión 'reverb', sin mirar
 * BROADCAST_CONNECTION (la plantilla de Laravel la deja en 'log'). TIEMPO_REAL=false lo apaga:
 * la copia local sigue preguntando cada `espera` segundos, como antes.
 */

$secreto = static fn (string $para) => substr(hash_hmac('sha256', 'reverb|' . $para, (string) env('APP_KEY')), 0, 32);

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', $secreto('clave')),
            'secret' => env('REVERB_APP_SECRET', $secreto('secreto')),
            'app_id' => env('REVERB_APP_ID', 'vidalsa'),
            // Cómo llega PHP al servidor de Reverb: por dentro del contenedor, sin TLS.
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Un aviso que no sale (Reverb caído) no debe frenar a nadie: espera poco.
            'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    /*
     * Lo que el navegador necesita para conectarse (meta "tiempo-real" en estructura_base).
     * Sin puerto se usa el de la página: en el servidor nginx pasa /app a Reverb por el mismo
     * 443. En local (XAMPP) se va directo al puerto de `php artisan reverb:start`.
     *
     * El canal es público pero con nombre secreto (sale de APP_KEY) y solo lo recibe quien
     * entró: así conectarse no pide /broadcasting/auth, que renovaría la sesión sin que el
     * usuario toque nada. Lo único que viaja es «hubo cambios»; los datos se piden con sesión.
     */
    'cliente' => [
        'activo' => (bool) env('TIEMPO_REAL', true),
        'puerto' => env('REVERB_PUERTO_CLIENTE'),
        'canal' => 'cambios.' . $secreto('canal'),
    ],

];
