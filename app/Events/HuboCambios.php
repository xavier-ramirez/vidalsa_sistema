<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * «Hubo cambios»: lo manda AvisarCambios en cuanto el registro de cambios crece, y cada
 * navegador conectado pone al día su copia local en ese momento (tiempo-real.js). No lleva
 * datos (a lo sumo la marca `acceso`): cada uno los pide con su sesión y sus permisos por /sync.
 *
 * Con `latido` es solo «el vigía sigue vivo»: no hace preguntar nada. Con `acceso`, a alguien
 * le cambiaron permisos, rol, nivel o frentes: cada pestaña mira ya si fue a ella
 * (navegacion.js → revisarAcceso), sin esperar su minuto. Con `consumibles`, cambió la tabla de
 * consumibles (no pasa por la copia local): su lista se repinta (consumibles_index.js).
 */
class HuboCambios implements ShouldBroadcastNow
{
    use InteractsWithBroadcasting;

    public function __construct(
        public readonly bool $latido = false,
        public readonly bool $acceso = false,
        public readonly bool $consumibles = false,
    )
    {
        $this->broadcastVia('reverb');   // ver config/broadcasting.php
    }

    public function broadcastOn(): Channel
    {
        return new Channel(config('broadcasting.cliente.canal'));
    }

    public function broadcastAs(): string
    {
        return $this->latido ? 'latido' : 'cambios';
    }

    public function broadcastWith(): array
    {
        return array_filter(['acceso' => $this->acceso, 'consumibles' => $this->consumibles]);
    }
}
