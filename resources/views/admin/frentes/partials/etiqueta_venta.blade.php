{{-- Etiqueta VENDIDO / EN TRÁMITE DE VENTA de un frente de venta: junto a su nombre en el filtro
     de frentes (FrenteTrabajoController::cambiarTipo la devuelve armada para el modal "Tipo de
     frente") y montada sobre la foto de cada equipo en la tabla. Nada si el frente no es de
     venta. Recibe $frente. --}}
@if($venta = $frente?->avisoVenta())<span class="etiqueta-venta etiqueta-venta--{{ $venta['clase'] }}">{{ $venta['etiqueta'] }}</span>@endif
