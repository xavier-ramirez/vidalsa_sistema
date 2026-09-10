<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Compresion de PDF de documentos (docs:comprimir). Aqui y no con env() suelto porque
    // produccion corre config:cache.
    'compresion_pdf' => [
        // Ghostscript: "gs" en el servidor (Linux, instalado en el Dockerfile); en Windows,
        // la ruta al gswin64c.exe.
        'ghostscript' => env('GHOSTSCRIPT_BIN', 'gs'),
        // Donde corre la tarea de madrugada. Sin definir (lo normal) se decide sola: corre
        // si la base de datos esta en otra maquina (el servidor) y no si esta en este mismo
        // equipo (el PC de desarrollo, que comparte Google Drive pero NO la base). Ver
        // ComprimirDocumentos::activadaAqui(). true/false la fuerzan.
        'nocturna' => env('COMPRESION_PDF_NOCTURNA'),
    ],

];
