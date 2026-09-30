<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Documentos de EMBARQUE (Bill of Lading): con que barco llego cada equipo.
     *
     * Un BL es de la EMPRESA, no de un equipo: un solo PDF (el HCLKGT03, por ejemplo) ampara
     * 84 unidades, cada una nombrada por su VIN en el anexo. Por eso no es una columna mas de
     * `documentacion` (seria el mismo enlace copiado 84 veces) sino un registro propio con sus
     * datos —numero, buque, puertos, fecha— y la lista de los equipos que ampara.
     *
     * Se carga desde la carga masiva (tipo "Documento de embarque") y se ve en la ficha del
     * equipo. Un equipo llega en UN embarque: moverlo a otro es reemplazar (con permiso).
     */
    public function up(): void
    {
        if (!Schema::hasTable('embarques')) {
            Schema::create('embarques', function (Blueprint $table) {
                $table->bigIncrements('ID_EMBARQUE');
                // "B/L NO. HCLKGT03". Unico: el mismo BL soltado dos veces es el MISMO embarque.
                // Nulo solo si no se pudo leer (entonces el embarque se reconoce por su PDF).
                $table->string('NRO_BL', 40)->nullable()->unique();
                $table->string('BUQUE', 120)->nullable();
                $table->string('PUERTO_CARGA', 120)->nullable();
                $table->string('PUERTO_DESCARGA', 120)->nullable();
                // "Place and date of issue".
                $table->date('FECHA_EMBARQUE')->nullable();
                // Ruta servida por la app: /storage/google/<id>?v=<ts>, igual que documentacion.LINK_*.
                $table->string('LINK', 500);
                $table->string('ARCHIVO', 255)->nullable();
                // Cuantas unidades nombra el BL (registradas o no): con los enlazados se ve si faltan.
                $table->unsignedInteger('UNIDADES')->nullable();
                $table->unsignedBigInteger('SUBIDO_POR')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('embarque_equipo')) {
            Schema::create('embarque_equipo', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->unsignedBigInteger('ID_EMBARQUE');
                // UNICO: un equipo llega en un solo embarque.
                $table->unsignedBigInteger('ID_EQUIPO')->unique();
                // El VIN tal como lo imprime el BL (puede diferir en guiones del de la ficha).
                $table->string('VIN', 40)->nullable();
                $table->unsignedBigInteger('ASOCIADO_POR')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('ID_EMBARQUE', 'idx_embarque_equipo_embarque');
                $table->foreign('ID_EMBARQUE')->references('ID_EMBARQUE')->on('embarques')
                      ->onDelete('cascade')->onUpdate('cascade');
                $table->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos')
                      ->onDelete('cascade')->onUpdate('cascade');
            });
        }
    }

    /** Tablas nuevas: revertir no destruye nada que existiera antes (los PDF siguen en Drive). */
    public function down(): void
    {
        Schema::dropIfExists('embarque_equipo');
        Schema::dropIfExists('embarques');
    }
};
