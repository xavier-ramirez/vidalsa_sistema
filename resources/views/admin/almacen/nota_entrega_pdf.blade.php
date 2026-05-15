{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — formato VID-FO-GEN-019.

    Construido con atributos HTML clásicos (border, width, align, valign,
    cellpadding, cellspacing) en vez de CSS — TCPDF tiene soporte completo de
    esos atributos y limitado de CSS, así que el render es más predecible.

    El grosor de las líneas (border=1) lo controla SetLineWidth(0.15) que se
    setea en el controller antes de renderizar — el mismo valor que el Header()
    usa, así las cajas del cuerpo quedan con el mismo grosor que el cabezote.

    Variables disponibles:
      - $datos: ['numero_nota','proyecto','contrato','fecha','rq','solicitante',
                 'departamento','almacen','entregado_por','motivo']
      - $movs:  Collection<MovimientoInventario>  con relaciones {producto}
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $minFilas = 25;
@endphp

{{-- ── N° de Nota (NE-YYYY-NNNN) ── --}}
@if(!empty($datos['numero_nota']))
<table border="0" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td align="right"><font size="9"><b>N° de Nota:</b> <font color="#0067b1">{{ $datos['numero_nota'] }}</font></font></td>
    </tr>
</table>
@endif

{{-- ── Bloque de datos del proyecto: una tabla con 4 filas ──
     fila 1: PROYECTO  (en itálica como el formato oficial)
     fila 2: CONTRATO Nº
     fila 3: FECHA DE ENTREGA · RQ N° · Solicitante
     fila 4: DEPARTAMENTO
     Grid de 6 columnas: 20% | 20% | 10% | 15% | 13% | 22% --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td width="20%"><font size="9"><b><i>PROYECTO:</i></b></font></td>
        <td colspan="5"><font size="9"><b><i>{{ $datos['proyecto'] ?: '' }}</i></b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>CONTRATO Nº:</b></font></td>
        <td colspan="5"><font size="9">{{ $datos['contrato'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td><font size="9"><b>FECHA DE ENTREGA:</b></font></td>
        <td width="20%"><font size="9">{{ $datos['fecha'] }}</font></td>
        <td width="10%"><font size="9"><b>RQ N°:</b></font></td>
        <td width="15%"><font size="9">{{ $datos['rq'] ?: '' }}</font></td>
        <td width="13%"><font size="9"><b>Solicitante:</b></font></td>
        <td><font size="9">{{ $datos['solicitante'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td><font size="9"><b>DEPARTAMENTO:</b></font></td>
        <td colspan="5"><font size="9">{{ $datos['departamento'] ?: '' }}</font></td>
    </tr>
</table>

{{-- ── Tabla de ítems: ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL ── --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <thead>
        <tr bgcolor="#1e293b">
            <th width="7%"  align="center"><font size="8" color="#ffffff"><b>ITEM</b></font></th>
            <th width="11%" align="center"><font size="8" color="#ffffff"><b>CANTIDAD</b></font></th>
            <th width="12%" align="center"><font size="8" color="#ffffff"><b>UNIDAD</b></font></th>
            <th width="50%" align="center"><font size="8" color="#ffffff"><b>DESCRIPCIÓN</b></font></th>
            <th width="20%" align="center"><font size="8" color="#ffffff"><b>N° COLADA / SERIAL</b></font></th>
        </tr>
    </thead>
    <tbody>
        @foreach($movs as $i => $m)
            <tr>
                <td align="center"><font size="8">{{ $i + 1 }}</font></td>
                <td align="center"><font size="8">{{ $fmt($m->CANTIDAD) }}</font></td>
                <td align="center"><font size="8">{{ $m->producto?->UM ?? '' }}</font></td>
                <td><font size="8">{{ $m->producto?->NOMBRE ?? '' }}</font></td>
                <td align="center"><font size="8">{{ $m->producto?->CODIGO ?? '' }}</font></td>
            </tr>
        @endforeach
        @for($j = $movs->count(); $j < $minFilas; $j++)
            <tr>
                <td align="center"><font size="8">{{ $j + 1 }}</font></td>
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
        @endfor
    </tbody>
</table>

{{-- ── Observaciones: label + caja vacía para escribir ── --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td><font size="9"><b>OBSERVACIONES:</b></font></td>
    </tr>
    <tr>
        <td height="30"><font size="9">{{ $datos['motivo'] ?: ' ' }}</font></td>
    </tr>
</table>

{{-- ── Firmas: label | ENTREGADO POR | RECIBIDO POR ──
     Almacenista del almacén origen va pre-impreso como ENTREGADO POR + cargo
     "COORD. DE MATERIALES" (estándar VIDALSA). RECIBIDO POR en blanco para
     que la persona del proyecto lo rellene a mano al recibir. --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td width="14%">&nbsp;</td>
        <td width="43%" align="center"><font size="9"><b>ENTREGADO POR:</b></font></td>
        <td width="43%" align="center"><font size="9"><b>RECIBIDO POR:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Nombre:</b></font></td>
        <td align="center"><font size="9"><b>NOMBRE:</b> {{ $datos['entregado_por'] ?: '' }}</font></td>
        <td><font size="9"><b>NOMBRE:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Cargo:</b></font></td>
        <td align="center"><font size="9"><b>CARGO:</b> COORD. DE MATERIALES</font></td>
        <td><font size="9"><b>CARGO:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Firma:</b></font></td>
        <td height="18">&nbsp;</td>
        <td height="18">&nbsp;</td>
    </tr>
    <tr>
        <td><font size="9"><b>Fecha:</b></font></td>
        <td align="center"><font size="9">{{ $datos['fecha'] }}</font></td>
        <td>&nbsp;</td>
    </tr>
</table>

{{-- ── Vehículo / Chofer: 2 cuadros separados side-by-side (no full-width) ── --}}
<table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="40%" valign="top">
            <table border="1" cellpadding="2" cellspacing="0" width="100%">
                <tr><td colspan="2" align="center"><font size="9"><b>DATOS DEL VEHICULO</b></font></td></tr>
                <tr>
                    <td width="35%"><font size="9"><b>VEHICULO:</b></font></td>
                    <td>&nbsp;</td>
                </tr>
                <tr>
                    <td><font size="9"><b>PLACA:</b></font></td>
                    <td>&nbsp;</td>
                </tr>
                <tr>
                    <td><font size="9"><b>EMPRESA:</b></font></td>
                    <td><font size="9">Constructora Vidalsa 27, C.A.</font></td>
                </tr>
            </table>
        </td>
        <td width="6%">&nbsp;</td>
        <td width="40%" valign="top">
            <table border="1" cellpadding="2" cellspacing="0" width="100%">
                <tr><td colspan="2" align="center"><font size="9"><b>DATOS DEL CHOFER</b></font></td></tr>
                <tr>
                    <td width="35%"><font size="9"><b>NOMBRE:</b></font></td>
                    <td>&nbsp;</td>
                </tr>
                <tr>
                    <td><font size="9"><b>CEDULA:</b></font></td>
                    <td>&nbsp;</td>
                </tr>
                <tr>
                    <td><font size="9"><b>FIRMA:</b></font></td>
                    <td>&nbsp;</td>
                </tr>
            </table>
        </td>
        <td width="14%">&nbsp;</td>
    </tr>
</table>
