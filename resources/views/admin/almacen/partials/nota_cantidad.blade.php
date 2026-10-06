{{--
    Celda CANTIDAD de una línea de la Nota de Entrega (PDF), en el vertical y en el
    horizontal. Normalmente es el número y nada más.

    En la nota ORIGINAL de una nota corregida (?version=original, CorreccionNotaService) la
    línea corregida trae CANTIDAD = lo que decía antes y CANTIDAD_CORREGIDA = lo que dice hoy:
    sale el número viejo tachado y el nuevo al lado, los dos en rojo.

      - $m, $fmt, $fi: los de la fila de la tabla de ítems que la incluye.
--}}
@if(isset($m->CANTIDAD_CORREGIDA))<font face="helvetica" size="{{ $fi }}" color="#b91c1c"><del>{{ $fmt($m->CANTIDAD) }}</del> <b>{{ $fmt($m->CANTIDAD_CORREGIDA) }}</b></font>@else<font face="helvetica" size="{{ $fi }}">{{ $fmt($m->CANTIDAD) }}</font>@endif
