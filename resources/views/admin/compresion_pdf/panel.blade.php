{{-- Panel de "Compresión de PDF" y "Documentos": las dos pestañas que viven DENTRO
     de Control de Auditoría (/admin/historial-documentos). Vive aparte para que esa pantalla
     lo incluya sin repetir su tabla, sus filtros ni su resumen.
     Los datos los arma App\Support\PanelDocumentos::datos(); quien escribe en la ficha, tanto
     desde el visor como desde la tarea de la mañana, es App\Services\CorrectorFichaDocumento. --}}
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

    /* Tarjeta grande de arriba: el MISMO molde que la de "Total Auditoría" del Historial,
       para que las tres pestañas abran con la misma pieza. */
    .cpdf-hero { display: flex; align-items: center; gap: 12px; background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%);
                 border-radius: 12px; padding: 15px; color: #fff; box-shadow: 0 4px 6px -1px rgba(15,23,42,.10); }
    .cpdf-hero .material-icons { font-size: 26px; opacity: .85; }
    .cpdf-hero small { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; opacity: .9; }
    .cpdf-hero strong { display: block; font-size: 28px; font-weight: 800; line-height: 1.1; }
    .cpdf-hero span { display: block; font-size: 12px; opacity: .85; }
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
    /* Tarjetas que filtran la lista: son enlaces, pero se ven igual que las demas cajas. */
    a.cpdf-filtra { text-decoration: none; transition: border-color .15s, transform .15s; }
    a.cpdf-filtra:hover { border-color: #6d28d9; transform: translateY(-1px); }
    .cpdf-avance small { margin-bottom: 8px; }
    /* Dos lineas por documento: el nombre entero con su cifra, y debajo la barra. En la
       columna lateral (280 px) el nombre no cabe al lado de la barra sin recortarse. */
    .cpdf-avance-fila { display: grid; grid-template-columns: 1fr auto; align-items: center;
                        column-gap: 8px; text-decoration: none; padding: 4px 0; }
    .cpdf-avance-nombre { font-size: 12px; font-weight: 700; color: #334155; }
    .cpdf-avance-barra { grid-column: 1 / -1; margin-top: 3px; }
    .cpdf-avance-barra { height: 7px; border-radius: 99px; background: #e2e8f0; overflow: hidden; }
    .cpdf-avance-barra i { display: block; height: 100%; border-radius: 99px; background: #6d28d9; }
    .cpdf-avance-cifra { font-size: 11px; font-weight: 700; color: #64748b; font-variant-numeric: tabular-nums; }
    .cpdf-avance-listo { color: #15803d; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
    /* Revisar a mano en el visor: bajo cada campo, lo que dice el documento (panel oscuro). */
    .cpdf-pista { margin-top: 4px; font-size: 11.5px; color: #cbd5e0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .cpdf-pista b { color: #fde68a; font-weight: 700; word-break: break-word; }
    .cpdf-pista button { margin-left: auto; background: #2563eb; color: #fff; border: 0; border-radius: 4px; padding: 2px 9px; font-size: 11px; cursor: pointer; }
    .cpdf-pista-aviso { margin-bottom: 12px; padding: 8px 10px; border-radius: 6px; background: rgba(37,99,235,.15);
                        border: 1px solid rgba(96,165,250,.4); color: #dbeafe; font-size: 12px; line-height: 1.35; }
    .cpdf-concl { font-size: 12.5px; }
    .cpdf-concl b { color: #fff; }
    .cpdf-concl-motivo { margin-top: 3px; color: #fde68a; }
    .cpdf-concl-dif { margin-top: 3px; }
    .cpdf-concl-dif b { color: #fde68a; }
    .cpdf-extra { margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(96,165,250,.3); }
    .cpdf-extra input[type="date"], .cpdf-extra input[type="text"] { width: 100%; box-sizing: border-box; margin: 4px 0; height: 30px;
        padding: 4px 8px; border-radius: 4px; border: 1px solid #555; background: #282828; color: #fff; font-size: 12.5px; }
    .cpdf-extra label { display: flex; align-items: center; gap: 6px; cursor: pointer; }

    /* La tabla es la MISMA de Control de Auditoría, Usuarios y Equipos (.admin-table), con
       menos relleno: estas dos listas llevan más columnas y muchas filas. */
    .cpdf-tabla-caja { overflow-x: auto; }
    .cpdf-tabla-caja .admin-table { border-spacing: 0 8px; }
    .cpdf-tabla-caja .admin-table tr td { padding: 9px 12px; }
    /* Las columnas de datos sueltos no parten; la de las diferencias se queda con el resto
       del ancho, que es donde de verdad hay que leer. */
    .cpdf-tabla-caja .admin-table td:not(.cpdf-ancha) { white-space: nowrap; width: 1%; }
    .cpdf-tabla-caja .admin-table td.cpdf-ancha { width: 100%; white-space: normal; }
    .cpdf-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .tabla-lista th.cpdf-num { text-align: right; }   /* le gana al text-align: left de .tabla-cabecera th */
    .cpdf-estado { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .cpdf-estado.comprimido { background: #dcfce7; color: #166534; }
    .cpdf-estado.saltado { background: #fef3c7; color: #92400e; cursor: help; }
    .cpdf-estado.error { background: #fee2e2; color: #991b1b; cursor: help; }
    .cpdf-vacio { text-align: center; color: #94a3b8; padding: 30px; }

    .cpdf-estado.coincide { background: #dcfce7; color: #166534; }
    .cpdf-estado.difiere { background: #fee2e2; color: #991b1b; cursor: help; }
    .cpdf-estado.ilegible, .cpdf-estado.sin_archivo { background: #fef3c7; color: #92400e; cursor: help; }
    /* Cada dato que no cuadra, en una linea: etiqueta, lo de la ficha (tachado) y lo del documento. */
    .cpdf-nom { font-size: 12.5px; color: #0f172a; }
    /* Casilla para dar filas por revisadas sin abrir el visor (barra: .selection-floating-bar). */
    .cpdf-tabla-caja .cpdf-sel { width: 1%; padding-right: 0 !important; }
    .cpdf-sel input { width: 16px; height: 16px; cursor: pointer; accent-color: #6d28d9; vertical-align: middle; }
    .cpdf-nom.mal { color: #991b1b; text-decoration: line-through; }
    .cpdf-dif { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; font-size: 12.5px; line-height: 1.5; }
    .cpdf-dif-eti { font-weight: 700; color: #64748b; }
    .cpdf-dif-doc { color: #166534; font-weight: 600; }
    .cpdf-flecha { font-size: 14px; color: #94a3b8; }
                    border-radius: 8px; padding: 4px 9px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }

    /* Angosto: una columna. stretch y no flex-start: con flex-start la tarjeta tomaba el
       ancho de la TABLA (816 px en un teléfono de 390) y la página se salía por la derecha;
       así toma el de la pantalla y la tabla se desplaza dentro de .cpdf-tabla-caja. El
       resumen va ARRIBA (order): debajo quedaba después de 50 filas. */
    @media (max-width: 1024px) {
        .cpdf-layout { flex-direction: column; align-items: stretch; }
        .cpdf-side { order: -1; width: 100%; flex-basis: auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .cpdf-side .cpdf-aviso { grid-column: 1 / -1; }
        .cpdf-filtros { flex-wrap: wrap; }
    }
    @media (max-width: 768px) {
        .cpdf-filtros > .filter-item.responsive-filter-item { flex: 1 1 100% !important; }
    }
</style>


@php
    $mb = fn ($b) => number_format(($b ?? 0) / 1048576, 1, ',', '.');
    $comp = $resumen[\App\Models\CompresionPdf::COMPRIMIDO] ?? null;
    $estados = [
        \App\Models\CompresionPdf::COMPRIMIDO => 'Comprimidos',
        \App\Models\CompresionPdf::SALTADO    => 'Saltados',
        \App\Models\CompresionPdf::ERROR      => 'Con error',
    ];
    $estadosDoc = [
        'revisar'                 => 'PARA REVISAR (no se pudo leer)',
        // El mismo monton que cuenta la tarjeta "Sin aplicar". Tiene que estar en esta lista:
        // el desplegable saca de aqui el nombre de lo filtrado y sin el la pantalla reventaba
        // al pulsar la tarjeta (clave inexistente).
        'corregibles'             => 'SIN APLICAR (la tarea aún no las puso)',
        \App\Models\VerificacionDocumento::DIFIERE     => 'Datos distintos',
        \App\Models\VerificacionDocumento::COINCIDE    => 'Coincide',
        \App\Models\VerificacionDocumento::ILEGIBLE    => 'No se pudo leer',
        \App\Models\VerificacionDocumento::SIN_ARCHIVO => 'Sin archivo en Drive',
        \App\Models\VerificacionDocumento::ERROR       => 'Con error',
    ];
    $tiposDoc = \App\Models\VerificacionDocumento::NOMBRES;
    // Los desplegables son iguales salvo su lista: se pintan con el mismo molde. Cada pestaña
    // filtra por lo suyo (la de compresion, por documento; la de documentos, por estado y tipo).
    $desplegables = $pestana === 'documentos' ? [
        ['id' => 'cpdfDocEstadoSelect', 'nombre' => 'estado_doc', 'etiqueta' => 'Filtrar Estado...', 'todos' => 'TODOS LOS ESTADOS',
         'valor' => $estadoDoc, 'opciones' => $estadosDoc],
        ['id' => 'cpdfDocTipoSelect', 'nombre' => 'tipo_doc', 'etiqueta' => 'Filtrar Documento...', 'todos' => 'TODOS LOS DOCUMENTOS',
         'valor' => $tipoDoc, 'opciones' => $tiposDoc],
    ] : [
        ['id' => 'cpdfEstadoSelect',    'nombre' => 'estado',    'etiqueta' => 'Filtrar Estado...',    'todos' => 'TODOS LOS ESTADOS',
         'valor' => $estado,    'opciones' => $estados],
        ['id' => 'cpdfDocumentoSelect', 'nombre' => 'documento', 'etiqueta' => 'Filtrar Documento...', 'todos' => 'TODOS LOS DOCUMENTOS',
         'valor' => $documento, 'opciones' => $documentos->mapWithKeys(fn ($d) => [$d => $d])->all()],
    ];
@endphp

<div class="cpdf-layout">
    <div class="cpdf-main">
        @foreach (['success' => '#dcfce7', 'error' => '#fee2e2'] as $tipo => $fondo)
            @if (session($tipo))
                <div style="background:{{ $fondo }};border-radius:10px;padding:9px 12px;margin-bottom:12px;font-size:13px;font-weight:600;color:#0f172a;">{{ session($tipo) }}</div>
            @endif
        @endforeach

        {{-- Filtros: mismos componentes que Usuarios/Equipos (buscador + custom-dropdown).
             Cada cambio vuelve a pedir la página con los filtros en la URL (cpdfFiltrar). --}}
        <div class="filter-toolbar-container cpdf-filtros">
            <div class="filter-item aligned-filter responsive-filter-item">
                <form style="width: 100%;" onsubmit="event.preventDefault(); window.cpdfFiltrar();">
                    <div class="search-wrapper" style="width: 100%; border-color: {{ $buscar !== '' ? '#0067b1' : '#cbd5e0' }}; background: {{ $buscar !== '' ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
                        <i class="material-icons search-icon">search</i>
                        <input type="text" id="cpdfBuscar" value="{{ $buscar }}"
                            placeholder="{{ $pestana === 'documentos' ? 'Buscar placa, serial o nombre...' : 'Buscar serial o documento...' }}"
                            class="search-input-field" style="height: 100%;" autocomplete="off"
                            oninput="document.getElementById('cpdfBuscarX').style.display = this.value ? 'block' : 'none';">
                        <i id="cpdfBuscarX" class="material-icons clear-icon" style="display: {{ $buscar !== '' ? 'block' : 'none' }};"
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

        @if ($pestana === 'documentos')
        <div class="cpdf-tabla-caja">
            <table class="admin-table">
                <thead>
                    <tr class="tabla-cabecera">
                        <th class="cpdf-sel"><input type="checkbox" id="cpdfSelTodas" title="Marcar todas las de esta página"
                            onchange="window.cpdfSelTodas(this.checked)"></th>
                        <th>Fecha</th>
                        <th>Documento</th>
                        <th>Placa / Serial</th>
                        <th>Qué dice la ficha y qué dice el documento</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($docs as $d)
                        <tr>
                            {{-- Las que ya coinciden no tienen nada que revisar. --}}
                            <td class="cpdf-sel">
                                @if ($d->ESTADO !== \App\Models\VerificacionDocumento::COINCIDE)
                                    <input type="checkbox" class="cpdf-sel-fila" value="{{ $d->ID_REGISTRO }}" onchange="window.cpdfSelContar()">
                                @endif
                            </td>
                            <td style="white-space:nowrap;">{{ $d->updated_at?->format('d/m/Y H:i') }}</td>
                            <td style="white-space:nowrap;">{{ $tiposDoc[$d->TIPO] ?? $d->TIPO }}</td>
                            <td style="white-space:nowrap;">
                                {{ $d->PLACA ?: '—' }}
                                @if ($d->SERIAL) <small style="display:block;color:#64748b;">{{ $d->SERIAL }}</small> @endif
                            </td>
                            {{-- Solo lo que NO cuadra: a la izquierda lo de la ficha (tachado), a la
                                 derecha lo que dice el PDF. Si todo cuadra, lo leido en gris. --}}
                            <td class="cpdf-ancha">
                                @forelse ($d->DIFERENCIAS ?? [] as $campo => $dif)
                                    <div class="cpdf-dif">
                                        <span class="cpdf-dif-eti">{{ $dif['etiqueta'] }}:</span>
                                        <span class="cpdf-nom mal">{{ $dif['ficha'] ?: '(vacío)' }}</span>
                                        <i class="material-icons cpdf-flecha">arrow_forward</i>
                                        <span class="cpdf-dif-doc">{{ $dif['documento'] }}</span>
                                    </div>
                                @empty
                                    <span style="font-size:12px;color:#64748b;">{{ $d->MOTIVO ?: 'Todo coincide con el documento' }}</span>
                                @endforelse
                            </td>
                            <td><span class="cpdf-estado {{ $d->ESTADO }}" @if ($d->MOTIVO) title="{{ $d->MOTIVO }}" @endif>{{ $estadosDoc[$d->ESTADO] ?? $d->ESTADO }}</span></td>
                            <td style="white-space:nowrap;">
                                @if ($d->DRIVE_ID)
                                    {{-- Abre el PDF con los campos de la ficha para revisarla (ver cpdfRevisar). --}}
                                    <button type="button" class="pdf-doc-btn" title="Ver el documento y revisar la ficha"
                                        onclick="window.cpdfRevisar(@js(['id' => $d->ID_REGISTRO, 'equipoId' => (int) $d->ID_EQUIPO, 'tipo' => $d->TIPO, 'dif' => (object) ($d->DIFERENCIAS ?? []), 'estado' => $estadosDoc[$d->ESTADO] ?? $d->ESTADO, 'motivo' => $d->MOTIVO, 'fiable' => !($d->esLecturaParcial() || $d->sinConfirmar() || $d->esDeOtroVehiculo() || $d->esDocumentoAnterior())]), '/storage/google/{{ $d->DRIVE_ID }}', @js(($tiposDoc[$d->TIPO] ?? '') . ' ' . ($d->PLACA ?: $d->SERIAL ?: '')))">
                                        <i class="material-icons">description</i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="cpdf-vacio">{{ $estadoDoc || $tipoDoc || $buscar !== '' ? 'Nada coincide con los filtros.' : 'Todavía no se ha revisado ningún documento.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $docs->links('vendor.pagination.custom-sliding') }}</div>
        {{-- Dar por revisadas las filas marcadas, sin abrir el visor (ver cpdfMarcarRevisadas). --}}
        <div id="cpdfSelBarra" class="selection-floating-bar">
            <div class="selection-counter">
                <i class="material-icons" style="font-size:18px;">fact_check</i>
                <span id="cpdfSelCuenta">0</span>
            </div>
            <div style="width:1px;height:24px;background:rgba(255,255,255,0.2);"></div>
            <button type="button" class="btn-bulk-clear" onclick="window.cpdfSelTodas(false)">Limpiar</button>
            <button type="button" class="btn-bulk-action" onclick="window.cpdfMarcarRevisadas()">
                <i class="material-icons">done_all</i> Marcar como revisadas
            </button>
        </div>
        @else
        <div class="cpdf-tabla-caja">
            <table class="admin-table">
                <thead>
                    <tr class="tabla-cabecera">
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
        @endif
    </div>

    <aside class="cpdf-side">
        @if ($pestana === 'documentos')
            <div class="cpdf-hero">
                <i class="material-icons">fact_check</i>
                <div>
                    <small>Documentos leídos</small>
                    <strong>{{ $resumenDocs->sum() }}</strong>
                    <span>títulos, pólizas, ROTC y RACDA, incluidos los que no se pudieron leer</span>
                </div>
            </div>
            <div class="cpdf-caja cpdf-aviso {{ $activa ? 'ok' : 'apagada' }}">
                <i class="material-icons">{{ $activa ? 'schedule' : 'block' }}</i>
                <div>
                    @if ($activa)
                        <strong>Lectura automática activa</strong>
                        <span>De 9:05 a.m. a 1:05 p.m., hora {{ $zona === 'America/Caracas' ? 'de Venezuela' : $zona }} (ahora {{ $horaApp->format('g:i a') }}). No se cruza con la compresión. Pone en la ficha lo que dice el documento; nunca la placa ni el serial.</span>
                    @else
                        <strong>Lectura automática apagada</strong>
                        <span>{{ ucfirst($motivoActiva) }}.</span>
                    @endif
                </div>
            </div>
            {{-- Cada tarjeta filtra la lista de abajo: se pulsa y se ve QUE filas son. --}}
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => \App\Models\VerificacionDocumento::COINCIDE, 'page' => null]) }}">
                <small>Coinciden</small>
                <strong>{{ $resumenDocs[\App\Models\VerificacionDocumento::COINCIDE] ?? 0 }}</strong>
                <span>la ficha dice lo mismo que el documento</span>
            </a>
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => 'corregibles', 'page' => null]) }}">
                <small>Sin aplicar</small>
                <strong>{{ $docsCorregibles }}</strong>
                <span>la tarea aún no las puso: se revisan en el visor</span>
            </a>
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => 'revisar', 'page' => null]) }}">
                <small>Para revisar a mano</small>
                <strong>{{ $docsParaRevisar }}</strong>
                <span>ilegibles, de otro vehículo o sin archivo</span>
            </a>
            <div class="cpdf-caja">
                <small>Faltan por leer</small>
                <strong>{{ $pendientesDocs }}</strong>
                <span>{{ $pendientesDocs ? 'se leen de 9:05 a.m. a 1:05 p.m.' : 'ya se leyeron todos los documentos cargados' }}</span>
            </div>
            <div class="cpdf-caja">
                <small>Última lectura</small>
                <strong style="font-size:16px;">{{ $ultimaLectura ? \Carbon\Carbon::parse($ultimaLectura)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
                <span>de los cuatro documentos</span>
            </div>

            {{-- Por donde va cada documento. Cuando los cuatro digan "listo", termino. --}}
            <div class="cpdf-caja cpdf-avance">
                <small>Por dónde va la revisión</small>
                @foreach ($avanceDocs as $tipo => $a)
                    <a class="cpdf-avance-fila" href="{{ request()->fullUrlWithQuery(['tipo_doc' => $tipo, 'estado_doc' => null, 'page' => null]) }}">
                        <span class="cpdf-avance-nombre">{{ $a['nombre'] }}</span>
                        <span class="cpdf-avance-barra"><i style="width: {{ $a['total'] ? round($a['leidos'] * 100 / $a['total']) : 100 }}%;"></i></span>
                        <span class="cpdf-avance-cifra">
                            {{ number_format($a['leidos'], 0, ',', '.') }}/{{ number_format($a['total'], 0, ',', '.') }}
                            @if (!$a['faltan'] && $a['total']) <b class="cpdf-avance-listo">listo</b> @endif
                        </span>
                    </a>
                @endforeach
            </div>
        @else
        <div class="cpdf-hero">
            <i class="material-icons">compress</i>
            <div>
                <small>PDF comprimidos</small>
                <strong>{{ $comp->n ?? 0 }}</strong>
                <span>{{ $mb(($comp->antes ?? 0) - ($comp->despues ?? 0)) }} MB ahorrados en Drive</span>
            </div>
        </div>
        <div class="cpdf-caja cpdf-aviso {{ $activa && $ghostscript ? 'ok' : 'apagada' }}">
            <i class="material-icons">{{ $activa && $ghostscript ? 'nights_stay' : 'block' }}</i>
            <div>
                {{-- Corto a propósito. La hora de la app va para comprobar de un vistazo que
                     "las 12" son las de Venezuela; si la zona fuera otra, se nombra. --}}
                @if ($activa && $ghostscript)
                    <strong>Tarea nocturna activa</strong>
                    <span>De 5:00 a 6:30 a.m., hora {{ $zona === 'America/Caracas' ? 'de Venezuela' : $zona }} (ahora {{ $horaApp->format('g:i a') }}). Si no queda nada por comprimir, no hace nada.</span>
                @elseif (!$activa)
                    <strong>Tarea nocturna apagada</strong>
                    <span>{{ ucfirst($motivoActiva) }}.</span>
                @else
                    <strong>Falta Ghostscript</strong>
                    <span>Sin él no puede comprimir (se instala en el Dockerfile).</span>
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
            <span>tandas de 5, de 5:00 a 6:30 a.m.</span>
        </div>
        @endif
    </aside>
</div>

<script>
    // Arma la URL con los filtros puestos y la abre por la SPA (sin recargar la página).
    // Se redefine en cada visita: es solo una asignación, no suma listeners.
    window.cpdfFiltrar = function (pestana) {
        var p = new URLSearchParams();
        pestana = pestana || @json($pestana);
        p.set('pestana', pestana);
        // El buscador y los filtros se quedan en la pestaña donde se pusieron: al cambiar de
        // pestaña se pide limpia, porque filtra por otras columnas.
        if (pestana === @json($pestana)) {
            var buscar = (document.getElementById('cpdfBuscar') || {}).value || '';
            if (buscar.trim()) p.set('buscar', buscar.trim());
            [['cpdfEstadoSelect', 'estado'], ['cpdfDocumentoSelect', 'documento'],
             ['cpdfDocEstadoSelect', 'estado_doc'], ['cpdfDocTipoSelect', 'tipo_doc']].forEach(function (par) {
                var input = document.querySelector('#' + par[0] + ' [data-filter-value]');
                if (input && input.value && input.value !== 'all') p.set(par[1], input.value);
            });
        }
        var url = @json(route('historial-documentos.index')) + (p.toString() ? '?' + p.toString() : '');
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    };

    // ── Dar por revisadas varias filas sin abrir el visor ──────────────────────────────
    // La ficha NO cambia (ni siquiera los datos sin campo en el panel, que el visor si ofrece
    // poner): cada fila queda como "Revisado a mano por ...". Se redefinen en cada visita
    // (solo asignaciones).
    var cpdfMarcadas = function () {
        return Array.prototype.map.call(document.querySelectorAll('.cpdf-sel-fila:checked'), function (c) { return c.value; });
    };
    window.cpdfSelContar = function () {
        var n = cpdfMarcadas().length, filas = document.querySelectorAll('.cpdf-sel-fila').length,
            todas = document.getElementById('cpdfSelTodas'), barra = document.getElementById('cpdfSelBarra');
        if (todas) todas.checked = filas > 0 && n === filas;
        if (barra) barra.classList.toggle('active', n > 0);
        var cuenta = document.getElementById('cpdfSelCuenta');
        if (cuenta) cuenta.textContent = n;
    };
    window.cpdfSelTodas = function (marcar) {
        document.querySelectorAll('.cpdf-sel-fila').forEach(function (c) { c.checked = !!marcar; });
        window.cpdfSelContar();
    };
    window.cpdfMarcarRevisadas = function () {
        var ids = cpdfMarcadas();
        if (!ids.length) return;
        window.confirmarAccion({
            title: 'Marcar como revisadas',
            message: 'Las ' + ids.length + ' filas marcadas quedarán como revisadas por ti. '
                + 'La ficha no cambia: si hay que corregir algún dato, ábrela en el visor.',
            confirmText: 'Marcar ' + ids.length,
        }, function () {
            window.apiFetch(@json(route('compresion-pdf.documentos.revisados')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ ids: ids }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error((data && data.message) || 'sin exito');
                    window.toast(data.revisadas === 1 ? '1 fila revisada' : data.revisadas + ' filas revisadas', 'success');
                    window.cpdfFiltrar();
                })
                .catch(function (err) {
                    window.toast('No se pudieron marcar como revisadas'
                        + (err && err.message && err.message !== 'sin exito' ? ': ' + err.message : ''), 'error');
                });
        });
    };

    // ── Revisar a mano desde el visor ─────────────────────────────────────────────────
    // Se abre el PDF con los campos de la ficha; bajo cada campo que no cuadra sale lo que
    // dice el documento, con un boton para ponerlo. Al GUARDAR (en el panel del visor, que
    // escribe la ficha con su propia ruta) la fila queda como revisada por esa persona y la
    // lista se recarga para seguir con la siguiente.
    window.cpdfRevisar = function (fila, url, rotulo) {
        window._pdfVerif = fila;
        window.openPdfPreview(url, fila.tipo, rotulo, fila.equipoId);
    };
    // Los escuchadores viven en document y la vista se vuelve a ejecutar en cada visita:
    // se montan UNA vez. Lo que cambia de visita en visita lo leen al dispararse
    // (window._pdfVerif, window.cpdfFiltrar).
    if (!window.__cpdfRevisarBound) {
        window.__cpdfRevisarBound = true;
        var URL_REVISADO = @json(route('compresion-pdf.documento.revisado', ['id' => 0]));
        // Diferencia del verificador -> campo del panel del visor que la corrige.
        var CAMPO = { NOMBRE_DEL_TITULAR: 'titular', ID_SEGURO: 'nombre_aseguradora',
                      FECHA_VENC_POLIZA: 'fecha_vencimiento', FECHA_ROTC: 'fecha_vencimiento', FECHA_RACDA: 'fecha_vencimiento' };
        // El vencimiento de cada documento (VerificacionDocumento::CAMPO_VENCE).
        var VENCE = @json(\App\Models\VerificacionDocumento::CAMPO_VENCE),
            DIAS_ANTERIOR = @json(\App\Models\VerificacionDocumento::DIAS_ANTERIOR);
        // Solo cuenta si el visor es el que se abrio desde esta pantalla, para esa fila.
        var esEste = function (d) {
            var v = window._pdfVerif;
            return !!v && d.module === 'equipo' && String(v.equipoId) === String(d.equipoId) && v.tipo === d.docType;
        };

        document.addEventListener('vidalsa:pdf-cerrado', function () { window._pdfVerif = null; });

        document.addEventListener('vidalsa:metadata-pintada', function (e) {
            if (!esEste(e.detail)) return;
            var cont = document.getElementById('metaFieldsContainer');
            if (!cont) return;
            var dif = window._pdfVerif.dif || {}, otras = [];
            window._pdfVerif.extras = [];
            // Lecturas guardadas antes de la regla del PDF ANTERIOR (VerificacionDocumento::
            // documentoAnterior): si el documento vence antes de lo que ya dice la ficha, es el
            // viejo y nada suyo se ofrece para poner (ni con "Usar" ni con casilla marcada).
            var dv = VENCE[window._pdfVerif.tipo] && dif[VENCE[window._pdfVerif.tipo]],
                fv = cont.querySelector('[name="fecha_vencimiento"]'),
                anterior = !!(dv && dv.documento && fv && fv.value
                    && (Date.parse(fv.value) - Date.parse(dv.documento)) / 86400000 > DIAS_ANTERIOR);
            Object.keys(dif).forEach(function (campo) {
                var d = dif[campo] || {}, valor = d.documento == null ? '' : String(d.documento);
                var input = CAMPO[campo] && cont.querySelector('[name="' + CAMPO[campo] + '"]');
                if (!input) { otras.push([campo, d, valor]); return; }
                // textContent, nunca innerHTML: el valor sale de un PDF.
                var pista = document.createElement('div'), t = document.createElement('span'),
                    b = document.createElement('b'), usar = document.createElement('button');
                pista.className = 'cpdf-pista';
                t.textContent = 'El documento dice:';
                b.textContent = valor;
                if (anterior) {
                    // Sin boton: es la fecha del PDF viejo.
                    pista.append(t, b, document.createTextNode(CAMPO[campo] === 'fecha_vencimiento'
                        ? '(es MÁS VIEJA que la de la ficha: el PDF es el anterior)' : '(del PDF anterior)'));
                } else {
                    usar.type = 'button';
                    usar.textContent = 'Usar';
                    usar.onclick = function () { input.value = valor; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); };
                    pista.append(t, b, usar);
                }
                input.insertAdjacentElement('afterend', pista);
            });
            var aviso = document.createElement('div'), v = window._pdfVerif;
            aviso.className = 'cpdf-pista-aviso';
            // Lo MISMO que la fila de la tabla: que concluyo la revision y, dato a dato, que dice
            // la ficha y que dice el documento. Para comparar sin salir del visor.
            var tit = document.createElement('div'), b = document.createElement('b');
            tit.className = 'cpdf-concl';
            b.textContent = v.estado || '';
            tit.append('Revisión: ', b);
            aviso.appendChild(tit);
            if (v.motivo) {
                var mot = document.createElement('div');
                mot.className = 'cpdf-concl-motivo';
                mot.textContent = v.motivo;
                aviso.appendChild(mot);
            }
            Object.keys(dif).forEach(function (campo) {
                var d = dif[campo] || {}, li = document.createElement('div'), doc = document.createElement('b');
                li.className = 'cpdf-concl-dif';
                doc.textContent = d.documento == null ? '(vacío)' : String(d.documento);
                li.append((d.etiqueta || campo) + ': ficha ' + (d.ficha || '(vacío)') + ' → documento ', doc);
                aviso.appendChild(li);
            });

            // Lo que el panel no tiene (fechas de emision, titular del ROTC): su propio campo,
            // relleno con lo que dice el documento, y una casilla. Al guardar se pone en la ficha
            // lo que quede marcado; sin esto se perderia al dar la fila por revisada.
            otras.forEach(function (o) {
                var campo = o[0], d = o[1], valor = o[2];
                var caja = document.createElement('div'), et = document.createElement('div'),
                    inp = document.createElement('input'), lab = document.createElement('label'),
                    chk = document.createElement('input');
                caja.className = 'cpdf-extra';
                et.textContent = (d.etiqueta || campo) + ' — en la ficha: ' + (d.ficha || '(vacío)');
                inp.type = /^FECHA_/.test(campo) ? 'date' : 'text';
                inp.value = valor;
                chk.type = 'checkbox';
                // Marcada solo si la lectura es fiable. Si el PDF se leyo a medias, no se
                // confirmo de que vehiculo es o es de otro, lo del documento podria dejar la
                // ficha PEOR: se pone solo si la persona lo marca tras mirarlo en el PDF.
                chk.checked = !!v.fiable && !anterior;
                lab.append(chk, document.createTextNode(anterior ? ' Poner en la ficha (es del PDF anterior: no lo marques)'
                    : v.fiable ? ' Poner en la ficha'
                    : ' Poner en la ficha (la lectura no es segura: márcalo solo si lo compruebas en el PDF)'));
                caja.append(et, inp, lab);
                aviso.appendChild(caja);
                window._pdfVerif.extras.push({ campo: campo, input: inp, check: chk });
            });
            cont.insertAdjacentElement('afterbegin', aviso);
        });

        document.addEventListener('vidalsa:metadata-guardada', function (e) {
            if (!esEste(e.detail)) return;
            // El aviso lo da esta pantalla al terminar (uno solo): el del visor se cancela.
            e.preventDefault();
            var id = window._pdfVerif.id, campos = {};
            (window._pdfVerif.extras || []).forEach(function (x) { if (x.check.checked) campos[x.campo] = x.input.value; });
            window._pdfVerif = null;
            window.apiFetch(URL_REVISADO.replace(/\/0\/revisado$/, '/' + id + '/revisado'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ campos: campos }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error((data && data.message) || 'sin exito');
                    window.toast('Guardado y revisado: la fila queda como coincide', 'success');
                    window.closePdfPreview();
                    // Recarga la lista con los mismos filtros, para seguir con la siguiente.
                    if (typeof window.cpdfFiltrar === 'function') window.cpdfFiltrar();
                })
                .catch(function (err) {
                    window.toast('La ficha se guardó, pero no se pudo marcar la fila como revisada'
                        + (err && err.message && err.message !== 'sin exito' ? ': ' + err.message : ''), 'error');
                });
        });
    }
</script>
