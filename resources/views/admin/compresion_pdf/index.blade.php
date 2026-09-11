@extends('layouts.estructura_base')

@section('title', 'Compresión de PDF')

@section('content')
<style>
    /* Como /admin/usuarios: a la izquierda una tarjeta blanca con los FILTROS arriba y la
       tabla debajo; a la derecha, el aviso de la tarea y el resumen, uno debajo del otro. */
    .cpdf-layout { width: 98%; max-width: 1400px; margin: 0 auto; display: flex; gap: 18px; align-items: flex-start; }
    .cpdf-main {
        flex: 1 1 auto; min-width: 0;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
        padding: 16px; box-shadow: 0 4px 6px -1px rgba(15,23,42,0.06);
    }
    .cpdf-side { width: 280px; flex: 0 0 280px; display: flex; flex-direction: column; gap: 10px; }

    .cpdf-filtros { display: flex; flex-wrap: nowrap; gap: 10px; align-items: stretch; margin-bottom: 14px; }
    .cpdf-filtros > .filter-item.responsive-filter-item { flex: 1 1 0 !important; max-width: none !important; min-width: 0; }

    .cpdf-caja { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06); }
    .cpdf-caja small { display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
    .cpdf-caja strong { display: block; font-size: 22px; color: #0f172a; margin-top: 2px; font-variant-numeric: tabular-nums; line-height: 1.2; }
    .cpdf-caja span { display: block; font-size: 12px; color: #64748b; margin-top: 2px; }
    .cpdf-aviso { display: flex; gap: 10px; align-items: flex-start; }
    .cpdf-aviso .material-icons { font-size: 20px; margin-top: 1px; }
    .cpdf-aviso strong { font-size: 13px; margin: 0; line-height: 1.35; }
    .cpdf-aviso span { font-size: 12px; }
    .cpdf-aviso.ok { background: #eff6ff; border-color: #bfdbfe; color: #1e3a5f; }
    .cpdf-aviso.ok strong, .cpdf-aviso.ok span { color: #1e3a5f; }
    .cpdf-aviso.apagada { background: #f8fafc; color: #475569; }

    .cpdf-tabla-caja { overflow-x: auto; }
    .cpdf-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cpdf-tabla thead tr { background: #1e293b; }
    .cpdf-tabla th { text-align: left; font-size: 11.5px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .5px; padding: 9px 10px; white-space: nowrap; }
    .cpdf-tabla th:first-child { border-radius: 8px 0 0 8px; }
    .cpdf-tabla th:last-child { border-radius: 0 8px 8px 0; }
    .cpdf-tabla td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .cpdf-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cpdf-estado { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .cpdf-estado.comprimido { background: #dcfce7; color: #166534; }
    .cpdf-estado.saltado { background: #fef3c7; color: #92400e; cursor: help; }
    .cpdf-estado.error { background: #fee2e2; color: #991b1b; cursor: help; }
    .cpdf-vacio { text-align: center; color: #94a3b8; padding: 30px; }

    @media (max-width: 1024px) {
        .cpdf-layout { flex-direction: column; }
        .cpdf-side { width: 100%; flex-basis: auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
        .cpdf-side .cpdf-aviso { grid-column: 1 / -1; }
        .cpdf-filtros { flex-wrap: wrap; }
    }
    @media (max-width: 768px) {
        .cpdf-filtros > .filter-item.responsive-filter-item { flex: 1 1 100% !important; }
    }
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
    $estados = [
        \App\Models\CompresionPdf::COMPRIMIDO => 'Comprimidos',
        \App\Models\CompresionPdf::SALTADO    => 'Saltados',
        \App\Models\CompresionPdf::ERROR      => 'Con error',
    ];
    // Los dos desplegables son iguales salvo su lista: se pintan con el mismo molde.
    $desplegables = [
        ['id' => 'cpdfEstadoSelect',    'nombre' => 'estado',    'etiqueta' => 'Filtrar Estado...',    'todos' => 'TODOS LOS ESTADOS',
         'valor' => $estado,    'opciones' => $estados],
        ['id' => 'cpdfDocumentoSelect', 'nombre' => 'documento', 'etiqueta' => 'Filtrar Documento...', 'todos' => 'TODOS LOS DOCUMENTOS',
         'valor' => $documento, 'opciones' => $documentos->mapWithKeys(fn ($d) => [$d => $d])->all()],
    ];
@endphp

<div class="cpdf-layout">
    <div class="cpdf-main">
        {{-- Filtros: mismos componentes que Usuarios/Equipos (buscador + custom-dropdown).
             Cada cambio vuelve a pedir la página con los filtros en la URL (cpdfFiltrar). --}}
        <div class="filter-toolbar-container cpdf-filtros">
            <div class="filter-item aligned-filter responsive-filter-item">
                <form style="width: 100%;" onsubmit="event.preventDefault(); window.cpdfFiltrar();">
                    <div class="search-wrapper" style="width: 100%; border-color: {{ $buscar !== '' ? '#0067b1' : '#cbd5e0' }}; background: {{ $buscar !== '' ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
                        <i class="material-icons search-icon">search</i>
                        <input type="text" id="cpdfBuscar" value="{{ $buscar }}"
                            placeholder="Buscar serial o documento..."
                            class="search-input-field" style="height: 100%;" autocomplete="off">
                        <i class="material-icons clear-icon" style="display: {{ $buscar !== '' ? 'block' : 'none' }};"
                           onclick="document.getElementById('cpdfBuscar').value=''; window.cpdfFiltrar();">close</i>
                    </div>
                </form>
            </div>

            @foreach ($desplegables as $dd)
                <div class="filter-item aligned-filter responsive-filter-item">
                    <div class="custom-dropdown" id="{{ $dd['id'] }}" data-filter-type="{{ $dd['nombre'] }}" data-default-label="{{ $dd['etiqueta'] }}" style="width: 100%;">
                        <input type="hidden" name="{{ $dd['nombre'] }}" data-filter-value value="{{ $dd['valor'] }}">
                        <div class="dropdown-trigger {{ $dd['valor'] ? 'filter-active' : '' }}" style="background: {{ $dd['valor'] ? '#e1effa' : '#fbfcfd' }}; border: 1px solid {{ $dd['valor'] ? '#0067b1' : '#cbd5e0' }}; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">
                            <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                placeholder="{{ $dd['valor'] ? $dd['opciones'][$dd['valor']] : $dd['etiqueta'] }}"
                                style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                                onkeyup="window.filterDropdownOptions(this)" autocomplete="off">
                            <div style="display: flex; align-items: center; padding-right: 10px;">
                                <i class="material-icons" data-clear-btn
                                   style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ $dd['valor'] ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); clearDropdownFilter('{{ $dd['id'] }}'); window.cpdfFiltrar();"
                                   title="Limpiar filtro">close</i>
                            </div>
                        </div>
                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                            <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                                <div class="dropdown-item {{ !$dd['valor'] ? 'selected' : '' }}" data-value="all" data-label="{{ $dd['todos'] }}"
                                     onclick="selectOption('{{ $dd['id'] }}', this.dataset.value, this.dataset.label); window.cpdfFiltrar();">{{ $dd['todos'] }}</div>
                                @foreach ($dd['opciones'] as $valor => $texto)
                                    <div class="dropdown-item {{ $dd['valor'] === $valor ? 'selected' : '' }}" data-value="{{ $valor }}" data-label="{{ $texto }}"
                                         onclick="selectOption('{{ $dd['id'] }}', this.dataset.value, this.dataset.label); window.cpdfFiltrar();">{{ $texto }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

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
                            <td style="white-space:nowrap;">{{ $f->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $f->DOCUMENTO }}</td>
                            <td>{{ $f->SERIAL ?? '—' }}</td>
                            <td class="cpdf-num">{{ $mb($f->BYTES_ANTES) }} MB</td>
                            <td class="cpdf-num">{{ $f->BYTES_DESPUES ? $mb($f->BYTES_DESPUES) . ' MB' : '—' }}</td>
                            <td class="cpdf-num">{{ $ahorro }}</td>
                            {{-- Por qué se saltó o falló: al pasar el ratón por el estado. --}}
                            <td><span class="cpdf-estado {{ $f->ESTADO }}" @if ($f->ESTADO !== 'comprimido' && $f->MOTIVO) title="{{ $f->MOTIVO }}" @endif>{{ ucfirst($f->ESTADO) }}</span></td>
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
                        <tr><td colspan="8" class="cpdf-vacio">{{ $estado || $documento || $buscar !== '' ? 'Nada coincide con los filtros.' : 'No hay nada registrado todavía.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $filas->links('vendor.pagination.custom-sliding') }}</div>
    </div>

    <aside class="cpdf-side">
        <div class="cpdf-caja cpdf-aviso {{ $activa && $ghostscript ? 'ok' : 'apagada' }}">
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
        <div class="cpdf-caja">
            <small>Comprimidos</small>
            <strong>{{ $comp->n ?? 0 }}</strong>
            <span>{{ $mb($comp->antes ?? 0) }} MB → {{ $mb($comp->despues ?? 0) }} MB</span>
        </div>
        <div class="cpdf-caja">
            <small>Espacio ahorrado</small>
            <strong>{{ $mb(($comp->antes ?? 0) - ($comp->despues ?? 0)) }} MB</strong>
            <span>en Drive y en cada descarga</span>
        </div>
        <div class="cpdf-caja">
            <small>Saltados</small>
            <strong>{{ $resumen[\App\Models\CompresionPdf::SALTADO]->n ?? 0 }}</strong>
            <span>se dejaron como estaban</span>
        </div>
        <div class="cpdf-caja">
            <small>Con error</small>
            <strong>{{ $resumen[\App\Models\CompresionPdf::ERROR]->n ?? 0 }}</strong>
            <span>se reintentan otra noche</span>
        </div>
        <div class="cpdf-caja">
            <small>Última noche</small>
            <strong style="font-size:16px;">{{ $ultimaNoche ? \Carbon\Carbon::parse($ultimaNoche)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
            <span>tandas de 5, de 12:00 a 5:00 a.m.</span>
        </div>
    </aside>
</div>

<script>
    // Arma la URL con los filtros puestos y la abre por la SPA (sin recargar la página).
    // Se redefine en cada visita: es solo una asignación, no suma listeners.
    window.cpdfFiltrar = function () {
        var p = new URLSearchParams();
        var buscar = (document.getElementById('cpdfBuscar') || {}).value || '';
        if (buscar.trim()) p.set('buscar', buscar.trim());
        [['cpdfEstadoSelect', 'estado'], ['cpdfDocumentoSelect', 'documento']].forEach(function (par) {
            var input = document.querySelector('#' + par[0] + ' [data-filter-value]');
            if (input && input.value && input.value !== 'all') p.set(par[1], input.value);
        });
        var url = @json(route('compresion-pdf.index')) + (p.toString() ? '?' + p.toString() : '');
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    };
</script>
@endsection
