<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Registro de la verificacion de los documentos de un equipo contra su ficha
     * (comando docs:verificar-documentos): titulo, poliza, ROTC y RACDA.
     *
     * Una fila por EQUIPO, TIPO de documento y archivo revisado. Sirve para lo mismo que
     * compresion_pdf_registro en su tarea: que la pasada de cada noche no vuelva a leer lo ya
     * leido —cada lectura le cuesta a Drive unos segundos— y que en la pantalla "Compresion de
     * PDF" se vea que coincide, que no y que no se pudo leer.
     *
     * Se guarda el ID de Drive del archivo revisado: si mañana suben otro documento a esa
     * ficha, el ID cambia y se vuelve a verificar sola (y la fila vieja se retira).
     *
     * Esta tabla es el REGISTRO de lo leido, no la ficha. Quien escribe en documentacion es
     * App\Services\CorrectorFichaDocumento, y aqui queda anotado cuando se hizo
     * (APLICADO_EN) y quien lo pidio (APLICADO_POR, vacio cuando lo hizo la tarea de noche).
     */
    public function up(): void
    {
        if (!Schema::hasTable('verificacion_documento_registro')) {
            Schema::create('verificacion_documento_registro', function (Blueprint $table) {
                $table->bigIncrements('ID_REGISTRO');
                $table->unsignedBigInteger('ID_EQUIPO')->index();
                // propiedad | poliza | rotc | racda
                $table->string('TIPO', 20)->index();
                // Como se identifica la fila en la pantalla (placa o serial del chasis).
                $table->string('PLACA', 30)->nullable();
                $table->string('SERIAL', 80)->nullable();
                // Archivo revisado; null cuando el enlace ya no apunta a ningun archivo.
                $table->string('DRIVE_ID', 80)->nullable()->index();
                // Lo que dice el documento (titular, aseguradora, fechas, nº, placa...).
                $table->json('LEIDO')->nullable();
                // Solo lo que NO cuadra: [campo => ['etiqueta', 'ficha', 'documento']]. Es lo
                // que pinta la pantalla y lo que aplica el boton.
                $table->json('DIFERENCIAS')->nullable();
                // coincide | difiere | ilegible | sin_archivo | error
                $table->string('ESTADO', 20)->index();
                $table->string('MOTIVO', 255)->nullable();
                // Cuanto texto saco Drive: casi cero = escaneo ilegible.
                $table->unsignedInteger('CARACTERES')->default(0);
                // Veces que se intento leer este archivo: los ilegibles y los fallidos vuelven
                // a la cola otras noches hasta VerificacionDocumento::MAX_INTENTOS.
                $table->unsignedTinyInteger('INTENTOS')->default(0);
                $table->unsignedBigInteger('APLICADO_POR')->nullable();
                $table->timestamp('APLICADO_EN')->nullable();
                $table->timestamps();

                $table->unique(['ID_EQUIPO', 'TIPO', 'DRIVE_ID'], 'uk_verif_doc_equipo_tipo_archivo');
            });
        }

        // A_MANO se queda aqui SOLO para las bases que se creen de cero con este archivo. En
        // las que ya corrieron esta migracion antes de que existiera la columna, este archivo
        // NO se vuelve a ejecutar (Laravel guarda su nombre en `migrations`): de eso se
        // encarga 2026_09_18_090000_add_a_mano_to_verificacion_documento_registro.php.
        if (!Schema::hasColumn('verificacion_documento_registro', 'A_MANO')) {
            Schema::table('verificacion_documento_registro', function (Blueprint $table) {
                $table->boolean('A_MANO')->default(false)->index()->after('INTENTOS');
            });
        }

        // Fecha en que se emitio cada documento: hasta ahora la ficha solo guardaba la de
        // vencimiento de la poliza. Las dos las lee el verificador del propio PDF ("Dado a los
        // 3 dias del mes de OCTUBRE de 2018" en el titulo; "Fecha de Emision" en la poliza).
        if (!Schema::hasColumn('documentacion', 'FECHA_EMISION_PROPIEDAD')) {
            Schema::table('documentacion', function (Blueprint $table) {
                $table->date('FECHA_EMISION_PROPIEDAD')->nullable()->after('LINK_DOC_PROPIEDAD');
            });
        }
        if (!Schema::hasColumn('documentacion', 'FECHA_EMISION_POLIZA')) {
            Schema::table('documentacion', function (Blueprint $table) {
                $table->date('FECHA_EMISION_POLIZA')->nullable()->after('FECHA_VENC_POLIZA');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verificacion_documento_registro');
        foreach (['FECHA_EMISION_PROPIEDAD', 'FECHA_EMISION_POLIZA'] as $col) {
            if (Schema::hasColumn('documentacion', $col)) {
                Schema::table('documentacion', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
