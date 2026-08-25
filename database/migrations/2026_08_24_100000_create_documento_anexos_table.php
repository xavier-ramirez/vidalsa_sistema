<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Correcciones ANEXAS a un documento del equipo.
     *
     * No es un historial de versiones: el anexo y el documento principal estan los
     * DOS vigentes a la vez. El caso real es una poliza con un error de ortografia
     * en el PDF y su correccion: las dos hacen falta y las dos se consultan.
     *
     * El documento principal sigue viviendo donde siempre —documentacion.LINK_*—
     * y la sustitucion sigue funcionando igual que hoy (uploadDoc lo pisa). Esta
     * tabla solo AÑADE; nada de aqui borra ni modifica la fila de documentacion.
     */
    public function up(): void
    {
        if (Schema::hasTable('documento_anexos')) return;

        Schema::create('documento_anexos', function (Blueprint $table) {
            $table->bigIncrements('ID_ANEXO');
            $table->unsignedBigInteger('ID_EQUIPO');

            // poliza | propiedad | rotc | racda | adicional | adicional_2.
            // Mismos nombres que el doc_type de uploadDoc(), para no traducir.
            $table->string('TIPO_DOC', 20);

            // Ruta servida por la app: /storage/google/<id>?v=<ts>, igual que las
            // columnas LINK_* del principal.
            $table->string('LINK', 500);

            // El id de Drive suelto, ademas del LINK. En el principal hay que
            // recortarlo del LINK a mano cada vez (parse_url + str_replace); aqui
            // se guarda ya limpio para no repetir ese destripe.
            $table->string('DRIVE_FILE_ID', 200)->nullable();

            // Nombre de la pestaña en el visor. NO lo escribe el usuario: anexarDoc lo
            // numera solo ("Correccion 1", "Correccion 2"...) contando los anexos que ya
            // tiene ese equipo+tipo; el front no manda ningun campo de etiqueta. Se pidio
            // a mano al principio —de ahi que en datos viejos haya cosas como "Endoso 02"—
            // pero estorbaba: anexar es un gesto de un clic.
            $table->string('ETIQUETA', 120)->nullable();

            // A que documento principal corrige. Se guarda el id de Drive del
            // principal en el momento de anexar: si mañana se sustituye el
            // principal (una renovacion, por ejemplo) el anexo NO se borra, pero
            // se puede señalar que pertenece al documento anterior.
            $table->string('PRINCIPAL_DRIVE_ID', 200)->nullable();

            $table->unsignedBigInteger('SUBIDO_POR')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // El listado del modal siempre pide (equipo + tipo) ordenado por fecha.
            $table->index(['ID_EQUIPO', 'TIPO_DOC'], 'idx_anexo_equipo_tipo');

            $table->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos')
                  ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * down() SI borra la tabla: a diferencia de las columnas de tracking sobre
     * `documentacion`, esta tabla es nueva y no existia antes en produccion, asi
     * que revertir no destruye nada preexistente. Aun asi, revertirla despues de
     * haber anexado correcciones deja huerfanos los PDF en Drive (no se tocan a
     * proposito: perder el registro es recuperable, perder el archivo no).
     */
    public function down(): void
    {
        Schema::dropIfExists('documento_anexos');
    }
};
