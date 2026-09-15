<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística de cada almacén: los choferes (nombre + cédula) y los vehículos (descripción +
 * placa) con los que despacha. La Nota de Entrega los sugiere en su bloque "Datos del
 * vehículo / Datos del chofer" (ver LogisticaAlmacenService), que antes salía en blanco y se
 * llenaba a mano.
 *
 * La lista la cuida el super.admin en "Editar almacén" y además aprende sola: cada salida
 * con chofer o vehículo nuevo lo agrega, así un almacén arma la suya con el uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacen_logistica', function (Blueprint $table) {
            $table->id('ID_LOGISTICA');
            $table->unsignedBigInteger('ID_ALMACEN');
            $table->foreign('ID_ALMACEN')->references('ID_ALMACEN')->on('almacenes')->cascadeOnDelete();
            // CHOFER | VEHICULO (AlmacenLogistica::TIPO_*).
            $table->string('TIPO', 10);
            // Chofer: nombre y apellido. Vehículo: tipo, marca y modelo ("CAMIONETA TOYOTA HILUX").
            $table->string('NOMBRE', 150);
            // Chofer: cédula. Vehículo: placa. Tal como se imprime en la nota.
            $table->string('DOCUMENTO', 30);
            // El documento sin puntos, guiones ni prefijo (AlmacenLogistica::clave): "V-17.902.185"
            // y "17902185" son la misma persona, y no se puede repetir en el almacén.
            $table->string('CLAVE', 30);
            // Última nota que lo usó: las sugerencias salen de la más reciente a la más vieja.
            $table->timestamp('ULTIMO_USO')->nullable();
            $table->timestamps();

            $table->unique(['ID_ALMACEN', 'TIPO', 'CLAVE'], 'uq_alm_logistica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_logistica');
    }
};
