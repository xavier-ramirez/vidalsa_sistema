<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de cada dispositivo (public/js/offline/bandeja.js) sube lo que se hizo sin
 * internet a los MISMOS endpoints de siempre:
 *
 *   · operaciones_cliente: cada operación lleva su clave (App\Http\Middleware\OperacionUnica).
 *     Si se repite —se cortó la red con la respuesta en camino— se devuelve la respuesta
 *     guardada en vez de aplicarla otra vez. ESTADO_HTTP NULL = se está aplicando.
 *   · notas_reservadas: números de Nota de Entrega apartados para un dispositivo, para que
 *     una salida hecha sin internet salga ya con su número (App\Support\NotasReservadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operaciones_cliente')) {
            Schema::create('operaciones_cliente', function (Blueprint $table) {
                $table->string('CLAVE', 64);
                $table->unsignedBigInteger('ID_USUARIO');
                $table->string('RUTA', 255);
                $table->unsignedSmallInteger('ESTADO_HTTP')->nullable();
                $table->longText('RESPUESTA')->nullable();
                $table->timestamps();
                $table->primary(['CLAVE', 'ID_USUARIO']);
                $table->index('created_at');   // la purga
            });
        }

        if (! Schema::hasTable('notas_reservadas')) {
            Schema::create('notas_reservadas', function (Blueprint $table) {
                $table->string('NUMERO_NOTA', 20)->primary();
                $table->unsignedBigInteger('ID_USUARIO');
                $table->string('DISPOSITIVO', 64);
                $table->timestamp('USADA_AT')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['ID_USUARIO', 'DISPOSITIVO', 'USADA_AT']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_reservadas');
        Schema::dropIfExists('operaciones_cliente');
    }
};
