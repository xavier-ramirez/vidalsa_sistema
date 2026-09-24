<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La carga masiva deja de vivir dentro de su modal: cada PDF que se suelta pasa a ser una
 * fila mas de la tabla de "Revision de documentos", con su estado, al lado de lo que lee la
 * tarea de la noche. Asi hay UNA sola tabla de documentos en el modulo, que es lo que se
 * pidio (23-09-2026), y el modal se queda solo con la zona de soltar archivos.
 *
 * Tres cosas nuevas en `verificacion_documento_registro`:
 *
 *   · ID_EQUIPO puede ir VACIO. Hasta hoy toda fila era de una ficha, porque solo nacian
 *     leyendo documentos ya enlazados. Un PDF recien soltado puede no tener ficha todavia
 *     (no se reconocio de quien es), y esa fila tambien hay que poder verla.
 *   · ID_AUXILIAR, porque un documento puede ser de un equipo auxiliar, que es otra tabla.
 *   · ORIGEN, para no confundir las dos procedencias: 'noche' (docs:verificar-documentos) y
 *     'carga_masiva'. Lo usa la tarea nocturna para NO borrar una propuesta que todavia no se
 *     ha aplicado, y la pantalla para saber que botones ofrecer.
 *   · ARCHIVO, el nombre del PDF tal como lo solto el usuario: sin el, en la tabla no hay
 *     forma de saber cual de los treinta es cada fila.
 *   · PROPUESTA, lo que la carga masiva propone (las fichas candidatas y las fechas), para
 *     poder aplicarlo desde la tabla sin volver a leer el PDF.
 *
 * Lo de la noche no cambia: sus filas siguen naciendo con ORIGEN='noche', ID_EQUIPO lleno y
 * ID_AUXILIAR vacio, y ninguna consulta suya mira las columnas nuevas.
 */
return new class extends Migration
{
    private const TABLA = 'verificacion_documento_registro';

    public function up(): void
    {
        // ID_EQUIPO y TIPO dejan de ser obligatorios: un PDF recien soltado puede no tener
        // todavia ni ficha (no se reconocio de quien es) ni tipo (no se pudo leer), y esas dos
        // filas tambien tienen que verse en la tabla. Se hace con SQL a pelo porque cambiar
        // una columna con ->change() exige doctrine/dbal y ademas perderia el indice.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE ' . self::TABLA . ' MODIFY ID_EQUIPO BIGINT UNSIGNED NULL'
        );
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE ' . self::TABLA . ' MODIFY TIPO VARCHAR(20) NULL'
        );

        Schema::table(self::TABLA, function (Blueprint $t) {
            if (!Schema::hasColumn(self::TABLA, 'ID_AUXILIAR')) {
                $t->unsignedBigInteger('ID_AUXILIAR')->nullable()->after('ID_EQUIPO')->index();
            }
            if (!Schema::hasColumn(self::TABLA, 'ORIGEN')) {
                $t->string('ORIGEN', 20)->default('noche')->after('ESTADO')->index();
            }
            if (!Schema::hasColumn(self::TABLA, 'ARCHIVO')) {
                $t->string('ARCHIVO', 255)->nullable()->after('DRIVE_ID');
            }
            if (!Schema::hasColumn(self::TABLA, 'PROPUESTA')) {
                // longText como LEIDO (el cast 'array' del modelo hace el resto): en MariaDB
                // el tipo json añade una restriccion de validez que la otra columna no tiene,
                // y las dos guardan lo mismo.
                $t->longText('PROPUESTA')->nullable()->after('LEIDO');
            }
        });
    }

    /**
     * Se quitan las columnas nuevas y ID_EQUIPO vuelve a ser obligatorio. Antes hay que
     * retirar las filas que no tienen ficha, que solo pueden ser de la carga masiva: si se
     * quedaran, el ALTER fallaria (no puede poner NOT NULL sobre valores vacios).
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table(self::TABLA)
            ->where(fn ($q) => $q->whereNull('ID_EQUIPO')->orWhereNull('TIPO'))->delete();

        Schema::table(self::TABLA, function (Blueprint $t) {
            foreach (['ID_AUXILIAR', 'ORIGEN', 'ARCHIVO', 'PROPUESTA'] as $col) {
                if (Schema::hasColumn(self::TABLA, $col)) $t->dropColumn($col);
            }
        });

        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE ' . self::TABLA . ' MODIFY ID_EQUIPO BIGINT UNSIGNED NOT NULL'
        );
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE ' . self::TABLA . ' MODIFY TIPO VARCHAR(20) NOT NULL'
        );
    }
};
