<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compresion de PDF de documentos, de madrugada y de 5 en 5 (ver docs:comprimir).
// Entre las 5:00 y las 6:30 a.m. (hora de la app, America/Caracas) se intenta cada minuto:
// DESPUES de la verificacion (00:30-04:50), porque las dos leen de Google Drive, con diez
// minutos de margen para que la ultima tanda de la verificacion termine tranquila;
// withoutOverlapping impide que un lote arranque encima del anterior (el candado caduca a
// los 30 min por si una pasada muriera a medias) y el propio comando deja un minuto de
// descanso entre el fin de un lote y el siguiente. Si ya no queda nada, las pasadas
// terminan al instante.
// Necesita el programador corriendo: `php artisan schedule:work` (docker/supervisord.conf).
// Y solo corre en el servidor: ver App\Support\EnlacesDocumentos::esBaseDelServidor().
Schedule::command('docs:comprimir --lote=5')
    ->when(fn () => \App\Support\EnlacesDocumentos::esBaseDelServidor()[0])
    ->everyMinute()
    ->between('05:00', '06:30')
    ->withoutOverlapping(30)
    ->runInBackground();

// Verificacion de los documentos contra sus PDF (docs:verificar-documentos), de 12:30 a las
// 4:50 de la madrugada y de 60 en 60. Su ventana NO se toca con la de la compresion (05:00-06:30):
// las dos leen de Google Drive y no deben pisarse.
// El tamaño de la tanda no cambia el consumo (el servidor solo gasta 0,07 s de CPU por
// documento; el resto es esperar a Drive), pero sí el tiempo muerto: con tandas de 10 la
// pasada duraba poco mas de un minuto y withoutOverlapping hacia perder el minuto siguiente.
// Con 60 la pasada dura unos 6 minutos y encadena sin huecos. Medido en el servidor el
// 18-09-2026: 8,6 documentos por minuto. Los 1.900 documentos cargados piden unas 3 h 40,
// asi que con la ventana hasta las 4:50 caben (rinde ~2.200) con un 20% de margen por si
// Drive se pone lento, y se leen todos en UNA noche.
// MANDA EL DOCUMENTO (CorrectorFichaDocumento): lo que dice el PDF se escribe en la ficha,
// este vacia o diga otra cosa. NUNCA la placa ni el serial, y NUNCA nada si el documento es
// de otro vehiculo, se leyo a medias o no se pudo confirmar de quien es: eso queda en Control
// de Auditoría para que lo mire una persona. Solo en el servidor: en el PC de desarrollo la
// base es una copia y esas correcciones no le servirian a nadie (ahi se usa --no-rellenar).
Schedule::command('docs:verificar-documentos --lote=60')
    ->when(fn () => \App\Support\EnlacesDocumentos::esBaseDelServidor()[0])
    ->everyMinute()
    ->between('00:30', '04:50')
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
