<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Pedido de Traspaso" — la cabecera del envío entre dos almacenes.
     *
     * Reemplaza el TRASPASO atómico (origen y destino en la misma transacción)
     * por un flujo en 2 pasos como manejan los grandes retailers:
     *
     *   BORRADOR  →  el ORIGEN arma la lista (no toca stock).
     *   ENVIADO   →  el ORIGEN despacha; sale stock del origen y queda "en tránsito".
     *   RECIBIDO  →  el DESTINO confirma; entra stock al destino (cantidades reales).
     *   RECIBIDO_PARCIAL → llegó menos de lo que se envió; el faltante queda auditado.
     *   CANCELADO →  se anula. Si ya estaba ENVIADO, se reversa el stock al origen.
     *
     * Las líneas (productos + cantidades enviadas/recibidas) viven en `traspaso_lineas`.
     * Los movimientos físicos siguen registrándose en `movimientos_inventario`; cada
     * uno apunta a su traspaso vía `movimientos_inventario.ID_TRASPASO` (otra migration).
     */
    public function up(): void
    {
        Schema::create('traspasos', function (Blueprint $table) {
            $table->id('ID_TRASPASO');

            $table->string('NUMERO', 20)->unique()
                  ->comment('Folio legible del traspaso (ej: TR-2026-0001). Se asigna al crear.');

            $table->unsignedBigInteger('ID_ALMACEN_ORIGEN');
            $table->foreign('ID_ALMACEN_ORIGEN')->references('ID_ALMACEN')->on('almacenes')->restrictOnDelete();

            $table->unsignedBigInteger('ID_ALMACEN_DESTINO');
            $table->foreign('ID_ALMACEN_DESTINO')->references('ID_ALMACEN')->on('almacenes')->restrictOnDelete();

            $table->unsignedBigInteger('ID_FRENTE_DESTINO')->nullable()
                  ->comment('Cuando el almacén destino surte a varios frentes: cuál de ellos recibe.');
            $table->foreign('ID_FRENTE_DESTINO')->references('ID_FRENTE')->on('frentes_trabajo')->nullOnDelete();

            $table->enum('ESTADO', [
                'BORRADOR',
                'ENVIADO',
                'RECIBIDO',
                'RECIBIDO_PARCIAL',
                'CANCELADO',
            ])->default('BORRADOR')
              ->comment('Estado actual del pedido (state machine).');

            $table->dateTime('FECHA_ENVIO')->nullable()
                  ->comment('Cuándo el origen marcó el traspaso como ENVIADO (sale del almacén).');
            $table->dateTime('FECHA_RECEPCION')->nullable()
                  ->comment('Cuándo el destino confirmó la recepción.');

            $table->unsignedBigInteger('ID_USUARIO_CREO');
            $table->foreign('ID_USUARIO_CREO')->references('ID_USUARIO')->on('usuarios')->restrictOnDelete();

            $table->unsignedBigInteger('ID_USUARIO_ENVIO')->nullable()
                  ->comment('Usuario que firmó el envío (puede ser distinto al que creó el borrador).');
            $table->foreign('ID_USUARIO_ENVIO')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();

            $table->unsignedBigInteger('ID_USUARIO_RECEPCION')->nullable()
                  ->comment('Usuario del destino que confirmó la recepción.');
            $table->foreign('ID_USUARIO_RECEPCION')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();

            $table->string('REFERENCIA', 100)->nullable()
                  ->comment('Nº de guía / orden de salida del documento físico.');
            $table->string('MOTIVO', 200)->nullable();
            $table->text('NOTAS')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('ESTADO',              'idx_tr_estado');
            $table->index('ID_ALMACEN_ORIGEN',   'idx_tr_origen');
            $table->index('ID_ALMACEN_DESTINO',  'idx_tr_destino');
            $table->index('FECHA_ENVIO',         'idx_tr_fecha_envio');
            // Acceso típico desde la bandeja del destino: "qué tengo por recibir aquí".
            $table->index(['ID_ALMACEN_DESTINO', 'ESTADO'], 'idx_tr_destino_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traspasos');
    }
};
