<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acta de Asignación · Equipo Auxiliar #{{ $auxiliar->ID_AUXILIAR }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 40px; color: #1e293b; background: white; }
        .header { border-bottom: 3px solid #1e293b; padding-bottom: 18px; margin-bottom: 26px; display: flex; align-items: center; gap: 18px; }
        .header img { height: 70px; }
        .header-text h1 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: 0.3px; }
        .header-text p { margin: 4px 0 0 0; font-size: 11px; color: #475569; letter-spacing: 2px; text-transform: uppercase; }
        .title-box { background: #f1f5f9; padding: 14px 18px; border-left: 4px solid #f59e0b; margin: 22px 0; }
        .title-box h2 { margin: 0; font-size: 16px; font-weight: 800; color: #1e293b; }
        .title-box p { margin: 4px 0 0 0; font-size: 11px; color: #64748b; }
        table.info { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 12px; }
        table.info th, table.info td { border: 1px solid #cbd5e1; padding: 8px 12px; vertical-align: top; }
        table.info th { background: #1e293b; color: white; text-align: left; font-weight: 700; width: 180px; letter-spacing: 0.3px; }
        table.info td { background: white; }
        .section-title { font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.6px; margin: 22px 0 8px 0; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }
        .sig-row { display: flex; justify-content: space-between; margin-top: 60px; gap: 40px; }
        .sig-box { flex: 1; text-align: center; }
        .sig-line { border-top: 2px solid #1e293b; margin: 0 20px 6px 20px; }
        .sig-label { font-size: 11px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; color: #475569; }
        .footer { margin-top: 50px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8; text-align: center; }
        @media print {
            body { padding: 20px; }
            .no-print { display: none !important; }
        }
        .print-btn { position: fixed; top: 20px; right: 20px; background: #0067b1; color: white; padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; font-weight: 700; font-size: 13px; box-shadow: 0 4px 10px rgba(0,103,177,0.35); }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">Imprimir Acta</button>

    <div class="header">
        @php $logoPath = public_path('img/imagen_uno.jpg'); @endphp
        @if(file_exists($logoPath))
            <img src="{{ asset('img/imagen_uno.jpg') }}" alt="C.VIDALSA 27">
        @endif
        <div class="header-text">
            <h1>C.VIDALSA 27, C.A.</h1>
            <p>Sistema de Gestión de Equipos Operacionales</p>
        </div>
    </div>

    <div class="title-box">
        <h2>ACTA DE ASIGNACIÓN DE EQUIPO AUXILIAR</h2>
        <p>Documento №: AUX-{{ str_pad($auxiliar->ID_AUXILIAR, 6, '0', STR_PAD_LEFT) }} · Fecha de emisión: {{ now()->format('d/m/Y H:i') }}</p>
    </div>

    <div class="section-title">Datos del Equipo Auxiliar</div>
    @php
        // Usa $tipos dinamico del controller; fallback al enum base.
        $tiposMap = $tipos ?? \App\Models\EquipoAuxiliar::tiposLabel();
        $tipoLabel = $tiposMap[$auxiliar->TIPO] ?? $auxiliar->TIPO;
        $estadoLabel = \App\Models\EquipoAuxiliar::estadosLabel()[$auxiliar->ESTADO_OPERATIVO] ?? $auxiliar->ESTADO_OPERATIVO;
    @endphp
    <table class="info">
        <tr><th>Tipo</th><td>{{ strtoupper($tipoLabel) }}</td></tr>
        <tr><th>Marca / Modelo</th><td>{{ $auxiliar->MARCA }} {{ $auxiliar->MODELO ?: '—' }}</td></tr>
        <tr><th>Serial</th><td>{{ $auxiliar->SERIAL ?: 'N/A' }}</td></tr>
        <tr><th>Código Interno</th><td>{{ $auxiliar->CODIGO_INTERNO ?: 'N/A' }}</td></tr>
        <tr><th>Capacidad</th><td>{{ $auxiliar->CAPACIDAD ?: 'N/A' }}</td></tr>
        <tr><th>Año</th><td>{{ $auxiliar->ANIO ?: 'N/A' }}</td></tr>
        <tr><th>Estado Operativo</th><td>{{ strtoupper($estadoLabel) }}</td></tr>
    </table>

    <div class="section-title">Asignación Actual</div>
    <table class="info">
        <tr><th>Frente de Trabajo</th><td>{{ optional($auxiliar->frente)->NOMBRE_FRENTE ?: 'Sin asignar' }}</td></tr>
        @if($auxiliar->equipoHost)
            @php $host = $auxiliar->equipoHost; @endphp
            <tr><th>Equipo Host (Camión)</th>
                <td>
                    <strong>{{ $host->CODIGO_PATIO ?: $host->SERIAL_CHASIS }}</strong>
                    @if(optional($host->tipo)->nombre) · {{ $host->tipo->nombre }} @endif
                    @if(optional($host->documentacion)->PLACA)
                        · Placa {{ $host->documentacion->PLACA }}
                    @endif
                </td>
            </tr>
            <tr><th>Marca / Modelo Host</th><td>{{ $host->MARCA }} {{ $host->MODELO }}</td></tr>
        @else
            <tr><th>Equipo Host</th><td><em>Sin anclar · auxiliar no montado en ningún equipo</em></td></tr>
        @endif
    </table>

    @if($auxiliar->OBSERVACIONES)
    <div class="section-title">Observaciones</div>
    <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:12px 16px;font-size:12px;color:#78350f;">
        {{ $auxiliar->OBSERVACIONES }}
    </div>
    @endif

    <div class="section-title">Firmas de Conformidad</div>
    <div class="sig-row">
        <div class="sig-box">
            <div style="height: 50px;"></div>
            <div class="sig-line"></div>
            <div class="sig-label">Entrega · Responsable del Activo</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Nombre completo · Cédula</div>
        </div>
        <div class="sig-box">
            <div style="height: 50px;"></div>
            <div class="sig-line"></div>
            <div class="sig-label">Recibe · Operador Asignado</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Nombre completo · Cédula</div>
        </div>
    </div>

    <div class="footer">
        Documento generado automáticamente por el Sistema de Gestión de Equipos Operacionales · C.VIDALSA 27, C.A.
    </div>
</body>
</html>
