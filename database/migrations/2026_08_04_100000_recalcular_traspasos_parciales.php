<?php

use App\Models\Traspaso;
use Illuminate\Database\Migrations\Migration;

/**
 * Alinea los traspasos ya cerrados con la NUEVA definición de "Confirmada parcial".
 *
 * Antes: RECIBIDO_PARCIAL = se confirmó y alguna cantidad no cuadró (faltante/sobrante/dañado).
 * Ahora: RECIBIDO_PARCIAL = quedaron productos SIN confirmar (líneas en PENDIENTE).
 *
 * Con la regla vieja, una nota revisada y aceptada entera se quedaba atascada en la bandeja
 * de Recepción solo por tener un faltante. Esta migración pasa a RECIBIDO las notas parciales
 * que NO tienen ninguna línea pendiente — es decir, las que sí se confirmaron completas. Las
 * diferencias de cantidad no se tocan: siguen en el ESTADO_LINEA y se consultan con el filtro
 * "Con discrepancias".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Notas parciales SIN líneas pendientes = se confirmaron completas → RECIBIDO.
        // "Pendiente" se pregunta con el MISMO scope que usa el resto del código
        // (TraspasoLinea::scopePendiente), no con un predicado reescrito aquí.
        Traspaso::where('ESTADO', Traspaso::ESTADO_RECIBIDO_PARCIAL)
            ->whereDoesntHave('lineas', fn ($q) => $q->pendiente())
            ->toBase()
            ->update(['ESTADO' => Traspaso::ESTADO_RECIBIDO]);
    }

    /**
     * No hay vuelta atrás fiable: una vez en RECIBIDO no queda rastro de si la nota llegó ahí
     * por esta migración o por una confirmación normal. Revertir a ciegas marcaría como
     * parciales notas que nunca lo fueron, así que se deja como no-op deliberado.
     */
    public function down(): void
    {
    }
};
