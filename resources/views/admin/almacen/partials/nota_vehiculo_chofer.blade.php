{{--
    Bloque "DATOS DEL VEHICULO / DATOS DEL CHOFER" de la Nota de Entrega.

    Lo comparten los DOS formatos (vertical y horizontal): antes estaba copiado en las dos
    vistas y cualquier retoque —una etiqueta, la razon social— habia que hacerlo dos veces.

    Vehiculo, placa, chofer y cedula salen de la nota ($datos['transporte_*'], ver
    MovimientoInventario::CAMPOS_TRANSPORTE): los pide el formulario de la salida y los sugiere
    la logistica del almacen. El que venga vacio queda en blanco para llenarlo a mano, como
    antes. La FIRMA siempre va a mano.

    UNA sola tabla de 4 columnas, no dos tablas anidadas: TCPDF dibuja el borde de una tabla
    hija DENTRO del td del wrapper y la caja queda corrida a la derecha respecto a la tabla de
    items de arriba (se veia "metida hacia adentro" por la izquierda). Con una sola tabla y
    widths explicitos en todos los <td>, el borde izquierdo cae EXACTO en la misma X. Los
    titulos van con colspan=2 y de paso la divisoria central queda como un borde continuo
    entre las dos mitades.

    Parametros (el que incluye decide los anchos porque las dos hojas miden distinto):
      $wLabel → ancho de las columnas de etiqueta, en %. Vertical 14, horizontal 12.
      $wValor → ancho de las columnas de valor,    en %. Vertical 36, horizontal 38.
    Deben sumar 50 entre los dos: ($wLabel + $wValor) * 2 = 100%.
      $centrarEmpresa → los valores (razón social y transporte) centrados en su celda. Lo
                        pide el vertical, donde los valores de las firmas de justo encima van
                        centrados; el horizontal alinea todos sus valores a la izquierda.
--}}
@php
    $wL = ($wLabel ?? 14) . '%';
    $wV = ($wValor ?? 36) . '%';
    // Celda de un dato del transporte: el valor, o un espacio si no vino.
    $dato = fn ($campo) => ($v = trim((string) ($datos[$campo] ?? ''))) !== ''
        ? '<font face="helvetica" size="8">' . e($v) . '</font>'
        : '&nbsp;';
@endphp
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    {{-- Gris #D9D9D9 y tamaño 8pt, unificados con la tabla de items de ambos formatos. --}}
    <tr bgcolor="#D9D9D9">
        <td width="50%" colspan="2" align="center"><font face="helvetica" size="8"><b>DATOS DEL VEHICULO</b></font></td>
        <td width="50%" colspan="2" align="center"><font face="helvetica" size="8"><b>DATOS DEL CHOFER</b></font></td>
    </tr>
    <tr>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>VEHICULO:</b></font></td>
        <td width="{{ $wV }}"@if(!empty($centrarEmpresa)) align="center"@endif>{!! $dato('transporte_vehiculo') !!}</td>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>NOMBRE:</b></font></td>
        <td width="{{ $wV }}"@if(!empty($centrarEmpresa)) align="center"@endif>{!! $dato('transporte_chofer') !!}</td>
    </tr>
    <tr>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>PLACA:</b></font></td>
        <td width="{{ $wV }}"@if(!empty($centrarEmpresa)) align="center"@endif>{!! $dato('transporte_placa') !!}</td>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>CEDULA:</b></font></td>
        <td width="{{ $wV }}"@if(!empty($centrarEmpresa)) align="center"@endif>{!! $dato('transporte_cedula') !!}</td>
    </tr>
    <tr>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>EMPRESA:</b></font></td>
        <td width="{{ $wV }}"@if(!empty($centrarEmpresa)) align="center"@endif><font face="helvetica" size="8">CONSTRUCTORA VIDALSA 27, C.A.</font></td>
        <td width="{{ $wL }}"><font face="helvetica" size="8"><b>FIRMA:</b></font></td>
        <td width="{{ $wV }}">&nbsp;</td>
    </tr>
</table>
