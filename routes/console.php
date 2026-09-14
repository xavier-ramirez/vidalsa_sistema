<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compresion de PDF de documentos, de madrugada y de 5 en 5 (ver docs:comprimir).
// Entre las 12:00 y las 5:00 a.m. (hora de la app, America/Caracas) se intenta cada minuto;
// withoutOverlapping impide que un lote arranque encima del anterior (el candado caduca a
// los 30 min por si una pasada muriera a medias) y el propio comando deja un minuto de
// descanso entre el fin de un lote y el siguiente. Si ya no queda nada, las pasadas
// terminan al instante.
// Necesita el programador corriendo: `php artisan schedule:work` (docker/supervisord.conf).
// Y solo corre en el servidor: ver App\Support\EnlacesDocumentos::esBaseDelServidor().
Schedule::command('docs:comprimir --lote=5')
    ->when(fn () => \App\Support\EnlacesDocumentos::esBaseDelServidor()[0])
    ->everyMinute()
    ->between('00:00', '05:00')
    ->withoutOverlapping(30)
    ->runInBackground();

// La caché vive en la base de datos (CACHE_STORE=database), y ahí una entrada caducada solo
// se borra si alguien la vuelve a leer. Las cachés con la versión en la clave (el tablero del
// menú, el historial de documentos) dejan una entrada nueva por usuario en cada cambio de
// datos, de hasta 2,8 MB, y la vieja no se lee nunca más: se quedaban para siempre (medido el
// 13-09-2026: 361 MB caducados). En tandas pequeñas para no bloquear la tabla en uso.
Schedule::call(function () {
    if (config('cache.default') !== 'database') return;
    $tabla = DB::connection(config('cache.stores.database.connection'))
        ->table(config('cache.stores.database.table', 'cache'));
    do {
        $borradas = (clone $tabla)->where('expiration', '<', now()->getTimestamp())->limit(20)->delete();
    } while ($borradas === 20);
})->hourly()->name('cache:purgar-caducadas')->withoutOverlapping();
