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
// fuera de la ventana de la verificacion (09:05-13:05), porque las dos leen de Google Drive;
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

// Verificacion de los documentos contra sus PDF (docs:verificar-documentos), de 9:05 de la
// mañana a 1:05 de la tarde y de 25 en 25. Su ventana NO se toca con la de la compresion (05:00-06:30):
// las dos leen de Google Drive y no deben pisarse.
// El tamaño de la tanda no cambia el consumo (el servidor solo gasta 0,07 s de CPU por
// documento; el resto es esperar a Drive), pero sí el tiempo muerto: el tick es cada minuto
// y withoutOverlapping no deja arrancar encima, asi que lo que sobra de minuto se pierde.
// Con 25 la pasada dura 2:22 y arranca una cada 3 minutos (38 s de espera): 8,3 documentos
// por minuto. Se eligio 25 porque es el tamaño que MENOS se resiente si Drive se pone lento:
// a 7 s por documento la pasada sube a 2:55 y sigue cabiendo en los mismos 3 minutos, o sea
// el mismo ritmo. Con 10 el ritmo se caeria a la mitad (5/min) y con 60 bajaria a 8,6.
// Medido en el servidor el 18-09-2026: 5,7 s por documento, ~8 documentos por minuto por
// lector; con los cuatro de abajo, ~30. Los ~1.900 cargados caben de sobra en la ventana.
// De dia a proposito (18-09-2026): la de la noche no llego a terminar. El servidor apenas lo
// nota (0,07 s de CPU por documento) y la ficha se escribe con bloqueo de fila, asi que no
// choca con quien este editando a la vez.
// MANDA EL DOCUMENTO (CorrectorFichaDocumento): lo que dice el PDF se escribe en la ficha,
// este vacia o diga otra cosa. NUNCA la placa ni el serial, y NUNCA nada si el documento es
// de otro vehiculo, se leyo a medias o no se pudo confirmar de quien es: eso queda en Control
// de Auditoría para que lo mire una persona. Solo en el servidor: en el PC de desarrollo la
// base es una copia y esas correcciones no le servirian a nadie (ahi se usa --no-rellenar).
// CUATRO lectores a la vez, cada uno con su cuarta parte de las fichas (--parte/--de, ver
// VerificarDocumentos::reparto): lo que tarda es esperar a Drive, no el servidor, y con uno
// solo la cola iba a ~8 documentos por minuto. Cada comando lleva su propio candado
// (withoutOverlapping va por la linea de comando), asi que no se estorban entre ellos.
foreach (range(0, 3) as $parte) {
    Schedule::command("docs:verificar-documentos --lote=25 --parte=$parte --de=4")
        ->when(fn () => \App\Support\EnlacesDocumentos::esBaseDelServidor()[0])
        ->everyMinute()
        ->between('09:05', '13:05')
        ->withoutOverlapping(30)
        ->runInBackground();
}

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
