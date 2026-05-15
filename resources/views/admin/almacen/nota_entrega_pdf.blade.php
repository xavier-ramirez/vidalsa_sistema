{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — replica el formato VID-FO-GEN-019
    del Excel oficial (Downloads\Nueva Nota de entrega de materiales 2025.xlsx).

    El cabezote (logo + título + sello CODIGO/EMIS/REV/PAG) lo pinta NotaEntregaPDF::Header()
    en TCPDF. Este blade renderiza el cuerpo a partir de Y≈42mm.

    Variables disponibles:
      - $datos: ['numero_nota','proyecto','contrato','fecha','rq','solicitante',
                 'departamento','almacen','entregado_por','motivo']
      - $movs:  Collection<MovimientoInventario>  con relaciones {producto}
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    // 25 filas como el Excel original — el formulario impreso queda parejo aunque
    // la nota lleve pocas líneas.
    $minFilas = 25;
@endphp

{{-- ── N° de Nota (identificador legible NE-YYYY-NNNN) ── --}}
@if(!empty($datos['numero_nota']))
<table cellpadding="2" cellspacing="0" border="0" style="width:100%;margin-bottom:2px;">
    <tr>
        <td align="right" style="font-size:10pt;font-weight:bold;color:#0f172a;">
            N° de Nota: <span style="color:#0067b1;">{{ $datos['numero_nota'] }}</span>
        </td>
    </tr>
</table>
@endif

{{-- ── Bloque de datos del proyecto (sin fondos grises, como el Excel) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:10pt;border-collapse:collapse;">
    <tr>
        <td width="20%" style="font-weight:bold;">PROYECTO:</td>
        <td colspan="3" style="font-weight:bold;">{{ $datos['proyecto'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">CONTRATO Nº:</td>
        <td colspan="3">{{ $datos['contrato'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">FECHA DE ENTREGA:</td>
        <td width="25%">{{ $datos['fecha'] }}</td>
        <td width="14%" style="font-weight:bold;">RQ N°:</td>
        <td>{{ $datos['rq'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Solicitante:</td>
        <td>{{ $datos['solicitante'] ?: '' }}</td>
        <td style="font-weight:bold;">DEPARTAMENTO:</td>
        <td>{{ $datos['departamento'] ?: '' }}</td>
    </tr>
</table>

{{-- ── Tabla de ítems: ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL ── --}}
<table cellpadding="2" cellspacing="0" border="1" style="width:100%;font-size:8.5pt;border-collapse:collapse;margin-top:2px;">
    <thead>
        <tr style="background-color:#1e293b;color:#ffffff;font-weight:bold;">
            <th width="7%"  align="center">ITEM</th>
            <th width="11%" align="center">CANTIDAD</th>
            <th width="12%" align="center">UNIDAD</th>
            <th width="50%" align="center">DESCRIPCIÓN</th>
            <th width="20%" align="center">N° COLADA / SERIAL</th>
        </tr>
    </thead>
    <tbody>
        @foreach($movs as $i => $m)
            <tr>
                <td align="center">{{ $i + 1 }}</td>
                <td align="center">{{ $fmt($m->CANTIDAD) }}</td>
                <td align="center">{{ $m->producto?->UM ?? '' }}</td>
                <td>{{ $m->producto?->NOMBRE ?? '' }}</td>
                <td align="center">{{ $m->producto?->CODIGO ?? '' }}</td>
            </tr>
        @endforeach
        @for($j = $movs->count(); $j < $minFilas; $j++)
            <tr>
                <td align="center">{{ $j + 1 }}</td>
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
        @endfor
    </tbody>
</table>

{{-- ── Observaciones (label + caja grande para escribir a mano, sin fondo gris) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;border-collapse:collapse;margin-top:2px;">
    <tr>
        <td style="font-weight:bold;">OBSERVACIONES:</td>
    </tr>
    <tr>
        <td style="height:38px;">{{ $datos['motivo'] ?: ' ' }}</td>
    </tr>
</table>

{{-- ── Firmas: Entregado por / Recibido por (3 columnas como el Excel) ──
     Col 1 (labels Nombre/Cargo/Firma/Fecha) | Col 2 ENTREGADO POR | Col 3 RECIBIDO POR.
     El almacenista del almacén origen va pre-impreso como ENTREGADO POR + cargo
     "COORD. DE MATERIALES" (estándar VIDALSA). RECIBIDO POR queda en blanco para
     que la persona del proyecto lo rellene a mano al recibir. --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;border-collapse:collapse;margin-top:2px;">
    <tr>
        <td width="14%">&nbsp;</td>
        <td width="43%" align="center" style="font-weight:bold;">ENTREGADO POR:</td>
        <td width="43%" align="center" style="font-weight:bold;">RECIBIDO POR:</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Nombre:</td>
        <td><b>NOMBRE:</b> {{ $datos['entregado_por'] ?: '' }}</td>
        <td><b>NOMBRE:</b></td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Cargo:</td>
        <td><b>CARGO:</b> COORD. DE MATERIALES</td>
        <td><b>CARGO:</b></td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Firma:</td>
        <td style="height:22px;">&nbsp;</td>
        <td style="height:22px;">&nbsp;</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Fecha:</td>
        <td>{{ $datos['fecha'] }}</td>
        <td>&nbsp;</td>
    </tr>
</table>

{{-- ── Datos del vehículo / Datos del chofer (sin fondo gris) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;border-collapse:collapse;margin-top:2px;">
    <tr>
        <td width="50%" align="center" style="font-weight:bold;">DATOS DEL VEHICULO</td>
        <td width="50%" align="center" style="font-weight:bold;">DATOS DEL CHOFER</td>
    </tr>
    <tr>
        <td><b>VEHICULO:</b></td>
        <td><b>NOMBRE:</b></td>
    </tr>
    <tr>
        <td><b>PLACA:</b></td>
        <td><b>CEDULA:</b></td>
    </tr>
    <tr>
        <td><b>EMPRESA:</b> Constructora Vidalsa 27, C.A.</td>
        <td><b>FIRMA:</b></td>
    </tr>
</table>
