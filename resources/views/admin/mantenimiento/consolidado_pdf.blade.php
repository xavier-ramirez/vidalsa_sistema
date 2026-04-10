<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: helvetica; font-size: 10pt; color: #000; margin: 0; padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background-color: #e6f2ff; border: 0.5pt solid #000;
            font-weight: bold; text-align: center; padding: 5px; font-size: 8.5pt;
        }
        .data-table td {
            border: 0.5pt solid #000; padding: 4px 5px; font-size: 8.5pt;
        }
        .data-table tr { page-break-inside: avoid; }
        .section-title {
            font-size: 10pt; font-weight: bold; background-color: #f0f4f8;
            padding: 5px 8px; margin: 10px 0 5px 0; border-left: 3pt solid #0067b1;
        }
        .summary-box {
            border: 0.5pt solid #ccc; padding: 6px 10px; text-align: center;
        }
    </style>
</head>
<body>

    <!-- Company Header -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="left" style="font-size: 8pt; color: #666;">CONSTRUCTORA VIDALSA 27, C.A.</td>
            <td align="right" style="font-size: 8pt; color: #666;">Generado: {{ date('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <table width="100%" border="0"><tr><td height="10">&nbsp;</td></tr></table>

    <!-- Title -->
    <table width="100%" border="0">
        <tr><td align="center" style="font-size: 15pt; font-weight: bold;">CONSOLIDADO NACIONAL DE FALLAS</td></tr>
        <tr><td align="center" style="font-size: 11pt; color: #444;">Fecha: {{ $fecha }}</td></tr>
    </table>

    <table width="100%" border="0"><tr><td height="10">&nbsp;</td></tr></table>

    <!-- Summary -->
    @php
        $totalFallas = $fallas->count();
        $abiertas = $fallas->where('ESTADO_FALLA', 'ABIERTA')->count();
        $resueltas = $fallas->where('ESTADO_FALLA', 'RESUELTA')->count();
        $enProceso = $fallas->where('ESTADO_FALLA', 'EN_PROCESO')->count();
    @endphp

    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td width="25%" class="summary-box">
                <div style="font-size: 8pt; color: #666;">TOTAL FALLAS</div>
                <div style="font-size: 16pt; font-weight: bold;">{{ $totalFallas }}</div>
            </td>
            <td width="25%" class="summary-box" style="background: #fef2f2;">
                <div style="font-size: 8pt; color: #dc2626;">ABIERTAS</div>
                <div style="font-size: 16pt; font-weight: bold; color: #dc2626;">{{ $abiertas }}</div>
            </td>
            <td width="25%" class="summary-box" style="background: #fff7ed;">
                <div style="font-size: 8pt; color: #ea580c;">EN PROCESO</div>
                <div style="font-size: 16pt; font-weight: bold; color: #ea580c;">{{ $enProceso }}</div>
            </td>
            <td width="25%" class="summary-box" style="background: #f0fdf4;">
                <div style="font-size: 8pt; color: #16a34a;">RESUELTAS</div>
                <div style="font-size: 16pt; font-weight: bold; color: #16a34a;">{{ $resueltas }}</div>
            </td>
        </tr>
    </table>

    <table width="100%" border="0"><tr><td height="12">&nbsp;</td></tr></table>

    <!-- By Frente Summary -->
    <div class="section-title">DESGLOSE POR FRENTE DE TRABAJO</div>

    <table width="100%" border="1" cellpadding="4" cellspacing="0" class="data-table">
        <thead>
            <tr bgcolor="#e6f2ff">
                <th width="5%">N°</th>
                <th width="40%">FRENTE</th>
                <th width="15%">TOTAL</th>
                <th width="15%">ABIERTAS</th>
                <th width="15%">RESUELTAS</th>
                <th width="10%">ESTADO</th>
            </tr>
        </thead>
        <tbody>
            @foreach($reportes as $index => $rep)
            @php
                $repAbiertas = $rep->fallas->where('ESTADO_FALLA', 'ABIERTA')->count();
            @endphp
            <tr nobr="true">
                <td align="center">{{ $index + 1 }}</td>
                <td><b>{{ strtoupper($rep->frente->NOMBRE_FRENTE ?? 'N/A') }}</b></td>
                <td align="center" style="font-weight: bold;">{{ $rep->fallas->count() }}</td>
                <td align="center" style="color: {{ $repAbiertas > 0 ? '#dc2626' : '#000' }};">{{ $repAbiertas }}</td>
                <td align="center" style="color: #16a34a;">{{ $rep->fallas->where('ESTADO_FALLA', 'RESUELTA')->count() }}</td>
                <td align="center" style="font-size: 7.5pt;">{{ $rep->ESTADO_REPORTE }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table width="100%" border="0"><tr><td height="12">&nbsp;</td></tr></table>

    <!-- Full Detail -->
    <div class="section-title">DETALLE DE FALLAS</div>

    <table width="100%" border="1" cellpadding="4" cellspacing="0" class="data-table">
        <thead>
            <tr bgcolor="#e6f2ff">
                <th width="4%">N°</th>
                <th width="15%">FRENTE</th>
                <th width="15%">EQUIPO</th>
                <th width="8%">TIPO</th>
                <th width="28%">DESCRIPCIÓN</th>
                <th width="8%">PRIORIDAD</th>
                <th width="8%">ESTADO</th>
                <th width="7%">HORA</th>
                <th width="7%">REGISTRÓ</th>
            </tr>
        </thead>
        <tbody>
            @php $n = 0; @endphp
            @foreach($reportes as $rep)
                @foreach($rep->fallas as $f)
                @php $n++; @endphp
                <tr nobr="true">
                    <td align="center">{{ $n }}</td>
                    <td style="font-size: 7.5pt;">{{ strtoupper($rep->frente->NOMBRE_FRENTE ?? '') }}</td>
                    <td>
                        <b>{{ $f->equipo->tipo->nombre ?? '' }}</b><br>
                        <span style="font-size: 7pt;">{{ $f->equipo->MARCA ?? '' }} {{ $f->equipo->MODELO ?? '' }}</span>
                    </td>
                    <td align="center" style="font-size: 7.5pt;">{{ $f->TIPO_FALLA }}</td>
                    <td>{{ $f->DESCRIPCION_FALLA }}</td>
                    <td align="center" style="font-weight: bold;">{{ $f->PRIORIDAD }}</td>
                    <td align="center" style="font-size: 7.5pt;">{{ str_replace('_', ' ', $f->ESTADO_FALLA) }}</td>
                    <td align="center">{{ $f->HORA_REGISTRO ? $f->HORA_REGISTRO->format('H:i') : '' }}</td>
                    <td style="font-size: 7pt;">{{ $f->usuarioRegistra->NOMBRE_COMPLETO ?? '' }}</td>
                </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <!-- Signature -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0" nobr="true">
        <tr><td height="30">&nbsp;</td></tr>
        <tr>
            <td width="45%" align="center" valign="bottom">
                <table width="100%" border="0" cellpadding="0" cellspacing="0">
                    <tr><td align="center" style="font-size: 9pt;"><b>ELABORADO POR:</b></td></tr>
                    <tr><td height="35">&nbsp;</td></tr>
                    <tr><td><table width="85%" align="center" border="0"><tr><td style="border-top: 0.5pt solid #000;"></td></tr><tr><td align="center" style="font-size: 8.5pt;">Nombre: ___________________________</td></tr><tr><td align="center" style="font-size: 8.5pt;">Cédula: ___________________________</td></tr></table></td></tr>
                </table>
            </td>
            <td width="10%"></td>
            <td width="45%" align="center" valign="bottom">
                <table width="100%" border="0" cellpadding="0" cellspacing="0">
                    <tr><td align="center" style="font-size: 9pt;"><b>APROBADO POR:</b></td></tr>
                    <tr><td height="35">&nbsp;</td></tr>
                    <tr><td><table width="85%" align="center" border="0"><tr><td style="border-top: 0.5pt solid #000;"></td></tr><tr><td align="center" style="font-size: 8.5pt;">Nombre: ___________________________</td></tr><tr><td align="center" style="font-size: 8.5pt;">Cédula: ___________________________</td></tr></table></td></tr>
                </table>
            </td>
        </tr>
    </table>

</body>
</html>
