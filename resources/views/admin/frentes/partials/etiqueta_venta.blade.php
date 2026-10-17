{{-- Etiqueta VENDIDO / EN TRÁMITE DE VENTA junto al nombre de un frente de venta (filtro de
     frentes y columna Frente de Equipos; FrenteTrabajoController::cambiarTipo la devuelve armada
     para el modal "Tipo de frente"). Nada si el frente no es de venta. Recibe $frente. --}}
@if($venta = $frente?->avisoVenta())<span class="etiqueta-venta etiqueta-venta--{{ $venta['clase'] }}">{{ $venta['etiqueta'] }}</span>@endif
