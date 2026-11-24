<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carga de los Excel de gasoil de cada proyecto (Consumibles → Cargar desde Excel).
 *
 * consumibles:
 *  - HUELLA_CI / HUELLA_EQ / HUELLA_DESC: identidad del despacho para no cargarlo dos veces.
 *    Los Excel son acumulados (el de septiembre repite abril–agosto) y entre versiones
 *    corrigen la cédula, la placa o ambas; por eso hay tres huellas (fecha|litros con la
 *    cédula, con la placa/serial y con la descripción del equipo, cada una con su nº de
 *    aparición en la hoja). Cómo se combinan: App\Services\Consumibles\ImportadorCombustible.
 *    CI y EQ son únicas: ni dos pestañas a la vez pueden duplicar.
 *  - ID_AUXILIAR: el consumo de una máquina de soldar, planta, compresor… (equipos_auxiliares).
 *  - ID_IMPORTACION: de qué carga salió; permite deshacerla entera.
 *  - ORIGEN: archivo · hoja · fila del Excel. ACTIVIDAD: el texto de proyecto/actividad tal cual.
 *
 * consumibles_alias: lo que el usuario enseña una vez («PATROL CAT 140H» = tal equipo,
 * «SERVICIO GENERAL» = tal frente, «LAVADO DE EQUIPOS» = no es equipo) y vale para siempre.
 *
 * consumibles_importaciones: el borrador (filas leídas) mientras se revisa, y el registro de
 * cada carga aplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cada paso se comprueba POR SEPARADO (columna, índice, llave): en MySQL un cambio de
        // estructura no se deshace si la migración se corta a medias, y al repetirla no debe
        // saltarse un UNIQUE o una llave que quedó sin crear (los UNIQUE de las huellas son los
        // que impiden cargar dos veces el mismo despacho).
        $columnas = [
            'ID_AUXILIAR'    => fn (Blueprint $t) => $t->unsignedBigInteger('ID_AUXILIAR')->nullable()->after('ID_EQUIPO'),
            'ID_IMPORTACION' => fn (Blueprint $t) => $t->unsignedBigInteger('ID_IMPORTACION')->nullable()->after('ID_AUXILIAR'),
            'HUELLA_CI'      => fn (Blueprint $t) => $t->string('HUELLA_CI', 120)->nullable()->after('ID_IMPORTACION'),
            'HUELLA_EQ'      => fn (Blueprint $t) => $t->string('HUELLA_EQ', 160)->nullable()->after('HUELLA_CI'),
            'HUELLA_DESC'    => fn (Blueprint $t) => $t->string('HUELLA_DESC', 160)->nullable()->after('HUELLA_EQ'),
            'ORIGEN'         => fn (Blueprint $t) => $t->string('ORIGEN', 255)->nullable()->after('RAW_ORIGEN'),
            'ACTIVIDAD'      => fn (Blueprint $t) => $t->string('ACTIVIDAD', 255)->nullable()->after('ORIGEN'),
        ];
        foreach ($columnas as $col => $crear) {
            if (!Schema::hasColumn('consumibles', $col)) {
                Schema::table('consumibles', fn (Blueprint $t) => $crear($t));
            }
        }

        $indices = [
            'idx_con_importacion' => fn (Blueprint $t) => $t->index('ID_IMPORTACION', 'idx_con_importacion'),
            'uk_con_huella_ci'    => fn (Blueprint $t) => $t->unique('HUELLA_CI', 'uk_con_huella_ci'),
            'uk_con_huella_eq'    => fn (Blueprint $t) => $t->unique('HUELLA_EQ', 'uk_con_huella_eq'),
            'idx_con_huella_desc' => fn (Blueprint $t) => $t->index('HUELLA_DESC', 'idx_con_huella_desc'),
        ];
        foreach ($indices as $nombre => $crear) {
            if (!Schema::hasIndex('consumibles', $nombre)) {
                Schema::table('consumibles', fn (Blueprint $t) => $crear($t));
            }
        }

        $this->llave('consumibles', 'consumibles_id_auxiliar_foreign', fn (Blueprint $t) => $t->foreign('ID_AUXILIAR', 'consumibles_id_auxiliar_foreign')
            ->references('ID_AUXILIAR')->on('equipos_auxiliares')->nullOnDelete());

        if (!Schema::hasTable('consumibles_alias')) {
            Schema::create('consumibles_alias', function (Blueprint $table) {
                $table->bigIncrements('ID_ALIAS');
                $table->string('TIPO', 10);              // EQUIPO | FRENTE
                $table->string('TEXTO', 191);            // texto normalizado del Excel
                $table->unsignedBigInteger('ID_EQUIPO')->nullable();
                $table->unsignedBigInteger('ID_AUXILIAR')->nullable();
                $table->unsignedBigInteger('ID_FRENTE')->nullable();
                $table->boolean('NO_ES_EQUIPO')->default(false);
                $table->unsignedBigInteger('ID_USUARIO')->nullable();
                $table->timestamps();

                $table->unique(['TIPO', 'TEXTO'], 'uk_con_alias_tipo_texto');
            });
        }
        // Las llaves aparte: Laravel las crea con ALTER después del CREATE, y si una falla la
        // tabla ya existe; así la siguiente vez se crean las que falten.
        $this->llave('consumibles_alias', 'consumibles_alias_id_equipo_foreign', fn (Blueprint $t) => $t->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos')->cascadeOnDelete());
        $this->llave('consumibles_alias', 'consumibles_alias_id_auxiliar_foreign', fn (Blueprint $t) => $t->foreign('ID_AUXILIAR')->references('ID_AUXILIAR')->on('equipos_auxiliares')->cascadeOnDelete());
        $this->llave('consumibles_alias', 'consumibles_alias_id_frente_foreign', fn (Blueprint $t) => $t->foreign('ID_FRENTE')->references('ID_FRENTE')->on('frentes_trabajo')->cascadeOnDelete());

        if (!Schema::hasTable('consumibles_importaciones')) {
            Schema::create('consumibles_importaciones', function (Blueprint $table) {
                $table->bigIncrements('ID_IMPORTACION');
                $table->string('ESTADO', 12)->default('BORRADOR');   // BORRADOR | APLICADA | DESHECHA
                $table->text('ARCHIVOS');                            // JSON: archivos y hojas leídas
                $table->longText('FILAS')->nullable();               // JSON: filas del borrador; se vacía al aplicar
                $table->unsignedInteger('INSERTADOS')->default(0);
                $table->decimal('LITROS', 12, 2)->default(0);
                $table->unsignedBigInteger('ID_USUARIO')->nullable();
                $table->timestamp('APLICADA_EN')->nullable();
                $table->timestamps();
                $table->index('ESTADO', 'idx_con_imp_estado');
            });
        }
    }

    /** Crea la llave foránea $nombre de $tabla si todavía no existe. */
    private function llave(string $tabla, string $nombre, \Closure $crear): void
    {
        $existe = collect(Schema::getForeignKeys($tabla))->contains(fn ($fk) => strcasecmp($fk['name'] ?? '', $nombre) === 0);
        if (!$existe) {
            Schema::table($tabla, fn (Blueprint $t) => $crear($t));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consumibles_importaciones');
        Schema::dropIfExists('consumibles_alias');

        Schema::table('consumibles', function (Blueprint $table) {
            if (Schema::hasColumn('consumibles', 'ID_AUXILIAR')) {
                $table->dropForeign('consumibles_id_auxiliar_foreign');
            }
            foreach (['uk_con_huella_ci', 'uk_con_huella_eq'] as $idx) {
                if (Schema::hasIndex('consumibles', $idx)) {
                    $table->dropUnique($idx);
                }
            }
            foreach (['idx_con_importacion', 'idx_con_huella_desc'] as $idx) {
                if (Schema::hasIndex('consumibles', $idx)) {
                    $table->dropIndex($idx);
                }
            }
            $cols = array_filter(
                ['ID_AUXILIAR', 'ID_IMPORTACION', 'HUELLA_CI', 'HUELLA_EQ', 'HUELLA_DESC', 'ORIGEN', 'ACTIVIDAD'],
                fn ($c) => Schema::hasColumn('consumibles', $c)
            );
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
