<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { 
            font-family: helvetica; 
            font-size: 10.5pt; 
            color: #000;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        table { 
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td {
            vertical-align: top;
        }
        .report-title {
            text-align: center;
            font-size: 12pt; 
            font-weight: bold;
            margin-top: 5px; /* Reducido de 20px a 5px */
            margin-bottom: 5px;
        }
        .section-header {
            background-color: #f2f2f2;
            font-weight: bold;
            font-size: 10.5pt;
            padding: 4px 8px; /* Reducido de 8px a 4px vertical */
        }
        .data-table th {
            background-color: #e6f2ff;
            border: 0.5pt solid #000; 
            font-weight: bold;
            text-align: center;
            padding: 5px;
            font-size: 7.5pt;
        }
        .data-table td {
            border: 0.5pt solid #000; 
            text-align: center;
            padding: 5px;
            font-size: 7.5pt;
        }
        .intro-text {
            text-align: justify;
            text-justify: inter-word;
            text-indent: 0;
            margin: 0;
            padding: 0;
            width: 100%;
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <!-- HEADER (Ahora manejado nativamente por TCPDF) -->

    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 5mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

    <div class="report-title">REPORTE DE DOCUMENTACIÓN EXPIRADA O A RENOVAR</div>
    
    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 5mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

    <div class="intro-text">El presente reporte, emitido por el <strong>Sistema de Gestión de Flota</strong>, tiene como propósito informar sobre el estatus legal de la documentación técnica (pólizas, RACDA, ROTC, certificados) de los vehículos y maquinaria pesada y liviana de la empresa.</div>

    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 4mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="left">
                <strong>RESUMEN DE ALERTAS</strong><br>
                Equipos evaluados: {{ $totalEquipos }} |
                Vencidos: {{ $totalVencidos }} |
                Por vencer: {{ $totalProximos }}
            </td>
        </tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 4mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

    @if(count($vencidos) > 0)
        <table class="section-header" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="padding: 15px 8px;">DOCUMENTOS VENCIDOS</td>
            </tr>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 3.5mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

        <table class="data-table" cellpadding="4">
            <thead>
                <tr>
                    <th width="5%">N°</th>
                    <th width="23%">FRENTE</th>
                    <th width="18%">TIPO</th>
                    <th width="23%">SERIAL / PLACA</th>
                    <th width="19%">DOCUMENTO</th>
                    <th width="12%">VENCE</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vencidos as $index => $alerta)
                {{-- nobr="true": atributo NATIVO de TCPDF para que la fila NO se parta entre
                     páginas (TCPDF ignora el CSS page-break-inside:avoid en filas de tabla). --}}
                <tr nobr="true">
                    <td width="5%">{{ $index + 1 }}</td>
                    <td width="23%">{{ $alerta->equipo->frenteActual?->NOMBRE_FRENTE ?? 'N/A' }}</td>
                    <td width="18%">{{ $alerta->equipo->tipo->nombre ?? 'N/A' }}</td>
                    {{-- Campo unificado: serial de chasis si existe; si no, la placa; si no hay ninguno, '---'. --}}
                    <td width="23%">{{ ($alerta->equipo->SERIAL_CHASIS ?: $alerta->equipo->documentacion?->PLACA) ?: '---' }}</td>
                    <td width="19%">{{ mb_strtoupper($alerta->label, 'UTF-8') }}</td>
                    <td width="12%">{{ \Carbon\Carbon::parse($alerta->fecha)->format('d/m/Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(count($proximos) > 0)
        {{-- PRÓXIMOS empieza en PÁGINA NUEVA cuando hubo vencidos: así su encabezado no
             queda al pie de una hoja con la primera fila cortada (el problema reportado).
             Usamos el salto NATIVO de TCPDF (<br pagebreak="true">), no CSS. Si no hubo
             vencidos, no forzamos el salto (evita una primera hoja en blanco) y dejamos
             el espaciador para el aire de arriba. --}}
        @if(count($vencidos) > 0)
            <br pagebreak="true"/>
        @else
            <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 15mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>
        @endif
        <table class="section-header" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="padding: 15px 8px;">PRÓXIMOS A VENCER (30 DÍAS)</td>
            </tr>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="height: 3.5mm; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>

        <table class="data-table" cellpadding="4">
            <thead>
                <tr>
                    <th width="5%">N°</th>
                    <th width="23%">FRENTE</th>
                    <th width="18%">TIPO</th>
                    <th width="23%">SERIAL / PLACA</th>
                    <th width="19%">DOCUMENTO</th>
                    <th width="12%">VENCE</th>
                </tr>
            </thead>
            <tbody>
                @foreach($proximos as $index => $alerta)
                {{-- nobr="true": atributo NATIVO de TCPDF para que la fila NO se parta entre
                     páginas (TCPDF ignora el CSS page-break-inside:avoid en filas de tabla). --}}
                <tr nobr="true">
                    <td width="5%">{{ $index + 1 }}</td>
                    <td width="23%">{{ $alerta->equipo->frenteActual?->NOMBRE_FRENTE ?? 'N/A' }}</td>
                    <td width="18%">{{ $alerta->equipo->tipo->nombre ?? 'N/A' }}</td>
                    {{-- Campo unificado: serial de chasis si existe; si no, la placa; si no hay ninguno, '---'. --}}
                    <td width="23%">{{ ($alerta->equipo->SERIAL_CHASIS ?: $alerta->equipo->documentacion?->PLACA) ?: '---' }}</td>
                    <td width="19%">{{ mb_strtoupper($alerta->label, 'UTF-8') }}</td>
                    <td width="12%">{{ \Carbon\Carbon::parse($alerta->fecha)->format('d/m/Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    
    @if(count($vencidos) == 0 && count($proximos) == 0)
        <div style="text-align: center; color: #555; font-weight: bold; margin-top: 30px;">
            -- NO SE ENCONTRARON DOCUMENTOS VENCIDOS O PRÓXIMOS A VENCER --
        </div>
    @endif

</body>
</html>
