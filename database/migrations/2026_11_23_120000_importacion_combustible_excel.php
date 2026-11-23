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
        Schema::table('consumibles', function (Blueprint $table) {
            if (!Schema::hasColumn('consumibles', 'ID_AUXILIAR')) {
                $table->unsignedBigInteger('ID_AUXILIAR')->nullable()->after('ID_EQUIPO');
                $table->foreign('ID_AUXILIAR', 'consumibles_id_auxiliar_foreign')
                    ->references('ID_AUXILIAR')->on('equipos_auxiliares')->nullOnDelete();
            }
            if (!Schema::hasColumn('consumibles', 'ID_IMPORTACION')) {
                $table->unsignedBigInteger('ID_IMPORTACION')->nullable()->after('ID_AUXILIAR');
                $table->index('ID_IMPORTACION', 'idx_con_importacion');
            }
            if (!Schema::hasColumn('consumibles', 'HUELLA_CI')) {
                $table->string('HUELLA_CI', 120)->nullable()->after('ID_IMPORTACION');
                $table->unique('HUELLA_CI', 'uk_con_huella_ci');
            }
            if (!Schema::hasColumn('consumibles', 'HUELLA_EQ')) {
                $table->string('HUELLA_EQ', 160)->nullable()->after('HUELLA_CI');
                $table->unique('HUELLA_EQ', 'uk_con_huella_eq');
            }
            if (!Schema::hasColumn('consumibles', 'HUELLA_DESC')) {
                $table->string('HUELLA_DESC', 160)->nullable()->after('HUELLA_EQ');
                $table->index('HUELLA_DESC', 'idx_con_huella_desc');
            }
            if (!Schema::hasColumn('consumibles', 'ORIGEN')) {
                $table->string('ORIGEN', 255)->nullable()->after('RAW_ORIGEN');
            }
            if (!Schema::hasColumn('consumibles', 'ACTIVIDAD')) {
                $table->string('ACTIVIDAD', 255)->nullable()->after('ORIGEN');
            }
        });

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
                $table->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos')->cascadeOnDelete();
                $table->foreign('ID_AUXILIAR')->references('ID_AUXILIAR')->on('equipos_auxiliares')->cascadeOnDelete();
                $table->foreign('ID_FRENTE')->references('ID_FRENTE')->on('frentes_trabajo')->cascadeOnDelete();
            });
        }

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
