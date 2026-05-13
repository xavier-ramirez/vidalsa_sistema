<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Líneas de detalle de un traspaso: un producto + cantidad enviada y recibida.
     *
     * Una línea pasa por estos estados (independiente del estado del traspaso padre):
     *  - PENDIENTE : creada en el borrador; se quedará así hasta recepción.
     *  - OK        : recibida exactamente igual a lo enviado.
     *  - FALTANTE  : recibido < enviado (faltó mercancía en el camión).
     *  - SOBRANTE  : recibido > enviado (raro pero registrable; se crea AJUSTE en destino).
     *  - DANADO    : recibido pero con avería — el destino acepta pero marca el incidente.
     *
     * Los enlaces a `movimientos_inventario` (ID_MOVIMIENTO_SALIDA / _ENTRADA) se llenan
     * cuando el traspaso se ENVÍA y se RECIBE, respectivamente. Permiten que el kardex
     * y el detalle del traspaso se vean entre sí (auditoría completa).
     */
    public function up(): void
    {
        Schema::create('traspaso_lineas', function (Blueprint $table) {
            $table->id('ID_LINEA');

            $table->unsignedBigInteger('ID_TRASPASO');
            $table->foreign('ID_TRASPASO')->references('ID_TRASPASO')->on('traspasos')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_PRODUCTO');
            $table->foreign('ID_PRODUCTO')->references('ID_PRODUCTO')->on('productos_inventario')->restrictOnDelete();

            $table->decimal('CANTIDAD_ENVIADA', 16, 3)
                  ->comment('Cantidad que el origen despachó (queda fija al ENVIAR).');

            $table->decimal('CANTIDAD_RECIBIDA', 16, 3)->nullable()
                  ->comment('Cantidad que el destino contó al recibir. NULL hasta que se confirme.');

            $table->enum('ESTADO_LINEA', ['PENDIENTE', 'OK', 'FALTANTE', 'SOBRANTE', 'DANADO'])
                  ->default('PENDIENTE');

            $table->text('NOTAS_LINEA')->nullable()
                  ->comment('Observaciones del receptor (ej: "1 saco roto", "humedad").');

            // Enlaces a los movimientos físicos (cuando aplique).
            $table->unsignedBigInteger('ID_MOVIMIENTO_SALIDA')->nullable()
                  ->comment('Kardex de la salida del origen — se llena al ENVIAR.');
            $table->foreign('ID_MOVIMIENTO_SALIDA')->references('ID_MOVIMIENTO')->on('movimientos_inventario')->nullOnDelete();

            $table->unsignedBigInteger('ID_MOVIMIENTO_ENTRADA')->nullable()
                  ->comment('Kardex de la entrada al destino — se llena al RECIBIR.');
            $table->foreign('ID_MOVIMIENTO_ENTRADA')->references('ID_MOVIMIENTO')->on('movimientos_inventario')->nullOnDelete();

            $table->timestamps();

            $table->index('ID_TRASPASO',  'idx_tl_traspaso');
            $table->index('ID_PRODUCTO',  'idx_tl_producto');
            $table->index('ESTADO_LINEA', 'idx_tl_estado');
            // El mismo producto puede repetirse en una orden — no ponemos UNIQUE.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traspaso_lineas');
    }
};
