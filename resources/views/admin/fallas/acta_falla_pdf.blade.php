<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr><td align="right" style="font-size: 9pt;"><b>CÓDIGO REPORTE: {{ $falla->CODIGO_REPORTE }}</b></td></tr>
</table>
<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="20">&nbsp;</td></tr></table>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="font-size: 15pt;"><b>REPORTE DE FALLA</b></td></tr>
</table>
<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- Datos del activo -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#e6f2ff">
        <td colspan="4" align="center" style="font-size: 9pt; font-weight: bold;">IDENTIFICACIÓN DEL ACTIVO</td>
    </tr>
    <tr>
        <td width="22%" style="font-size: 8.5pt;"><b>Tipo de Activo:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->ACTIVO_TIPO === 'equipo' ? 'Vehículo' : 'Equipo Auxiliar' }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Código:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ optional($activo)->CODIGO_PATIO ?? optional($activo)->CODIGO_INTERNO ?? '—' }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Marca:</b></td>
<td style="font-size: 8.5pt;">{{ optional($activo)->MARCA ?? '—' }}</td>
        <td style="font-size: 8.5pt;"><b>Modelo:</b></td>
<td style="font-size: 8.5pt;">{{ optional($activo)->MODELO ?? '—' }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Placa:</b></td>
<td style="font-size: 8.5pt;">{{ $activo && method_exists($activo, 'documentacion') ? optional($activo->documentacion)->PLACA ?? 'S/P' : 'S/P' }}</td>
        <td style="font-size: 8.5pt;"><b>Serial Chasis:</b></td>
<td style="font-size: 8.5pt;">{{ optional($activo)->SERIAL_CHASIS ?? optional($activo)->SERIAL ?? '—' }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Serial Motor:</b></td>
<td style="font-size: 8.5pt;">{{ optional($activo)->SERIAL_DE_MOTOR ?? '—' }}</td>
        <td style="font-size: 8.5pt;"><b>Horómetro/Km:</b></td>
<td style="font-size: 8.5pt;">{{ $falla->HOROMETRO_ACTUAL ?? '—' }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Estado al Reportar:</b></td>
<td style="font-size: 8.5pt;">{{ $falla->ESTADO_AL_CREAR ?? '—' }}</td>
        <td style="font-size: 8.5pt;"><b>Fecha de Emisión:</b></td>
<td style="font-size: 8.5pt;">{{ $falla->FECHA_EMISION->format('d/m/Y H:i') }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="10">&nbsp;</td></tr></table>

<!-- Detalle técnico -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#e6f2ff"><td colspan="4" align="center" style="font-size: 9pt; font-weight: bold;">DETALLE TÉCNICO</td></tr>
    <tr>
        <td width="22%" style="font-size: 8.5pt;"><b>Sistema Afectado:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->SISTEMA_AFECTADO ?? '—' }}</td>
        <td width="22%" style="font-size: 8.5pt;"><b>Prioridad:</b></td>
<td width="28%" style="font-size: 8.5pt;">{{ $falla->PRIORIDAD ?? '—' }}</td>
    </tr>
    <tr>
        <td style="font-size: 8.5pt;"><b>Tipo de Intervención:</b></td>
<td colspan="3" style="font-size: 8.5pt;">{{ $falla->TIPO_INTERVENCION ? str_replace('_', ' ', $falla->TIPO_INTERVENCION) : '—' }}</td>
    </tr>
    <tr>
        <td colspan="4" style="font-size: 8.5pt;"><b>Descripción de la Avería:</b><br><br>{{ $falla->DESCRIPCION_AVERIA ?? '—' }}</td>
    </tr>
    <tr>
        <td colspan="4" style="font-size: 8.5pt;"><b>Repuestos Estimados:</b><br><br>{{ $falla->REPUESTOS_ESTIMADOS ?? '—' }}</td>
    </tr>
    <tr>
        <td colspan="4" style="font-size: 8.5pt;"><b>Observaciones del Mecánico:</b><br><br>{{ $falla->OBSERVACIONES_MECANICO ?? '—' }}</td>
    </tr>
</table>

<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="20">&nbsp;</td></tr></table>

<!-- Validación de personal -->
<table width="100%" border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr bgcolor="#e6f2ff"><td colspan="2" align="center" style="font-size: 9pt; font-weight: bold;">VALIDACIÓN DE PERSONAL</td></tr>
    <tr>
        <td width="50%" align="center" style="font-size: 8.5pt;"><b>REPORTADO POR</b></td>
        <td width="50%" align="center" style="font-size: 8.5pt;"><b>{{ $falla->ESTADO_REPORTE === 'cerrado' ? 'CERRADO POR' : 'PENDIENTE DE CIERRE' }}</b></td>
    </tr>
    <tr>
<td align="center" style="font-size: 8.5pt; line-height: 1.6;">{{ $falla->NOMBRE_REPORTA }}<br><b>{{ $falla->CARGO_REPORTA ?: 'RESPONSABLE' }}</b><br>{{ $falla->EMAIL_REPORTA }}<br>{{ $falla->FECHA_EMISION->format('d/m/Y H:i') }}</td>
<td align="center" style="font-size: 8.5pt; line-height: 1.6;">@if($falla->ESTADO_REPORTE === 'cerrado'){{ $falla->NOMBRE_CIERRA }}<br><b>{{ $falla->CARGO_CIERRA ?: 'RESPONSABLE' }}</b><br>{{ optional($falla->FECHA_CIERRE)->format('d/m/Y H:i') }}@else_______________________<br>_______________________<br>_______________________<br>_______________________@endif</td>
    </tr>
</table>

</body>
</html>
