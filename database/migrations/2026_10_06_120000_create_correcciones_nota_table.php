<?php

use App\Models\Usuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección de cantidades de una Nota de Entrega ya emitida (salieron 100 electrodos y se
 * tecleó 180). La corrección cambia la salida en el kardex —ver CorreccionNotaService—, y
 * esta tabla es su RASTRO: qué producto, cuánto decía antes, cuánto dice ahora, quién y
 * por qué. De aquí sale también la nota ORIGINAL con la corrección en rojo.
 *
 * Y la clave que la permite, 'almacen.nota.corregir', EXCLUSIVA como las demás del almacén
 * (Usuario::PERMISOS_EXPLICITOS). Se le da a quien ya tiene 'almacen.nota.eliminar': quien
 * puede anular una nota entera puede corregir una línea. Al resto se le marca a mano.
 */
return new class extends Migration
{
    private const CLAVE   = 'almacen.nota.corregir';
    private const BASE    = 'almacen.nota.eliminar';
    /** A quién se la dio ESTA migración (mismo criterio que la de docs.carga.masiva). */
    private const MEMORIA = 'migracion_nota_corregir_permiso_dado';

    public function up(): void
    {
        if (!Schema::hasTable('correcciones_nota')) {
            Schema::create('correcciones_nota', function (Blueprint $table) {
                $table->bigIncrements('ID_CORRECCION');
                // Por número y producto, no por fila del kardex: la línea impresa de un
                // producto puede ser varias filas (una por bolsa) y una corrección a la baja
                // puede borrar alguna.
                $table->string('NUMERO_NOTA', 30);
                $table->unsignedBigInteger('ID_PRODUCTO');
                $table->decimal('CANTIDAD_ANTES', 14, 3);
                $table->decimal('CANTIDAD_DESPUES', 14, 3);
                $table->string('MOTIVO', 150)->nullable();
                $table->unsignedBigInteger('ID_USUARIO')->nullable();
                $table->timestamps();

                $table->index(['NUMERO_NOTA', 'ID_PRODUCTO'], 'idx_correcciones_nota_numero');
            });
        }

        $dadas = [];
        foreach (Usuario::whereNotNull('PERMISOS')->get() as $u) {
            $permisos = (array) $u->PERMISOS;
            if (!in_array(self::BASE, $permisos, true) || in_array(self::CLAVE, $permisos, true)) continue;
            $u->PERMISOS = array_values(array_merge($permisos, [self::CLAVE]));
            $u->save();
            $dadas[] = $u->getKey();
        }
        Cache::forever(self::MEMORIA, $dadas);
    }

    public function down(): void
    {
        foreach ((array) Cache::get(self::MEMORIA, []) as $id) {
            $u = Usuario::find($id);
            if (!$u || !in_array(self::CLAVE, (array) $u->PERMISOS, true)) continue;
            $u->PERMISOS = array_values(array_diff((array) $u->PERMISOS, [self::CLAVE]));
            $u->save();
        }
        Cache::forget(self::MEMORIA);

        Schema::dropIfExists('correcciones_nota');
    }
};
