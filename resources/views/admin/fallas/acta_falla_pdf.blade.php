<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body>
@php
    use Illuminate\Support\Str;

    $marca  = $activo ? ($activo->MARCA ?? '') : '';
    $modelo = $activo ? ($activo->MODELO ?? '') : '';
    $serial = $activo ? ($activo->SERIAL_CHASIS ?? $activo->SERIAL ?? '') : '';
    $placa  = ($activo && method_exists($activo, 'documentacion'))
                  ? (optional($activo->documentacion)->PLACA ?? '') : '';

    $tipoMant = $falla->TIPO_MANTENIMIENTO;
    $cerrado  = $falla->ESTADO_REPORTE === 'cerrado';

    $box      = fn($on) => $on ? '[X]' : '[&nbsp;&nbsp;]';
    $boxEmpty = '[&nbsp;&nbsp;]';   // Maquinaria/Vehículo/Otro: vacías, las marca el usuario al imprimir.

    $descripcion = Str::limit((string) $falla->DESCRIPCION_AVERIA, 700);
    $diagnostico = Str::limit((string) $falla->DIAGNOSTICO, 350);
    $acciones    = Str::limit((string) $falla->ACCIONES_REALIZADAS, 350);

    // Líneas finas 0.1mm por CSS (NO border="1", que TCPDF dibuja ~0.35mm grueso e
    // ignora SetLineWidth). Mismo grosor que el acta de movilización del módulo equipos.
    $bs = 'border:0.1mm solid #000;';
    $hb = 'bgcolor="#d6e0f2"';
@endphp

{{-- Todas las secciones van pegadas (sin espaciadores) para que no haya franja
     blanca entre tablas: una termina y la siguiente empieza de una. --}}

