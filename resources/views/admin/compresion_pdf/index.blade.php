@extends('layouts.estructura_base')

@section('title', 'Compresión de PDF')

@section('content')
<style>
    .cpdf-wrap { width: 98%; max-width: 1400px; margin: 0 auto; }
    .cpdf-aviso { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; border: 1px solid; }
    .cpdf-aviso .material-icons { font-size: 22px; margin-top: 1px; }
    .cpdf-aviso strong { display: block; font-size: 14px; }
    .cpdf-aviso span { font-size: 13px; }
    .cpdf-aviso.ok { background: #eff6ff; border-color: #bfdbfe; color: #1e3a5f; }
    .cpdf-aviso.apagada { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
    .cpdf-resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .cpdf-dato { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; }
    .cpdf-dato small { display: block; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }
    .cpdf-dato strong { display: block; font-size: 22px; color: #0f172a; margin-top: 2px; font-variant-numeric: tabular-nums; }
    .cpdf-dato span { font-size: 12px; color: #64748b; }
    .cpdf-filtros { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .cpdf-filtro { padding: 6px 12px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none; }
    .cpdf-filtro.activo { background: #1e3a5f; border-color: #1e3a5f; color: #fff; }
    .cpdf-tabla-caja { overflow-x: auto; }
    .cpdf-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cpdf-tabla th { text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; padding: 8px 10px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .cpdf-tabla td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .cpdf-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cpdf-estado { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .cpdf-estado.comprimido { background: #dcfce7; color: #166534; }
    .cpdf-estado.saltado { background: #fef3c7; color: #92400e; }
    .cpdf-estado.error { background: #fee2e2; color: #991b1b; }
    .cpdf-motivo { color: #64748b; font-size: 12px; }
    .cpdf-vacio { text-align: center; color: #94a3b8; padding: 30px; }
</style>

@include('admin.partials.page_header', [
    'titulo'  => 'Compresión de PDF',
    'align'   => 'left',
    'margin'  => '0 auto 16px auto',
    'padding' => '0',
    'extra'   => 'width:98%;max-width:1400px;',
])

@php
    $mb = fn ($b) => number_format(($b ?? 0) / 1048576, 1, ',', '.');
    $comp = $resumen[\App\Models\CompresionPdf::COMPRIMIDO] ?? null;
@endphp

<div class="cpdf-wrap">
    <div class="cpdf-aviso {{ $activa && $ghostscript ? 'ok' : 'apagada' }}">
        <i class="material-icons">{{ $activa && $ghostscript ? 'nights_stay' : 'block' }}</i>
        <div>
            @if ($activa && $ghostscript)
                <strong>Tarea nocturna activa en este servidor</strong>
                <span>{{ ucfirst($motivoActiva) }}. Cada noche, de 12:00 a 5:00 a.m. (hora {{ $zona }}; ahora son las {{ $horaApp->format('g:i a') }}), comprime de 5 en 5 con un minuto de descanso entre lotes.</span>
            @elseif (!$activa)
                <strong>La tarea nocturna no corre en este equipo</strong>
                <span>{{ ucfirst($motivoActiva) }}. Solo el servidor cambia documentos.</span>
            @else
                <strong>Falta Ghostscript</strong>
                <span>La tarea esta activada pero sin Ghostscript no puede comprimir (se instala en el Dockerfile).</span>
            @endif
        </div>
    </div>

    <div class="cpdf-resumen">
        <div class="cpdf-dato">
            <small>Comprimidos</small>
            <strong>{{ $comp->n ?? 0 }}</strong>
            <span>{{ $mb($comp->antes ?? 0) }} MB → {{ $mb($comp->despues ?? 0) }} MB</span>
        </div>
        <div class="cpdf-dato">
            <small>Espacio ahorrado</small>
            <strong>{{ $mb(($comp->antes ?? 0) - ($comp->despues ?? 0)) }} MB</strong>
            <span>en Drive y en cada descarga</span>
        </div>
        <div class="cpdf-dato">
            <small>Saltados</small>
            <strong>{{ $resumen[\App\Models\CompresionPdf::SALTADO]->n ?? 0 }}</strong>
            <span>se dejaron como estaban</span>
        </div>
        <div class="cpdf-dato">
            <small>Con error</small>
            <strong>{{ $resumen[\App\Models\CompresionPdf::ERROR]->n ?? 0 }}</strong>
            <span>se reintentan otra noche</span>
        </div>
        <div class="cpdf-dato">
            <small>Última noche</small>
            <strong style="font-size:16px;">{{ $ultimaNoche ? \Carbon\Carbon::parse($ultimaNoche)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
            <span>tandas de 5, de 12:00 a 5:00 a.m.</span>
        </div>
    </div>

    <div class="cpdf-filtros">
        <a class="cpdf-filtro {{ !$estado ? 'activo' : '' }}" href="{{ route('compresion-pdf.index') }}">Todos</a>
        <a class="cpdf-filtro {{ $estado === 'comprimido' ? 'activo' : '' }}" href="{{ route('compresion-pdf.index', ['estado' => 'comprimido']) }}">Comprimidos</a>
        <a class="cpdf-filtro {{ $estado === 'saltado' ? 'activo' : '' }}" href="{{ route('compresion-pdf.index', ['estado' => 'saltado']) }}">Saltados</a>
        <a class="cpdf-filtro {{ $estado === 'error' ? 'activo' : '' }}" href="{{ route('compresion-pdf.index', ['estado' => 'error']) }}">Con error</a>
    </div>

    <div class="admin-card">
        <div class="cpdf-tabla-caja">
            <table class="cpdf-tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Documento</th>
                        <th>Serial</th>
                        <th class="cpdf-num">Antes</th>
                        <th class="cpdf-num">Después</th>
                        <th class="cpdf-num">Ahorro</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $f)
                        @php
                            // El que se abre es el que usa ahora el documento: el nuevo si se comprimio.
                            $vigente = $f->ESTADO === 'comprimido' ? $f->DRIVE_ID_NUEVO : $f->DRIVE_ID_VIEJO;
                            $ahorro  = ($f->BYTES_ANTES && $f->BYTES_DESPUES && $f->ESTADO === 'comprimido')
                                ? round(100 * (1 - $f->BYTES_DESPUES / $f->BYTES_ANTES)) . ' %' : '—';
                        @endphp
                        <tr>
                            <td style="white-space:nowrap;">{{ $f->created_at?->format('d/m/Y H:i') }}{{ $f->ORIGEN === 'manual' ? ' · a mano' : '' }}</td>
                            <td>{{ $f->DOCUMENTO }}</td>
                            <td>{{ $f->SERIAL ?? '—' }}</td>
                            <td class="cpdf-num">{{ $mb($f->BYTES_ANTES) }} MB</td>
                            <td class="cpdf-num">{{ $f->BYTES_DESPUES ? $mb($f->BYTES_DESPUES) . ' MB' : '—' }}</td>
                            <td class="cpdf-num">{{ $ahorro }}</td>
                            <td><span class="cpdf-estado {{ $f->ESTADO }}">{{ ucfirst($f->ESTADO) }}</span></td>
                            <td class="cpdf-motivo">{{ $f->MOTIVO }}</td>
                            <td>
                                @if ($vigente)
                                    <button type="button" class="pdf-doc-btn" title="Ver PDF"
                                        onclick="window.openPdfPreview('/storage/google/{{ $vigente }}', 'compresion', @js($f->DOCUMENTO . ' ' . ($f->SERIAL ?? '')), 0, '', true)">
                                        <i class="material-icons">description</i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="cpdf-vacio">No hay nada registrado todavía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $filas->links('vendor.pagination.custom-sliding') }}</div>
    </div>
</div>
@endsection
