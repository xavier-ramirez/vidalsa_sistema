<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body>
@php
    $isAux       = $falla->ACTIVO_TIPO === 'equipo_auxiliar';
    $tipoActivo  = $isAux ? 'EQUIPO AUXILIAR' : 'VEHÍCULO';
    $codigoUnit  = $activo ? ($activo->CODIGO_PATIO ?? $activo->CODIGO_INTERNO ?? '—') : '—';
    $marca       = $activo ? ($activo->MARCA ?? '—') : '—';
    $modelo      = $activo ? ($activo->MODELO ?? '—') : '—';
    $serial      = $activo ? ($activo->SERIAL_CHASIS ?? $activo->SERIAL ?? '—') : '—';
    $serialMotor = $activo ? ($activo->SERIAL_DE_MOTOR ?? '—') : '—';
    $placa       = ($activo && method_exists($activo, 'documentacion'))
                      ? (optional($activo->documentacion)->PLACA ?? 'S/P')
                      : 'S/P';
    $anio        = $activo ? ($activo->ANIO ?? '—') : '—';
    $sistema     = $falla->SISTEMA_AFECTADO
                      ? (\App\Models\Falla::sistemasAfectados()[$falla->SISTEMA_AFECTADO] ?? $falla->SISTEMA_AFECTADO)
                      : '—';
    $prioridad   = $falla->PRIORIDAD
                      ? (\App\Models\Falla::prioridades()[$falla->PRIORIDAD] ?? $falla->PRIORIDAD)
                      : '—';
    $tipoIntv    = $falla->TIPO_INTERVENCION
                      ? (\App\Models\Falla::tiposIntervencion()[$falla->TIPO_INTERVENCION] ?? $falla->TIPO_INTERVENCION)
                      : '—';
@endphp

<!-- Título principal centrado -->
<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="font-size: 15pt;"><b>REPORTE DE FALLAS</b></td></tr>
</table>
<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="6">&nbsp;</td></tr></table>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
<tr><td align="center" style="font-size: 9pt; color:#475569;">{{ $tipoActivo }} · CÓDIGO {{ $falla->CODIGO_REPORTE }} · {{ $falla->FECHA_EMISION->format('d/m/Y H:i') }}</td></tr>
</table>
<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="12">&nbsp;</td></tr></table>

<!-- ─── 1. ENCABEZADO DE CONTROL ─── -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#1e293b">
<td colspan="4" align="center" style="font-size: 9pt; font-weight: bold; color:#ffffff;">1. ENCABEZADO DE CONTROL</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td width="22%" style="font-size: 8.5pt;"><b>Código de Reporte:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->CODIGO_REPORTE }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Fecha de Emisión:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->FECHA_EMISION->format('d/m/Y H:i') }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Estado del Reporte:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($falla->ESTADO_REPORTE) }}</td>
        <td style="font-size: 8.5pt;"><b>Estado del Equipo:</b></td>
<td style="font-size: 8.5pt;">{{ $falla->ESTADO_AL_CREAR ?? '—' }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- ─── 2. IDENTIFICACIÓN DEL ACTIVO ─── -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#1e293b">
<td colspan="4" align="center" style="font-size: 9pt; font-weight: bold; color:#ffffff;">2. IDENTIFICACIÓN DEL ACTIVO</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td width="22%" style="font-size: 8.5pt;"><b>Tipo de Activo:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $tipoActivo }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Código Interno:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $codigoUnit }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Marca:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($marca) }}</td>
        <td style="font-size: 8.5pt;"><b>Modelo:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($modelo) }}</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td style="font-size: 8.5pt;"><b>Placa:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($placa) }}</td>
        <td style="font-size: 8.5pt;"><b>Año:</b></td>
<td style="font-size: 8.5pt;">{{ $anio }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Serial de Chasis:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($serial) }}</td>
        <td style="font-size: 8.5pt;"><b>Serial de Motor:</b></td>
<td style="font-size: 8.5pt;">{{ strtoupper($serialMotor) }}</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td style="font-size: 8.5pt;"><b>Horómetro / Kilometraje:</b></td>
<td colspan="3" style="font-size: 8.5pt;">{{ $falla->HOROMETRO_ACTUAL ?: '—' }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- ─── 3. DETALLE TÉCNICO ─── -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#1e293b">
<td colspan="4" align="center" style="font-size: 9pt; font-weight: bold; color:#ffffff;">3. DETALLE TÉCNICO DE LA FALLA</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td width="22%" style="font-size: 8.5pt;"><b>Sistema Afectado:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ strtoupper($sistema) }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Nivel de Prioridad:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ strtoupper($prioridad) }}</td>
    </tr>
    <tr>
