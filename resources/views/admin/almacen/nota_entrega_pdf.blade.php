{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" (formato VID-FO-GEN-019).
    El cabezote (logo + título + sello de código) lo pinta NotaEntregaPDF::Header()
    en TCPDF; este blade sólo renderiza el bloque de datos a partir de Y≈42mm.

    Variables disponibles:
      - $datos: ['numero_nota','proyecto','contrato','fecha','rq','solicitante',
                 'departamento','almacen','entregado_por','motivo']
      - $movs:  Collection<MovimientoInventario>  con relaciones {producto}
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    // Mínimo de filas a mostrar en la tabla para que el formulario impreso quede prolijo
    // (igual al Excel original que dejaba 24 filas listas). Si hay más líneas, simplemente
    // se imprimen todas; si hay menos, rellenamos con filas vacías.
    $minFilas = 12;
@endphp

{{-- ── Banda del N° de Nota: identificador legible del documento (NE-YYYY-NNNN), justo
     debajo del cabezote para que sea lo primero que se reconoce al imprimir. --}}
@if(!empty($datos['numero_nota']))
<table cellpadding="4" cellspacing="0" border="0" style="width:100%;margin-bottom:6px;">
    <tr>
        <td align="right" style="font-size:11pt;font-weight:bold;color:#0f172a;">
            N° de Nota: <span style="color:#0067b1;">{{ $datos['numero_nota'] }}</span>
        </td>
    </tr>
</table>
@endif

{{-- ── Bloque de cabecera de datos (proyecto, contrato, fecha, rq, solicitante, departamento) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:10pt;">
    <tr>
        <td width="20%" style="background-color:#e2e8f0;font-weight:bold;">PROYECTO:</td>
        <td colspan="3" style="font-weight:bold;">{{ $datos['proyecto'] ?: '—' }}</td>
    </tr>
    <tr>
        <td style="background-color:#e2e8f0;font-weight:bold;">CONTRATO Nº:</td>
        <td colspan="3">{{ $datos['contrato'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="background-color:#e2e8f0;font-weight:bold;">FECHA DE ENTREGA:</td>
        <td width="28%">{{ $datos['fecha'] }}</td>
        <td width="14%" style="background-color:#e2e8f0;font-weight:bold;">RQ N°:</td>
        <td>{{ $datos['rq'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="background-color:#e2e8f0;font-weight:bold;">Solicitante:</td>
        <td>{{ $datos['solicitante'] ?: '' }}</td>
        <td style="background-color:#e2e8f0;font-weight:bold;">DEPARTAMENTO:</td>
        <td>{{ $datos['departamento'] ?: '' }}</td>
    </tr>
</table>

<br>

{{-- ── Tabla de ítems (ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;">
    <thead>
        <tr style="background-color:#1e293b;color:#ffffff;font-weight:bold;">
            <th width="7%"  align="center">ITEM</th>
            <th width="12%" align="center">CANTIDAD</th>
            <th width="13%" align="center">UNIDAD</th>
            <th width="48%" align="center">DESCRIPCIÓN</th>
            <th width="20%" align="center">N° COLADA / SERIAL</th>
        </tr>
    </thead>
    <tbody>
        @foreach($movs as $i => $m)
            <tr>
                <td align="center">{{ $i + 1 }}</td>
                <td align="center">{{ $fmt($m->CANTIDAD) }}</td>
                <td align="center">{{ $m->producto?->UM ?? '' }}</td>
                <td>{{ $m->producto?->NOMBRE ?? '—' }}</td>
                <td align="center">{{ $m->producto?->CODIGO ?? '' }}</td>
            </tr>
        @endforeach
        {{-- Filas vacías para conservar el aspecto del formulario impreso. --}}
        @for($j = $movs->count(); $j < $minFilas; $j++)
            <tr>
                <td align="center">{{ $j + 1 }}</td>
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
        @endfor
    </tbody>
</table>

<br>

{{-- ── Observaciones ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;">
    <tr>
        <td style="background-color:#e2e8f0;font-weight:bold;">OBSERVACIONES:</td>
    </tr>
    <tr>
        <td style="height:60px;">{{ $datos['motivo'] ?: ' ' }}</td>
    </tr>
</table>

<br>

{{-- ── Firmas (ENTREGADO POR / RECIBIDO POR) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;">
    <tr style="background-color:#e2e8f0;font-weight:bold;">
        <td width="50%" align="center">ENTREGADO POR:</td>
        <td width="50%" align="center">RECIBIDO POR:</td>
    </tr>
    <tr>
        <td style="vertical-align:top;height:80px;">
            <b>NOMBRE:</b> {{ $datos['entregado_por'] }}<br><br>
            <b>CARGO:</b><br><br>
            <b>FIRMA:</b><br><br>
            <b>FECHA:</b> {{ $datos['fecha'] }}
        </td>
        <td style="vertical-align:top;height:80px;">
            <b>NOMBRE:</b><br><br>
            <b>CARGO:</b><br><br>
            <b>FIRMA:</b><br><br>
            <b>FECHA:</b>
        </td>
    </tr>
</table>

<br>

{{-- ── Datos del vehículo / chofer ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;">
    <tr style="background-color:#e2e8f0;font-weight:bold;">
        <td width="50%" align="center">DATOS DEL VEHÍCULO</td>
        <td width="50%" align="center">DATOS DEL CHOFER</td>
    </tr>
    <tr>
        <td style="vertical-align:top;height:40px;">
            <b>VEHÍCULO:</b><br><br>
            <b>PLACA:</b>
        </td>
        <td style="vertical-align:top;height:40px;">
            <b>NOMBRE:</b><br><br>
            <b>CÉDULA:</b>
        </td>
    </tr>
</table>
