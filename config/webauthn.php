<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RP ID (dominio al que quedan atadas las huellas)
    |--------------------------------------------------------------------------
    |
    | En WebAuthn, una credencial se registra CONTRA UN DOMINIO. El navegador solo
    | la ofrece si el dominio de la página coincide con el RP ID con el que se
    | registró. Por eso `midominio.com` y `www.midominio.com` son, para la huella,
    | dos sitios distintos: quien la registre en uno no puede usarla en el otro.
    |
    | Sin valor aquí se usa el host de cada petición (comportamiento histórico), que
    | funciona mientras se entre SIEMPRE por la misma URL. En cuanto hay dos formas
    | de llegar —con y sin www, un dominio nuevo, una IP— las huellas se parten.
    |
    | Fijándolo al dominio registrable (p.ej. "midominio.com") las credenciales valen
    | también en sus subdominios, y un cambio de la URL de entrada deja de importar.
    |
    | OJO: cambiar este valor INVALIDA las huellas ya registradas — habrá que
    | volver a activarlas una vez. Por eso viene vacío por defecto.
    |
    */

    'rp_id' => env('WEBAUTHN_RP_ID'),

];