{{-- ─── 1. INFORMACIÓN GENERAL ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
    <tr><td colspan="4" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="9"><b>1. INFORMACIÓN GENERAL</b></font></td></tr>
    <tr>
        <td width="27%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Nombre, apellido y cargo:</b></font></td>
        <td width="40%" style="{!! $bs !!}"><font face="helvetica" size="8">{{ strtoupper($falla->NOMBRE_REPORTA ?? '') }}@if($falla->CARGO_REPORTA) — {{ strtoupper($falla->CARGO_REPORTA) }}@endif</font></td>
        <td width="17%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Fecha de solicitud:</b></font></td>
        <td width="16%" style="{!! $bs !!}"><font face="helvetica" size="8">{{ $falla->FECHA_EMISION->format('d/m/Y') }}</font></td>
    </tr>
    <tr>
        <td width="27%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Frente de trabajo:</b></font></td>
        <td width="73%" colspan="3" style="{!! $bs !!}"><font face="helvetica" size="8">{{ strtoupper($falla->FRENTE_TRABAJO ?: '') }}</font></td>
    </tr>
</table>

{{-- ─── 2. IDENTIFICACIÓN DEL EQUIPO (2 columnas: cada celda "Etiqueta: valor") ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
    <tr><td colspan="2" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="9"><b>2. IDENTIFICACIÓN DEL EQUIPO</b></font></td></tr>
    <tr>
        <td colspan="2" style="{!! $bs !!}"><font face="helvetica" size="8">Maquinaria <b>{!! $boxEmpty !!}</b> &nbsp;&nbsp;&nbsp;&nbsp; Vehículo <b>{!! $boxEmpty !!}</b> &nbsp;&nbsp;&nbsp;&nbsp; Otro <b>{!! $boxEmpty !!}</b></font></td>
    </tr>
    <tr>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Marca:</b> {{ strtoupper($marca) }}</font></td>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Modelo:</b> {{ strtoupper($modelo) }}</font></td>
    </tr>
    <tr>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Placa:</b> {{ strtoupper($placa) }}</font></td>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Serial:</b> {{ strtoupper($serial) }}</font></td>
    </tr>
    <tr>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Kilometraje:</b> {{ $falla->KILOMETRAJE ?: '' }}</font></td>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Horas:</b> {{ $falla->HORAS ?: '' }}</font></td>
    </tr>
</table>

{{-- ─── 3. TIPO DE MANTENIMIENTO REQUERIDO ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
    <tr>
        <td width="55%" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="9"><b>3. TIPO DE MANTENIMIENTO REQUERIDO</b></font></td>
        <td width="22%" {!! $hb !!} align="center" style="{!! $bs !!}"><font face="helvetica" size="8"><b>PREVENTIVO {!! $box($tipoMant === 'PREVENTIVO') !!}</b></font></td>
        <td width="23%" {!! $hb !!} align="center" style="{!! $bs !!}"><font face="helvetica" size="8"><b>CORRECTIVO {!! $box($tipoMant === 'CORRECTIVO') !!}</b></font></td>
    </tr>
    <tr>
        <td colspan="3" valign="top" height="120" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Descripción de falla o requerimiento:</b><br><br>{{ $descripcion }}</font></td>
    </tr>
</table>

{{-- ─── 4. SECCIÓN EXCLUSIVA PARA TALLER DE MANTENIMIENTO ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
    <tr><td colspan="2" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="9"><b>4. SECCIÓN EXCLUSIVA PARA TALLER DE MANTENIMIENTO</b></font></td></tr>
    <tr>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Mecánico asignado:</b> {{ strtoupper($falla->MECANICO_ASIGNADO ?: '') }}</font></td>
        <td width="50%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Fecha de recepción:</b> {{ optional($falla->FECHA_RECEPCION)->format('d/m/Y') ?: '' }}</font></td>
    </tr>
    <tr>
        <td width="50%" {!! $hb !!} align="center" style="{!! $bs !!}"><font face="helvetica" size="8"><b>DIAGNÓSTICO</b></font></td>
        <td width="50%" {!! $hb !!} align="center" style="{!! $bs !!}"><font face="helvetica" size="8"><b>ACCIONES REALIZADAS</b></font></td>
    </tr>
    <tr>
        <td width="50%" valign="top" height="130" style="{!! $bs !!}"><font face="helvetica" size="8">{{ $diagnostico }}</font></td>
        <td width="50%" valign="top" height="130" style="{!! $bs !!}"><font face="helvetica" size="8">{{ $acciones }}</font></td>
    </tr>
</table>

{{-- ─── ESTATUS (todo en UNA casilla) ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
    <tr>
        <td style="{!! $bs !!}"><font face="helvetica" size="8"><b>Estatus:</b> &nbsp;&nbsp; <b>Pendiente:</b> {!! $box(!$cerrado) !!} &nbsp;&nbsp;&nbsp;&nbsp; <b>Cerrado:</b> {!! $box($cerrado) !!}@if($cerrado && $falla->FECHA_CIERRE) &nbsp;({{ $falla->FECHA_CIERRE->format('d/m/Y') }})@endif</font></td>
    </tr>
</table>

{{-- ─── FIRMAS: SOLICITADO · RECIBIDO · AUTORIZADO ─── --}}
<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;" nobr="true">
    <tr>
        <td width="34%" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="8"><b>SOLICITADO:</b></font></td>
        <td width="33%" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="8"><b>RECIBIDO:</b></font></td>
        <td width="33%" {!! $hb !!} style="{!! $bs !!}"><font face="helvetica" size="8"><b>AUTORIZADO:</b></font></td>
    </tr>
    <tr>
        <td width="34%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Nombre:</b> {{ strtoupper($falla->NOMBRE_REPORTA ?: '') }}</font></td>
        <td width="33%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Nombre:</b> {{ strtoupper($falla->MECANICO_ASIGNADO ?: '') }}</font></td>
        <td width="33%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Nombre:</b> {{ strtoupper($cerrado ? ($falla->NOMBRE_CIERRA ?: '') : '') }}</font></td>
    </tr>
    <tr>
        <td width="34%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Firma:</b></font></td>
        <td width="33%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Firma:</b></font></td>
        <td width="33%" style="{!! $bs !!}"><font face="helvetica" size="8"><b>Firma:</b></font></td>
    </tr>
</table>

</body>
</html>