<td colspan="4" style="font-size: 8.5pt;"><b>Descripción Detallada de la Avería:</b><br><br>{{ $falla->DESCRIPCION_AVERIA ?: '—' }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- ─── 4. GESTIÓN DE MANTENIMIENTO ─── -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#1e293b">
<td colspan="4" align="center" style="font-size: 9pt; font-weight: bold; color:#ffffff;">4. GESTIÓN DE MANTENIMIENTO</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td width="22%" style="font-size: 8.5pt;"><b>Tipo de Intervención:</b></td>
<td colspan="3" style="font-size: 8.5pt;">{{ strtoupper($tipoIntv) }}</td>
    </tr>
    <tr>
<td colspan="4" style="font-size: 8.5pt;"><b>Repuestos Estimados:</b><br><br>{{ $falla->REPUESTOS_ESTIMADOS ?: '—' }}</td>
    </tr>
    <tr bgcolor="#f1f5f9">
<td colspan="4" style="font-size: 8.5pt;"><b>Observaciones del Mecánico:</b><br><br>{{ $falla->OBSERVACIONES_MECANICO ?: '—' }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- ─── 5. CIERRE / RESOLUCIÓN ─── -->
@if($falla->ESTADO_REPORTE === 'cerrado')
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#1e293b">
<td colspan="4" align="center" style="font-size: 9pt; font-weight: bold; color:#ffffff;">5. CIERRE DEL REPORTE</td>
    </tr>
    <tr bgcolor="#f1f5f9">
        <td width="22%" style="font-size: 8.5pt;"><b>Fecha de Cierre:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ optional($falla->FECHA_CIERRE)->format('d/m/Y H:i') }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Cerrado por:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->NOMBRE_CIERRA ?? '—' }}</td>
    </tr>
    <tr>
<td colspan="4" style="font-size: 8.5pt;"><b>Observaciones de Cierre:</b><br><br>{{ $falla->OBSERVACIONES_CIERRE ?: '—' }}</td>
    </tr>
</table>
<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="14">&nbsp;</td></tr></table>
@endif

<!-- ─── BLOQUE DE FIRMAS (nobr para que no se parta) ─── -->
<table width="100%" border="0" cellpadding="0" cellspacing="0" nobr="true">
    <tr><td colspan="3" height="20">&nbsp;</td></tr>
    <tr>
        <!-- REPORTADO POR -->
        <td width="45%" align="center" valign="bottom">
            <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                <tr><td align="center" style="font-size: 9pt;"><b>REPORTADO POR:</b></td></tr>
                <tr><td height="30">&nbsp;</td></tr>
                <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($falla->CARGO_REPORTA ?: 'RESPONSABLE') }}</b></td></tr>
                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($falla->NOMBRE_REPORTA ?? '') }}</td></tr>
                <tr><td align="center" style="font-size: 7.5pt; line-height: 1.5; color: #475569;">{{ $falla->EMAIL_REPORTA ?? '' }}</td></tr>
                <tr><td align="center" style="font-size: 7.5pt; line-height: 1.5; color: #475569;">{{ $falla->FECHA_EMISION->format('d/m/Y H:i') }}</td></tr>
            </table>
        </td>

        <td width="10%"></td>

        <!-- AUTORIZA / CIERRE -->
        <td width="45%" align="center" valign="bottom">
            <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                <tr><td align="center" style="font-size: 9pt;"><b>{{ $falla->ESTADO_REPORTE === 'cerrado' ? 'AUTORIZA CIERRE:' : 'AUTORIZA / SUPERVISOR:' }}</b></td></tr>
                <tr><td height="30">&nbsp;</td></tr>
                <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                @if($falla->ESTADO_REPORTE === 'cerrado')
                <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($falla->CARGO_CIERRA ?: 'RESPONSABLE') }}</b></td></tr>
                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($falla->NOMBRE_CIERRA ?? '') }}</td></tr>
                <tr><td align="center" style="font-size: 7.5pt; line-height: 1.5; color: #475569;">{{ optional($falla->FECHA_CIERRE)->format('d/m/Y H:i') }}</td></tr>
                @else
                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                <tr><td height="2">&nbsp;</td></tr>
                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
                <tr><td height="2">&nbsp;</td></tr>
                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Firma: _____________________________</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

</body>
</html>
