<?php

use App\Console\Commands\VerificarDocumentos;
use App\Models\VerificacionDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 21-09-2026: el anexo de poliza de FLOTA sin fechas ya no se da por definitivo
 * (ver VerificarDocumentos::procesar, INTENTOS). Los que quedaron como "No se pudo leer" con
 * el lector viejo se vuelven a leer YA, sin esperar a la noche:
 *
 * 1. Cada "No se pudo leer" o error recupera sus MAX_INTENTOS (el viejo le ponia los tres de
 *    golpe al anexo de flota, y con uno solo un texto cortado de Drive lo dejaria igual).
 * 2. Se pide la lectura como el boton "Revisar ahora" del panel: el programador lanza los
 *    cuatro lectores en el siguiente minuto (solo en el servidor, ver routes/console.php).
 *
 * Una sola vez: es una migracion. down vacio: las lecturas se rehacen solas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        DB::table('verificacion_documento_registro')
            ->whereIn('ESTADO', [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR])
            ->update(['INTENTOS' => 0]);

        VerificarDocumentos::pedirAhora();
    }

    public function down(): void
    {
    }
};
