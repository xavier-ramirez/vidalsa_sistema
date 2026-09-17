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

// Verificacion de los documentos contra sus PDF (docs:verificar-documentos), de 9 de la noche
// a medianoche y de 5 en 5. Su ventana NO se toca con la de la compresion (00:00-05:00): las
// dos leen de Google Drive y no deben pisarse. Una pasada lee 5 documentos (~8 s cada uno),
// asi que en una noche entran unos 900.
// No cambia ninguna ficha: solo deja el resultado en Control de Auditoría para que una
// persona decida. Solo en el servidor, como la compresion: en el PC de desarrollo la base es
// una copia y las correcciones se perderian.
Schedule::command('docs:verificar-documentos --lote=5')
    ->when(fn () => \App\Support\EnlacesDocumentos::esBaseDelServidor()[0])
    ->everyMinute()
    ->between('21:00', '23:59')
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
