<?php

use App\Console\Commands\ComprimirDocumentos;
use App\Console\Commands\VerificarDocumentos;
use App\Models\VerificacionDocumento;
use App\Support\EnlacesDocumentos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Documentos: dos tareas de noche que leen de Google Drive, en franjas que NO se tocan ──
// Las horas viven en cada comando (su HORARIO, hora de la app: America/Caracas) y el panel
// de Control de Auditoría las lee de ahí; aquí no se escribe ninguna.
//   · docs:verificar-documentos  VerificarDocumentos::HORARIO  (8:00 p.m. – 12:00 de la noche)
//   · docs:comprimir             ComprimirDocumentos::HORARIO  (2:00 – 5:00 a.m.)
// La lectura arranca también fuera de su franja si se pulsa "Revisar ahora" en el panel
// (VerificarDocumentos::pedirAhora). Dentro de su franja se intentan cada minuto, pero SOLO se lanza el proceso si hay algo que
// hacer: cuando todo está leído / comprimido, o cuando termina, no arranca nada más y no se
// gastan recursos. withoutOverlapping impide que un lote arranque encima del anterior (el
// candado caduca a los 30 min por si una pasada muriera a medias).
// Necesita el programador corriendo: `php artisan schedule:work` (docker/supervisord.conf), y
// solo corre en el servidor: ver EnlacesDocumentos::esBaseDelServidor(). En el orden de los
// when(): primero la franja, así fuera de hora no se consulta nada.

// Verificación de los documentos contra sus PDF: CUATRO lectores a la vez, cada uno con su
// cuarta parte de las fichas (--parte/--de, ver VerificarDocumentos::reparto). Lo que tarda
// es esperar a Drive (5,7 s por documento, medido en el servidor el 18-09-2026), no el
// servidor (0,07 s de CPU): cada lector hace ~8 documentos por minuto y los cuatro ~30.
// Tandas de 25: la pasada dura ~2:22 y cabe en el tick de 3 minutos aunque Drive vaya lento.
// Cada comando lleva su propio candado (withoutOverlapping va por la línea de comando).
// MANDA EL DOCUMENTO (CorrectorFichaDocumento), salvo la placa y el serial, el PDF de otro
// vehículo, el leído a medias, el sin confirmar y el ANTERIOR: eso lo mira una persona.
// ¿Hay trabajo? se pregunta UNA vez por minuto para los cuatro (este archivo se carga en cada
// schedule:run), no cuatro.
$hayQueVerificar = null;
foreach (range(0, 3) as $parte) {
    Schedule::command("docs:verificar-documentos --lote=25 --parte=$parte --de=4")
        ->everyMinute()
        ->when(fn () => VerificarDocumentos::tocaLeer())   // su franja, o "Revisar ahora" del panel
        ->when(fn () => EnlacesDocumentos::esBaseDelServidor()[0])
        ->when(function () use (&$hayQueVerificar) {
            return $hayQueVerificar ??= VerificacionDocumento::hayTrabajo();
        })
        ->withoutOverlapping(30)
        ->runInBackground();
}

// Compresión de PDF, de 5 en 5 (ver docs:comprimir). El propio comando deja un minuto de
// descanso entre lotes. Cuando comprueba que no queda nada, lo apunta para esa noche
// (ComprimirDocumentos::nadaEstaNoche) y a partir de ahí no se vuelve a lanzar.
Schedule::command('docs:comprimir --lote=5')
    ->everyMinute()
    ->between(...ComprimirDocumentos::HORARIO)
    ->when(fn () => EnlacesDocumentos::esBaseDelServidor()[0])
    ->when(fn () => !ComprimirDocumentos::nadaEstaNoche())
    ->withoutOverlapping(30)
    ->runInBackground();

// La caché vive en la base de datos (CACHE_STORE=database), y ahí una entrada caducada solo
// se borra si alguien la vuelve a leer. Las cachés con la versión en la clave (el tablero del
// menú) dejan una entrada nueva por usuario en cada cambio de datos, de hasta 2,8 MB, y la
// vieja no se lee nunca más: se quedaban para siempre (medido el 13-09-2026: 361 MB
// caducados). En tandas pequeñas para no bloquear la tabla en uso.
Schedule::call(function () {
    if (config('cache.default') !== 'database') return;
    $tabla = DB::connection(config('cache.stores.database.connection'))
        ->table(config('cache.stores.database.table', 'cache'));
    do {
        $borradas = (clone $tabla)->where('expiration', '<', now()->getTimestamp())->limit(20)->delete();
    } while ($borradas === 20);
})->hourly()->name('cache:purgar-caducadas')->withoutOverlapping();

// Control de Auditoría: la lista del historial siempre hecha. Armarla cuesta 2-4 s y la paga
// quien abre la pantalla si no está en caché; se rehace sola tras cada cambio
// (HistorialDocumentosController::programarCalentado), pero no tras un despliegue ni cuando
// caduca. Esto la deja lista en esos casos; si ya está al día no hace nada.
Schedule::call(fn () => app(\App\Http\Controllers\HistorialDocumentosController::class)->calentar())
    ->everyFiveMinutes()->name('historial-documentos:calentar')->withoutOverlapping(10);
