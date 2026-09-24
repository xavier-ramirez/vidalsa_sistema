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
    ],

    // ¿Esta instalacion es el servidor? Solo alli se tocan archivos de Google Drive
    // (compresion nocturna, borrado de documentos reemplazados): el PC de desarrollo comparte
    // el Drive pero NO la base. Sin definir (lo normal) se decide sola por donde esta la base
    // (App\Support\EnlacesDocumentos::esBaseDelServidor); true/false la fuerzan.
    'drive' => [
        'es_servidor' => env('DRIVE_ES_SERVIDOR'),
    ],

    // Segundo lector de documentos (App\Services\LectorGemini): Gemini mira el PDF entero
    // cuando el OCR de Drive no alcanza. Sin GEMINI_API_KEY el sistema trabaja como siempre.
    // Los topes son los del plan GRATIS (AI Studio, medidos el 22-09-2026); si se paga un plan
    // con mas cupo, basta subirlos por .env sin tocar codigo.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),

        // El de diario: rapido, barato y con cupo grande (15 por minuto, 500 al dia).
        'modelo' => env('GEMINI_MODELO', 'gemini-3.5-flash-lite'),
        'rpm'    => env('GEMINI_RPM', 15),
        'rpd'    => env('GEMINI_RPD', 450),

        // El de los casos duros: lee mejor pero su cupo diario es minimo (20 al dia), asi que
        // solo se usa cuando el documento quedo ilegible o los datos no cuadran.
        'modelo_dificil' => env('GEMINI_MODELO_DIFICIL', 'gemini-3.8-flash'),
        'rpd_dificil'    => env('GEMINI_RPD_DIFICIL', 18),

        // Espera por documento. 75 s con holgura: medido el 22-09-2026 son ~6 s de media y
        // ~20 s el peor. Tiene que caber en el tope de la peticion de la carga masiva
        // (set_time_limit(180) en CargaMasivaDocumentosController) junto con la subida y el OCR.
        'timeout' => env('GEMINI_TIMEOUT', 75),
    ],

];
