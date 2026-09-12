{{-- Línea bajo el proyecto cuando el saldo que movió la fila es de OTRA bolsa del almacén
     (ver MovimientoInventario::prestamosPorMovimiento). La comparten kardex_rows y
     kardex_rows_mini. Recibe $m (movimiento), $bolsa (id de la bolsa) y $nombreBolsa.

     En una salida es un préstamo ("tomado de"). En una devolución es lo contrario: se le
     repone el material a la bolsa que lo había prestado ("devuelto a"). --}}
@php $devuelve = $m->TIPO === \App\Models\MovimientoInventario::TIPO_DEVOLUCION; @endphp
<div class="mv-tomado-de" title="{{ $devuelve
        ? 'Ese material se le había tomado prestado a esta bolsa del mismo almacén: la devolución se lo repone'
        : 'Ese proyecto no tenía saldo suficiente: la diferencia se tomó de esta otra bolsa del mismo almacén' }}">
    <i class="material-icons">subdirectory_arrow_right</i>
    <span>{{ $devuelve ? 'devuelto a' : 'tomado de' }} <strong>{{ \App\Services\InventarioService::rotuloBolsaPrestada(
        $bolsa, $nombreBolsa, \App\Services\InventarioService::ROTULO_BOLSA_COMUN_EN_FRASE) }}</strong></span>
</div>
