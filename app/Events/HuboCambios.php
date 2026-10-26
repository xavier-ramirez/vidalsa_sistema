<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * «Hubo cambios»: lo manda AvisarCambios en cuanto el registro de cambios crece, y cada
 * navegador conectado pone al día su copia local en ese momento (tiempo-real.js). No lleva
 * datos: cada uno los pide con su sesión y sus permisos por /sync.
 *
 * Con `latido` es solo «el vigía sigue vivo»: no hace preguntar nada.
 */
class HuboCambios implements ShouldBroadcastNow
{
    use InteractsWithBroadcasting;

    public function __construct(public readonly bool $latido = false)
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
        return [];
    }
}
