<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: helvetica; font-size: 9pt; color: #000; margin: 0; padding: 0; }
        table { border-collapse: collapse; }
        .border-all td, .border-all th { border: 0.5pt solid #000; }
        .section-header {
            background-color: #d9e2f3; font-weight: bold; font-size: 9pt;
            padding: 4px 6px; text-align: left;
        }
        .field-label { font-weight: bold; font-size: 8pt; color: #333; padding: 3px 5px; }
        .field-value { font-size: 8.5pt; padding: 3px 5px; }
        .check-box { font-size: 9pt; }
        .big-area { min-height: 60px; padding: 5px; font-size: 8.5pt; vertical-align: top; }
        .sig-label { font-size: 8pt; font-weight: bold; text-align: center; padding: 3px; }
        .sig-line { border-top: 0.5pt solid #000; margin-top: 25px; }
    </style>
</head>
<body>

@foreach($fallas as $index => $f)
@php
    $equipo = $f->equipo;
    $tipo = $equipo->tipo ?? null;
    $tipoNombre = strtoupper($tipo->nombre ?? '');
    $esMaquinaria = str_contains($tipoNombre, 'EXCAVADORA') || str_contains($tipoNombre, 'RETRO') || str_contains($tipoNombre, 'CARGADOR') || str_contains($tipoNombre, 'RODILLO') || str_contains($tipoNombre, 'MOTONIVELADORA') || str_contains($tipoNombre, 'TRACTOR') || str_contains($tipoNombre, 'GRUA');
    $esVehiculo = str_contains($tipoNombre, 'CAMION') || str_contains($tipoNombre, 'CHUTO') || str_contains($tipoNombre, 'GANDOLA') || str_contains($tipoNombre, 'VEHICULO') || str_contains($tipoNombre, 'PICK') || str_contains($tipoNombre, 'AUTOBUS');
    $esOtro = !$esMaquinaria && !$esVehiculo;
    $esCorrectivo = in_array($f->TIPO_FALLA, ['MECANICA','ELECTRICA','HIDRAULICA','NEUMATICA','ESTRUCTURAL']);
@endphp

<!-- ═══════════════════════════════════════════════════ -->
<!-- HEADER: Logo area + Title + Metadata               -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <!-- Logo area -->
        <td width="20%" rowspan="6" align="center" valign="middle" style="padding:8px;">
            <b style="font-size:8pt; color:#333;">CONSTRUCTORA<br>VIDALSA 27, C.A.</b>
        </td>
        <!-- Title -->
        <td width="50%" rowspan="6" align="center" valign="middle" style="padding:5px;">
            <b style="font-size: 14pt;">REPORTE DE FALLAS</b>
        </td>
        <!-- Metadata -->
        <td width="30%" style="padding:3px 6px; font-size:8pt;"><b>Código:</b> RF-{{ str_pad($f->ID_FALLA, 5, '0', STR_PAD_LEFT) }}</td>
    </tr>
    <tr>
        <td style="padding:3px 6px; font-size:8pt;"><b>Revisión:</b> 1</td>
    </tr>
    <tr>
        <td style="padding:3px 6px; font-size:8pt;"><b>Sección:</b> Mantenimiento</td>
    </tr>
    <tr>
        <td style="padding:3px 6px; font-size:8pt;"><b>Proc.de Ref:</b> —</td>
    </tr>
    <tr>
        <td style="padding:3px 6px; font-size:8pt;"><b>Fecha de Emisión:</b> {{ $f->HORA_REGISTRO ? $f->HORA_REGISTRO->format('d/m/Y') : date('d/m/Y') }}</td>
    </tr>
    <tr>
        <td style="padding:3px 6px; font-size:8pt;"><b>Página {{ $index + 1 }} de {{ $fallas->count() }}</b></td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- SECTION 1: INFORMACIÓN GENERAL                     -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <td colspan="4" class="section-header">1. INFORMACIÓN GENERAL</td>
    </tr>
    <tr>
        <td width="55%" colspan="2" style="padding:5px 6px; min-height:20px;">
            <span class="field-label">NOMBRE, APELLIDO Y CARGO:</span><br>
            <span class="field-value">{{ strtoupper($f->usuarioRegistra->NOMBRE_COMPLETO ?? '') }}</span>
        </td>
        <td width="45%" colspan="2" style="padding:5px 6px;">
            <span class="field-label">FECHA DE SOLICITUD:</span><br>
            <span class="field-value">{{ $f->HORA_REGISTRO ? $f->HORA_REGISTRO->format('d/m/Y H:i') : '' }}</span>
        </td>
    </tr>
    <tr>
        <td colspan="4" style="padding:5px 6px; min-height:20px;">
            <span class="field-label">FRENTE DE TRABAJO:</span><br>
            <span class="field-value">{{ strtoupper($f->reporte->frente->NOMBRE_FRENTE ?? '') }} {{ $f->reporte->frente->UBICACION ? '— ' . strtoupper($f->reporte->frente->UBICACION) : '' }}</span>
        </td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- SECTION 2: IDENTIFICACIÓN DEL EQUIPO               -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <td colspan="6" class="section-header">2. IDENTIFICACIÓN DEL EQUIPO</td>
    </tr>
    <!-- Equipment type checkboxes -->
    <tr>
        <td width="33%" colspan="2" align="center" style="padding:5px;">
            <span class="check-box">{{ $esMaquinaria ? '☑' : '☐' }} MAQUINARIA</span>
        </td>
        <td width="33%" colspan="2" align="center" style="padding:5px;">
            <span class="check-box">{{ $esVehiculo ? '☑' : '☐' }} VEHÍCULO</span>
        </td>
        <td width="34%" colspan="2" align="center" style="padding:5px;">
            <span class="check-box">{{ $esOtro ? '☑' : '☐' }} OTRO</span>
        </td>
    </tr>
    <!-- Marca / Modelo -->
    <tr>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">MARCA:</span>
            <span class="field-value">{{ strtoupper($equipo->MARCA ?? '') }}</span>
        </td>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">MODELO:</span>
            <span class="field-value">{{ strtoupper($equipo->MODELO ?? '') }} ({{ $equipo->ANIO ?? '' }})</span>
        </td>
    </tr>
    <!-- Placa / Serial -->
    <tr>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">PLACA:</span>
            <span class="field-value">{{ strtoupper($equipo->CODIGO_PATIO ?? '—') }}</span>
        </td>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">SERIAL:</span>
            <span class="field-value">{{ strtoupper($equipo->SERIAL_CHASIS ?? '—') }}</span>
        </td>
    </tr>
    <!-- Kilometraje / Horas -->
    <tr>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">KILOMETRAJE:</span>
            <span class="field-value">—</span>
        </td>
        <td width="50%" colspan="3" style="padding:4px 6px;">
            <span class="field-label">HORAS:</span>
            <span class="field-value">—</span>
        </td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- SECTION 3: TIPO DE MANTENIMIENTO REQUERIDO         -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <td width="50%" class="section-header">3. TIPO DE MANTENIMIENTO REQUERIDO</td>
        <td width="25%" align="center" style="padding:5px; border:0.5pt solid #000;">
            <span class="check-box">{{ !$esCorrectivo ? '☑' : '☐' }} PREVENTIVO</span>
        </td>
        <td width="25%" align="center" style="padding:5px; border:0.5pt solid #000;">
            <span class="check-box">{{ $esCorrectivo ? '☑' : '☐' }} CORRECTIVO</span>
        </td>
    </tr>
    <tr>
        <td colspan="3" class="big-area" style="min-height:100px; height:100px;">
            <span class="field-label">DESCRIPCIÓN DE FALLA O REQUERIMIENTO:</span><br><br>
            <span class="field-value">
                <b>Tipo:</b> {{ $f->TIPO_FALLA }}<br>
                @if($f->SISTEMA_AFECTADO)<b>Sistema Afectado:</b> {{ $f->SISTEMA_AFECTADO }}<br>@endif
                <b>Prioridad:</b> {{ $f->PRIORIDAD }}<br><br>
                {{ $f->DESCRIPCION_FALLA }}
            </span>
            @if($f->materiales && $f->materiales->count() > 0)
            <br><br>
            <span class="field-label">MATERIALES REQUERIDOS:</span><br>
            @foreach($f->materiales as $mat)
                <span class="field-value">• {{ $mat->DESCRIPCION_MATERIAL }}{{ $mat->ESPECIFICACION ? ' (' . $mat->ESPECIFICACION . ')' : '' }} — Cant: {{ number_format($mat->CANTIDAD, 2) }} {{ $mat->UNIDAD }}</span><br>
            @endforeach
            @endif
        </td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- SECTION 4: SECCIÓN EXCLUSIVA PARA TALLER           -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <td colspan="2" class="section-header">4. SECCIÓN EXCLUSIVA PARA TALLER DE MANTENIMIENTO</td>
    </tr>
    <tr>
        <td width="50%" style="padding:4px 6px;">
            <span class="field-label">MECÁNICO ASIGNADO:</span><br>
            <span class="field-value">&nbsp;</span>
        </td>
        <td width="50%" style="padding:4px 6px;">
            <span class="field-label">FECHA DE RECEPCIÓN:</span><br>
            <span class="field-value">&nbsp;</span>
        </td>
    </tr>
    <tr>
        <td width="50%" style="padding:4px 6px; font-weight:bold; font-size:8.5pt; text-align:center; background:#f5f5f5;">
            DIAGNÓSTICO
        </td>
        <td width="50%" style="padding:4px 6px; font-weight:bold; font-size:8.5pt; text-align:center; background:#f5f5f5;">
            ACCIONES REALIZADAS
        </td>
    </tr>
    <tr>
        <td width="50%" class="big-area" style="min-height:80px; height:80px;">
            @if($f->ESTADO_FALLA === 'RESUELTA' && $f->DESCRIPCION_RESOLUCION)
                <span class="field-value">{{ $f->DESCRIPCION_RESOLUCION }}</span>
            @else
                <span class="field-value">&nbsp;</span>
            @endif
        </td>
        <td width="50%" class="big-area" style="min-height:80px; height:80px;">
            <span class="field-value">&nbsp;</span>
        </td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- ESTATUS                                             -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all">
    <tr>
        <td colspan="3" style="padding:5px 6px; font-size:8.5pt;">
            <b>ESTATUS:</b>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            {{ $f->ESTADO_FALLA !== 'RESUELTA' ? '☑' : '☐' }} PENDIENTE
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            {{ $f->ESTADO_FALLA === 'RESUELTA' ? '☑' : '☐' }} CERRADO
        </td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════ -->
<!-- SIGNATURES                                          -->
<!-- ═══════════════════════════════════════════════════ -->
<table width="100%" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;" class="border-all" nobr="true">
    <!-- Header row -->
    <tr>
        <td width="33%" align="center" style="padding:3px; font-size:8pt; font-weight:bold; background:#f5f5f5;">SOLICITADO:</td>
        <td width="34%" align="center" style="padding:3px; font-size:8pt; font-weight:bold; background:#f5f5f5;">RECIBIDO:</td>
        <td width="33%" align="center" style="padding:3px; font-size:8pt; font-weight:bold; background:#f5f5f5;">AUTORIZADO:</td>
    </tr>
    <!-- Name row -->
    <tr>
        <td align="center" style="padding:3px 5px; font-size:8pt;">
            <b>NOMBRE:</b> {{ strtoupper($f->usuarioRegistra->NOMBRE_COMPLETO ?? '') }}
        </td>
        <td align="center" style="padding:3px 5px; font-size:8pt;">
            <b>NOMBRE:</b> ___________________________
        </td>
        <td align="center" style="padding:3px 5px; font-size:8pt;">
            <b>NOMBRE:</b> ___________________________
        </td>
    </tr>
    <!-- Signature row -->
    <tr>
        <td align="center" style="padding:3px 5px; height:30px; font-size:8pt;">
            <b>FIRMA:</b>
        </td>
        <td align="center" style="padding:3px 5px; height:30px; font-size:8pt;">
            <b>FIRMA:</b>
        </td>
        <td align="center" style="padding:3px 5px; height:30px; font-size:8pt;">
            <b>FIRMA:</b>
        </td>
    </tr>
</table>

@if(!$loop->last)
    <!-- Page break between faults -->
    <br pagebreak="true"/>
@endif

@endforeach

</body>
</html>
