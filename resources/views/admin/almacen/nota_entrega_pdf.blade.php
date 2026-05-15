{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — replica el formato VID-FO-GEN-019
    del Excel oficial (Downloads\Nueva Nota de entrega de materiales 2025.xlsx).

    El cabezote (caja externa + logo + título + sello CODIGO/EMIS/REV/PAG) lo pinta
    NotaEntregaPDF::Header() con Rect()/Line()/Cell() para tener bordes precisos.
    Este blade renderiza el cuerpo a partir de Y≈42mm.

    Variables disponibles:
      - $datos: ['numero_nota','proyecto','contrato','fecha','rq','solicitante',
                 'departamento','almacen','entregado_por','motivo']
      - $movs:  Collection<MovimientoInventario>  con relaciones {producto}
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    // 25 filas como el Excel original.
    $minFilas = 25;
@endphp

{{-- ── N° de Nota (identificador legible NE-YYYY-NNNN) ── --}}
@if(!empty($datos['numero_nota']))
<table cellpadding="2" cellspacing="0" border="0" style="width:100%;margin-bottom:2px;">
    <tr>
        <td align="right" style="font-size:9pt;font-weight:bold;color:#0f172a;">
            N° de Nota: <span style="color:#0067b1;">{{ $datos['numero_nota'] }}</span>
        </td>
    </tr>
</table>
@endif

{{-- ── Bloque de datos del proyecto (una sola tabla con 4 filas, como el Excel):
       fila 1: PROYECTO (label + valor, en itálica como el formato oficial)
       fila 2: CONTRATO Nº
       fila 3: FECHA DE ENTREGA · RQ N° · Solicitante (3 pares label/valor)
       fila 4: DEPARTAMENTO (label + valor)
     Grid de 6 columnas: 20% | 20% | 10% | 15% | 13% | 22% (total 100%) --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9.5pt;border-collapse:collapse;">
    <tr>
        <td width="20%" style="font-weight:bold;font-style:italic;">PROYECTO:</td>
        <td colspan="5" style="font-weight:bold;font-style:italic;">{{ $datos['proyecto'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">CONTRATO Nº:</td>
        <td colspan="5">{{ $datos['contrato'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">FECHA DE ENTREGA:</td>
        <td width="20%">{{ $datos['fecha'] }}</td>
        <td width="10%" style="font-weight:bold;">RQ N°:</td>
        <td width="15%">{{ $datos['rq'] ?: '' }}</td>
        <td width="13%" style="font-weight:bold;">Solicitante:</td>
        <td>{{ $datos['solicitante'] ?: '' }}</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">DEPARTAMENTO:</td>
        <td colspan="5">{{ $datos['departamento'] ?: '' }}</td>
    </tr>
</table>

{{-- ── Tabla de ítems (ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL) ── --}}
<table cellpadding="2" cellspacing="0" border="1" style="width:100%;font-size:8pt;border-collapse:collapse;">
    <thead>
        <tr style="background-color:#1e293b;color:#ffffff;font-weight:bold;font-size:8.5pt;">
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

{{-- ── Observaciones (label + caja grande para escribir a mano) ── --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;border-collapse:collapse;">
    <tr>
        <td style="font-weight:bold;">OBSERVACIONES:</td>
    </tr>
    <tr>
        <td style="height:30px;">{{ $datos['motivo'] ?: ' ' }}</td>
    </tr>
</table>

{{-- ── Firmas: Entregado por / Recibido por (3 columnas como el Excel) ──
     Col 1 (labels Nombre/Cargo/Firma/Fecha) | Col 2 ENTREGADO POR | Col 3 RECIBIDO POR.
     El almacenista del almacén origen va pre-impreso como ENTREGADO POR + cargo
     "COORD. DE MATERIALES" (estándar VIDALSA). RECIBIDO POR queda en blanco para
     que la persona del proyecto lo rellene a mano al recibir. --}}
<table cellpadding="3" cellspacing="0" border="1" style="width:100%;font-size:9pt;border-collapse:collapse;">
    <tr>
        <td width="14%">&nbsp;</td>
        <td width="43%" align="center" style="font-weight:bold;">ENTREGADO POR:</td>
        <td width="43%" align="center" style="font-weight:bold;">RECIBIDO POR:</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Nombre:</td>
        <td align="center">NOMBRE: {{ $datos['entregado_por'] ?: '' }}</td>
        <td>NOMBRE:</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Cargo:</td>
        <td align="center">CARGO: COORD. DE MATERIALES</td>
        <td>CARGO:</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Firma:</td>
        <td style="height:18px;">&nbsp;</td>
        <td style="height:18px;">&nbsp;</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Fecha:</td>
        <td align="center">{{ $datos['fecha'] }}</td>
        <td align="center">&nbsp;</td>
    </tr>
</table>

{{-- ── Datos del vehículo / Datos del chofer (2 cuadros separados como el Excel,
     no una sola tabla full-width) ── --}}
<table cellpadding="0" cellspacing="0" border="0" style="width:100%;font-size:9pt;margin-top:2px;">
    <tr>
        <td width="40%" style="vertical-align:top;">
            <table cellpadding="2" cellspacing="0" border="1" style="width:100%;font-size:8.5pt;border-collapse:collapse;">
                <tr><td align="center" style="font-weight:bold;" colspan="2">DATOS DEL VEHICULO</td></tr>
                <tr><td width="35%" style="font-weight:bold;">VEHICULO:</td><td>&nbsp;</td></tr>
                <tr><td style="font-weight:bold;">PLACA:</td><td>&nbsp;</td></tr>
                <tr><td style="font-weight:bold;">EMPRESA:</td><td>Constructora Vidalsa 27, C.A.</td></tr>
            </table>
        </td>
        <td width="6%">&nbsp;</td>
        <td width="40%" style="vertical-align:top;">
            <table cellpadding="2" cellspacing="0" border="1" style="width:100%;font-size:8.5pt;border-collapse:collapse;">
                <tr><td align="center" style="font-weight:bold;" colspan="2">DATOS DEL CHOFER</td></tr>
                <tr><td width="35%" style="font-weight:bold;">NOMBRE:</td><td>&nbsp;</td></tr>
                <tr><td style="font-weight:bold;">CEDULA:</td><td>&nbsp;</td></tr>
                <tr><td style="font-weight:bold;">FIRMA:</td><td>&nbsp;</td></tr>
            </table>
        </td>
        <td width="14%">&nbsp;</td>
    </tr>
</table>
