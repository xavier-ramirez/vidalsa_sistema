{{-- Panel de "Compresión de PDF" y "Títulos y pólizas": las dos pestañas que viven DENTRO
     de Control de Auditoría (/admin/historial-documentos). Vive aparte para que esa pantalla
     lo incluya sin repetir su tabla, sus filtros ni su resumen.
     Los datos los arma App\Support\PanelDocumentos::datos(); la corrección de una ficha la
     aplica CompresionPdfController::aplicarDocumento. --}}
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

    /* La tabla y su encabezado: .tabla-lista y .tabla-cabecera (estilos_globales.css). */
    .cpdf-tabla-caja { overflow-x: auto; }
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
    .cpdf-nom.mal { color: #991b1b; text-decoration: line-through; }
    .cpdf-dif { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; font-size: 12.5px; line-height: 1.5; }
    .cpdf-dif-eti { font-weight: 700; color: #64748b; }
    .cpdf-dif-doc { color: #166534; font-weight: 600; }
    .cpdf-flecha { font-size: 14px; color: #94a3b8; }
    .cpdf-aplicar { display: inline-flex; align-items: center; gap: 3px; border: 1px solid #bfdbfe; background: #eff6ff; color: #0067b1;
                    border-radius: 8px; padding: 4px 9px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .cpdf-aplicar:hover { background: #dbeafe; }
    .cpdf-aplicar .material-icons { font-size: 15px; }

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
        ['id' => 'cpdfDocTipoSelect', 'nombre' => 'tipo_doc', 'etiqueta' => 'Filtrar Documento...', 'todos' => 'TÍTULOS Y PÓLIZAS',
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
            <table class="tabla-lista">
                <thead>
                    <tr class="tabla-cabecera">
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
                            <td style="white-space:nowrap;">{{ $d->updated_at?->format('d/m/Y H:i') }}</td>
                            <td style="white-space:nowrap;">{{ $tiposDoc[$d->TIPO] ?? $d->TIPO }}</td>
                            <td style="white-space:nowrap;">
                                {{ $d->PLACA ?: '—' }}
                                @if ($d->SERIAL) <small style="display:block;color:#64748b;">{{ $d->SERIAL }}</small> @endif
                            </td>
                            {{-- Solo lo que NO cuadra: a la izquierda lo de la ficha (tachado), a la
                                 derecha lo que dice el PDF. Si todo cuadra, lo leido en gris. --}}
                            <td>
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
                                    <button type="button" class="pdf-doc-btn" title="Ver el documento"
                                        onclick="window.openPdfPreview('/storage/google/{{ $d->DRIVE_ID }}', 'verificacion', @js(($tiposDoc[$d->TIPO] ?? '') . ' ' . ($d->PLACA ?: $d->SERIAL ?: '')), 0, '', true)">
                                        <i class="material-icons">description</i>
                                    </button>
                                @endif
                                {{-- Corrige la ficha con lo que dice el documento. Solo aparece cuando hay
                                     algo que corregir y el PDF es de ESTE vehiculo. --}}
                                @if ($d->aplicable())
                                    <form method="POST" action="{{ route('compresion-pdf.documento.aplicar', ['id' => $d->ID_REGISTRO]) }}" style="display:inline;"
                                          onsubmit="return confirm('¿Poner en la ficha lo que dice el documento?');">
                                        @csrf
                                        <button type="submit" class="cpdf-aplicar" title="Poner en la ficha lo que dice el documento"><i class="material-icons">how_to_reg</i> Corregir ficha</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cpdf-vacio">{{ $estadoDoc || $tipoDoc || $buscar !== '' ? 'Nada coincide con los filtros.' : 'Todavía no se ha revisado ningún documento.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $docs->links('vendor.pagination.custom-sliding') }}</div>
        @else
        <div class="cpdf-tabla-caja">
            <table class="tabla-lista">
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
            <div class="cpdf-caja cpdf-aviso {{ $activa ? 'ok' : 'apagada' }}">
                <i class="material-icons">{{ $activa ? 'fact_check' : 'block' }}</i>
                <div>
                    @if ($activa)
                        <strong>Lectura nocturna activa</strong>
                        <span>De 9:00 p.m. a medianoche, hora {{ $zona === 'America/Caracas' ? 'de Venezuela' : $zona }} (ahora {{ $horaApp->format('g:i a') }}). No se cruza con la compresión, y no cambia ninguna ficha sola.</span>
                    @else
                        <strong>Lectura nocturna apagada</strong>
                        <span>{{ ucfirst($motivoActiva) }}.</span>
                    @endif
                </div>
            </div>
            <div class="cpdf-caja">
                <small>Coinciden</small>
                <strong>{{ $resumenDocs[\App\Models\VerificacionDocumento::COINCIDE] ?? 0 }}</strong>
                <span>la ficha dice lo mismo que el documento</span>
            </div>
            <div class="cpdf-caja">
                <small>Datos distintos</small>
                <strong>{{ $docsCorregibles }}</strong>
                <span>se corrigen con un botón</span>
            </div>
            <div class="cpdf-caja">
                <small>Para revisar a mano</small>
                <strong>{{ $docsParaRevisar }}</strong>
                <span>ilegibles, de otro vehículo o sin archivo</span>
            </div>
            <div class="cpdf-caja">
                <small>Faltan por leer</small>
                <strong>{{ $pendientesDocs }}</strong>
                <span>tandas de 5, títulos y pólizas</span>
            </div>
            <div class="cpdf-caja">
                <small>Última lectura</small>
                <strong style="font-size:16px;">{{ $ultimaLectura ? \Carbon\Carbon::parse($ultimaLectura)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
                <span>de títulos y pólizas</span>
            </div>
        @else
        <div class="cpdf-caja cpdf-aviso {{ $activa && $ghostscript ? 'ok' : 'apagada' }}">
            <i class="material-icons">{{ $activa && $ghostscript ? 'nights_stay' : 'block' }}</i>
            <div>
                {{-- Corto a propósito. La hora de la app va para comprobar de un vistazo que
                     "las 12" son las de Venezuela; si la zona fuera otra, se nombra. --}}
                @if ($activa && $ghostscript)
                    <strong>Tarea nocturna activa</strong>
                    <span>De 12:00 a 5:00 a.m., hora {{ $zona === 'America/Caracas' ? 'de Venezuela' : $zona }} (ahora {{ $horaApp->format('g:i a') }}).</span>
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
            <span>tandas de 5, de 12:00 a 5:00 a.m.</span>
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
</script>
