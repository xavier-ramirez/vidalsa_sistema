{{-- Panel de "Compresión de PDF" y "Documentos": las dos pestañas que viven DENTRO
     de Control de Auditoría (/admin/historial-documentos). Vive aparte para que esa pantalla
     lo incluya sin repetir su tabla, sus filtros ni su resumen.
     Los datos los arma App\Support\PanelDocumentos::datos(); quien escribe en la ficha, tanto
     desde el visor como desde la tarea de la noche, es App\Services\CorrectorFichaDocumento. --}}
<link rel="stylesheet" href="{{ asset('css/vistas/admin_compresion_pdf_panel.css') }}?v={{ @filemtime(public_path('css/vistas/admin_compresion_pdf_panel.css')) }}">


@php
    $mb = fn ($b) => number_format(($b ?? 0) / 1048576, 1, ',', '.');
    $comp = $resumen[\App\Models\CompresionPdf::COMPRIMIDO] ?? null;
    $estados = [
        \App\Models\CompresionPdf::COMPRIMIDO => 'Comprimidos',
        \App\Models\CompresionPdf::SALTADO    => 'Saltados',
        \App\Models\CompresionPdf::ERROR      => 'Con error',
    ];
    $estadosDoc = [
        'revisar'                 => 'PARA REVISAR A MANO',
        // Lo leido que la tarea todavia no paso a la ficha (lo pasa al leer, asi que casi
        // siempre esta vacio). Tiene que estar en esta lista: el desplegable saca de aqui el
        // nombre de lo filtrado y sin el la pantalla revienta al llegar con
        // ?estado_doc=corregibles (clave inexistente).
        'corregibles'             => 'SIN APLICAR',
        \App\Models\VerificacionDocumento::DIFIERE     => 'Datos distintos',
        \App\Models\VerificacionDocumento::COINCIDE    => 'Coincide',
        \App\Models\VerificacionDocumento::ILEGIBLE    => 'No se pudo leer',
        \App\Models\VerificacionDocumento::SIN_ARCHIVO => 'Sin archivo en Drive',
        \App\Models\VerificacionDocumento::ERROR       => 'Con error',
        // Los dos de la carga masiva: PDF recien soltados que todavia no estan en ninguna
        // ficha. Aqui es DONDE SE VE lo que se subio; el modal solo sirve para soltarlos.
        \App\Models\VerificacionDocumento::POR_ENGANCHAR => 'Por aplicar',
        \App\Models\VerificacionDocumento::SIN_FICHA     => 'Sin ficha reconocida',
        \App\Models\VerificacionDocumento::APLICADO      => 'Aplicado',
    ];
    // En el DESPLEGABLE cada opcion lleva su cuenta, y las que no tienen ninguna fila no se
    // ofrecen: elegirlas daba la lista vacia y parecia que el filtro no hacia nada. La elegida
    // se queda siempre (el desplegable saca de aqui su nombre). Es una copia: las etiquetas de
    // las FILAS ($estadosDoc) van sin cuenta.
    $opcionesEstadoDoc = $estadosDoc;
    if (isset($resumenDocs)) {
        $cuentas = ['revisar' => $docsParaRevisar ?? 0, 'corregibles' => $docsCorregibles ?? 0]
            + $resumenDocs->map(fn ($n) => (int) $n)->all();
        foreach ($opcionesEstadoDoc as $clave => $etiqueta) {
            $n = (int) ($cuentas[$clave] ?? 0);
            if ($n === 0 && $estadoDoc !== $clave) {
                unset($opcionesEstadoDoc[$clave]);
            } else {
                $opcionesEstadoDoc[$clave] = $etiqueta . ' (' . number_format($n, 0, ',', '.') . ')';
            }
        }
    }
    // Los cuatro que lee la noche MAS los dos que solo llegan por la carga masiva
    // (Certificado asociado y Compraventa). Sin ellos, esas filas salian con su clave
    // cruda ('adicional') y el desplegable "Filtrar Documento" no las ofrecia.
    $tiposDoc = \App\Models\VerificacionDocumento::NOMBRES + \App\Services\CargaMasivaDocumentos::NOMBRES;
    // Los desplegables son iguales salvo su lista: se pintan con el mismo molde. Cada pestaña
    // filtra por lo suyo (la de compresion, por documento; la de documentos, por estado y tipo).
    $desplegables = $pestana === 'documentos' ? [
        ['id' => 'cpdfDocEstadoSelect', 'nombre' => 'estado_doc', 'etiqueta' => 'Filtrar Estado...', 'todos' => 'TODOS LOS ESTADOS',
         'valor' => $estadoDoc, 'opciones' => $opcionesEstadoDoc],
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

            @include('admin.historial_documentos.partials.acciones')
        </div>

        @if ($pestana === 'documentos')
        <div class="cpdf-tabla-caja cpdf-docs">
            <table class="admin-table">
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
                        {{-- Las que no coinciden se eligen con un clic en la fila (ver cpdfSelFila);
                             las que ya coinciden no tienen nada que revisar.

                             Las de la CARGA MASIVA tampoco se eligen: son propuestas de un PDF que
                             aún no está en ninguna ficha, se aplican con su propio botón y el
                             servidor las deja fuera del "Revisado" en lote (marcarRevisados). --}}
                        @if ($d->ESTADO !== \App\Models\VerificacionDocumento::COINCIDE
                             && $d->ORIGEN === \App\Models\VerificacionDocumento::DE_LA_NOCHE)
                            <tr class="cpdf-fila-sel" data-id="{{ $d->ID_REGISTRO }}" onclick="window.cpdfSelFila(event, this)">
                        @else
                            <tr>
                        @endif
                            <td><div class="hd-fecha"><span>{{ $d->updated_at?->format('d/m/Y') }}</span><span class="hd-hora">{{ $d->updated_at?->format('h:i A') }}</span></div></td>
                            <td style="white-space:nowrap;">
                                {{ $tiposDoc[$d->TIPO] ?? ($d->TIPO ?: 'Sin reconocer') }}
                                {{-- El nombre del archivo solo en lo recién soltado: es la única
                                     forma de saber cuál de los treinta PDF es cada fila. --}}
                                @if ($d->ARCHIVO)
                                    <small style="display:block;color:#64748b;font-weight:400;">{{ \Illuminate\Support\Str::limit($d->ARCHIVO, 28) }}</small>
                                @endif
                            </td>
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
                                {{-- Lo recién soltado en la carga masiva se aplica DESDE AQUÍ: el
                                     modal solo sirve para soltar archivos. Solo cuando hay ficha
                                     reconocida; sin ella no hay dónde enlazarlo.

                                     El MISMO permiso que el JS de más abajo: sin él estos botones
                                     no se pintan. Si no, un super.admin sin la clave vería filas
                                     que otro subió, pulsaría y se encontraría con que la función
                                     ni existe. --}}
                                @can('docs.carga.masiva')
                                @if ($d->ESTADO === \App\Models\VerificacionDocumento::POR_ENGANCHAR && ($d->PROPUESTA['equipos'] ?? []))
                                    <button type="button" class="pdf-doc-btn cpdf-aplicar" title="Enlazar este PDF a su ficha"
                                        onclick="event.stopPropagation(); window.cpdfAplicarCarga(this, @js($d->PROPUESTA))">
                                        <i class="material-icons">playlist_add_check</i>
                                    </button>
                                @endif
                                {{-- Descartar: el PDF subido se va a la papelera de Drive y la fila
                                     desaparece. Sin esto, lo que se sube y no se aplica se queda
                                     ahí para siempre. Solo en lo de la carga masiva sin aplicar. --}}
                                @if (in_array($d->ESTADO, \App\Models\VerificacionDocumento::DE_LA_CARGA, true) && ($d->PROPUESTA['link'] ?? null))
                                    <button type="button" class="pdf-doc-btn cpdf-descartar" title="Descartar este PDF"
                                        onclick="event.stopPropagation(); window.cpdfDescartarCarga(this, @js($d->PROPUESTA['link']), @js($d->ARCHIVO))">
                                        <i class="material-icons">delete_outline</i>
                                    </button>
                                @endif
                                @endcan
                                @if ($d->DRIVE_ID && $d->ORIGEN !== \App\Models\VerificacionDocumento::DE_LA_NOCHE)
                                    {{-- Lo soltado en la carga masiva se MIRA, sin la ficha al lado: todavia no es
                                         el documento de ningun equipo. Con cpdfRevisar el visor tomaba el PDF
                                         por el documento de la ficha, y su "Eliminar" o "Reemplazar" actuaban
                                         sobre el documento MONTADO del equipo; y guardar el panel la daba por
                                         revisada ("Coincide") sin haberse aplicado. --}}
                                    <button type="button" class="pdf-doc-btn" title="Ver el PDF soltado"
                                        onclick="window.openPdfPreview('/storage/google/{{ $d->DRIVE_ID }}', @js($d->TIPO), @js($d->ARCHIVO ?: 'Documento'), null, '', true)">
                                        <i class="material-icons">description</i>
                                    </button>
                                @elseif ($d->DRIVE_ID)
                                    {{-- Abre el PDF con los campos de la ficha para revisarla (ver cpdfRevisar). --}}
                                    <button type="button" class="pdf-doc-btn" title="Ver el documento y revisar la ficha"
                                        onclick="window.cpdfRevisar(@js(['id' => $d->ID_REGISTRO, 'equipoId' => (int) $d->ID_EQUIPO, 'tipo' => $d->TIPO, 'dif' => (object) ($d->DIFERENCIAS ?? []), 'estado' => $estadosDoc[$d->ESTADO] ?? $d->ESTADO, 'motivo' => $d->MOTIVO, 'fiable' => !($d->esLecturaParcial() || $d->sinConfirmar() || $d->esDeOtroVehiculo() || $d->esDocumentoAnterior())]), '/storage/google/{{ $d->DRIVE_ID }}', @js(($tiposDoc[$d->TIPO] ?? '') . ' ' . ($d->PLACA ?: $d->SERIAL ?: '')))">
                                        <i class="material-icons">description</i>
                                    </button>
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
        {{-- Dar por revisadas las filas elegidas con un clic, sin abrir el visor (ver cpdfMarcarRevisadas). --}}
        <div id="cpdfSelBarra" class="selection-floating-bar">
            <div class="selection-counter">
                <i class="material-icons" style="font-size:18px;">fact_check</i>
                <span id="cpdfSelCuenta">0</span>
            </div>
            <div style="width:1px;height:24px;background:rgba(255,255,255,0.2);"></div>
            <button type="button" class="btn-bulk-clear" onclick="window.cpdfSelLimpiar()">Limpiar</button>
            <button type="button" class="btn-bulk-action" onclick="window.cpdfMarcarRevisadas()">
                <i class="material-icons">done_all</i> Revisado
            </button>
        </div>
        @else
        <div class="cpdf-tabla-caja cpdf-comp">
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
                            <td><div class="hd-fecha"><span>{{ $f->created_at?->format('d/m/Y') }}</span><span class="hd-hora">{{ $f->created_at?->format('h:i A') }}</span></div></td>
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
                </div>
            </div>
            <div class="cpdf-caja cpdf-aviso {{ $activa ? 'ok' : 'apagada' }}">
                <i class="material-icons">{{ $activa ? 'schedule' : 'block' }}</i>
                <div>
                    @if ($activa)
                        <strong>Lectura automática activa</strong>
                        {{-- Corto a proposito. La zona solo se nombra si el servidor NO esta en la de Venezuela. --}}
                        <span>{{ ucfirst($horarioLectura) }}{{ $zona !== 'America/Caracas' ? ' (' . $zona . ')' : '' }} · no toca placa ni serial.</span>
                        {{-- A cualquier hora: arranca ya y relee tambien los "No se pudo leer". --}}
                        @if ($lecturaPedida)
                            <span class="cpdf-ahora-pedida">Pedida: {{ \Carbon\Carbon::parse($lecturaPedida)->format('g:i a') }} (en curso).</span>
                        @endif
                        <button type="button" class="btn-primary-maquinaria cpdf-ahora" onclick="window.cpdfLeerAhora(this)">
                            <i class="material-icons">play_arrow</i> Revisar ahora
                        </button>
                    @else
                        <strong>Lectura automática apagada</strong>
                        <span>{{ ucfirst($motivoActiva) }}.</span>
                    @endif
                </div>
            </div>
            {{-- Cada tarjeta filtra la lista de abajo: se pulsa y se ve QUE filas son. --}}
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => 'revisar', 'page' => null]) }}">
                <small>Para revisar a mano</small>
                <strong>{{ $docsParaRevisar }}</strong>
                <span>ilegibles, ajenos o sin archivo</span>
            </a>
            <div class="cpdf-caja">
                <small>Última lectura</small>
                <strong style="font-size:16px;">{{ $ultimaLectura ? \Carbon\Carbon::parse($ultimaLectura)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
                <span>de los 4 tipos</span>
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
                    <span>{{ ucfirst($horarioCompresion) }}{{ $zona !== 'America/Caracas' ? ' (' . $zona . ')' : '' }} · solo si hay algo por comprimir.</span>
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
    // De la ficha solo se llenan las fechas que tiene VACIAS y el documento trae
    // (CorrectorFichaDocumento::fechasVacias); lo demas no cambia. Cada fila queda como
    // "Revisado a mano por ...". Se redefinen en cada visita (solo asignaciones).
    var cpdfMarcadas = function () {
        return Array.prototype.map.call(document.querySelectorAll('.cpdf-fila-sel.selected-row-maquinaria'), function (tr) { return tr.dataset.id; });
    };
    var cpdfSelContar = function () {
        var n = cpdfMarcadas().length, barra = document.getElementById('cpdfSelBarra'),
            cuenta = document.getElementById('cpdfSelCuenta');
        if (barra) barra.classList.toggle('active', n > 0);
        if (cuenta) cuenta.textContent = n;
    };
    // Clic en cualquier parte de la fila la elige o la suelta; el botón del PDF sigue
    // abriendo el visor sin tocar la selección.
    window.cpdfSelFila = function (e, tr) {
        if (e.target.closest('button, a, input')) return;
        tr.classList.toggle('selected-row-maquinaria');
        cpdfSelContar();
    };
    window.cpdfSelLimpiar = function () {
        document.querySelectorAll('.cpdf-fila-sel.selected-row-maquinaria').forEach(function (tr) { tr.classList.remove('selected-row-maquinaria'); });
        cpdfSelContar();
    };
    window.cpdfMarcarRevisadas = function () {
        var ids = cpdfMarcadas();
        if (!ids.length) return;
        window.confirmarAccion({
            title: 'Revisado',
            message: 'Las ' + ids.length + ' filas seleccionadas quedarán como revisadas por ti. '
                + 'Las fechas que la ficha tiene vacías se llenan con las del documento; lo demás no cambia: '
                + 'si hay que corregir otro dato, ábrela en el visor.',
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
                    window.toast((data.revisadas === 1 ? '1 fila revisada' : data.revisadas + ' filas revisadas')
                        + (data.fechas ? ' · ' + (data.fechas === 1 ? '1 fecha puesta' : data.fechas + ' fechas puestas') : ''), 'success');
                    window.cpdfFiltrar();
                })
                .catch(function (err) {
                    window.toast('No se pudieron marcar como revisadas'
                        + (err && err.message && err.message !== 'sin exito' ? ': ' + err.message : ''), 'error');
                });
        });
    };

    // "Revisar ahora": pide la lectura a cualquier hora (ver VerificarDocumentos::pedirAhora).
    window.cpdfLeerAhora = function (btn) {
        btn.disabled = true;
        window.apiFetch(@json(route('compresion-pdf.documentos.leer-ahora')), { method: 'POST', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error('sin exito');
                window.toast('La revisión arranca en un minuto y sigue hasta que no quede nada', 'success');
                window.cpdfFiltrar();
            })
            .catch(function () {
                btn.disabled = false;
                window.toast('No se pudo pedir la revisión', 'error');
            });
    };

    // ── Aplicar un PDF de la carga masiva ─────────────────────────────────────────────
    // El modal de carga masiva solo sirve para SOLTAR archivos; lo que se subió se ve y se
    // aplica aquí, en la misma tabla que la revisión de la noche. Este botón enlaza el PDF a
    // la ficha que se le propuso, con las mismas puertas del servidor (no pisa lo que ya hay,
    // no retrocede un vencimiento, no entra sin fecha).
    @can('docs.carga.masiva')
    window.cpdfAplicarCarga = function (btn, propuesta) {
        var fichas = (propuesta && propuesta.equipos) || [];
        if (!fichas.length) return;

        // Los que vencen necesitan su fecha, y del certificado y la compraventa no se lee
        // ninguna: se pide aquí, que es donde está la persona.
        var vence = propuesta.vence || '';
        if (@json(array_keys(\App\Support\DocumentacionDeEquipo::VENCIMIENTO)).indexOf(propuesta.tipo) !== -1 && !vence) {
            vence = window.prompt('¿Cuándo vence este documento? (AAAA-MM-DD)', '');
            if (!vence) return;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(vence)) { window.toast('La fecha va como 2027-04-08', 'error'); return; }
        }

        btn.disabled = true;
        // Un ROTC de flota trae cientos de fichas y cada una arma y sube su parte (~2-3 s): el
        // boton cuenta por donde va, para que se vea que avanza y no se vuelva a pulsar.
        var htmlBoton = btn.innerHTML;
        var i = 0, bien = 0, fallos = [], reemplazarTodas = null;
        var siguiente = function () {
            if (fichas.length > 1) btn.innerHTML = '<span style="font-size:11px;font-weight:700;">' + Math.min(i + 1, fichas.length) + '/' + fichas.length + '</span>';
            if (i >= fichas.length) {
                btn.innerHTML = htmlBoton;
                btn.disabled = false;
                // Lo que NO entro se dice siempre, tambien cuando otras fichas si: antes, con
                // una sola que entrara, los rechazos de las demas se perdian sin avisar.
                if (bien && !fallos.length) window.toast('Aplicado a ' + bien + ' ficha' + (bien === 1 ? '' : 's'), 'success');
                else if (bien) window.toast('Aplicado a ' + bien + ' de ' + fichas.length + '. No entró en: ' + resumen(fallos), 'warning');
                else window.toast(resumen(fallos) || 'No se pudo aplicar', 'error');
                window.cpdfFiltrar();
                return;
            }
            var f = fichas[i++];
            enviar(f, false).then(siguiente);
        };

        // Una ficha. Si el servidor dice que YA tiene ese documento, se pregunta y solo
        // entonces se reintenta con "reemplazar": así la regla de no pisar sigue siendo del
        // servidor y aquí solo se pide permiso. Un documento ANTERIOR se niega igualmente,
        // reemplazo o no, y ahí el servidor manda su motivo sin volver a preguntar.
        // Con cientos de fichas el aviso no puede listarlas todas: las 5 primeras y cuantas mas.
        function resumen(lista) {
            return lista.slice(0, 5).join(' · ') + (lista.length > 5 ? ' · y ' + (lista.length - 5) + ' más' : '');
        }

        function enviar(f, pisar) {
            return window.apiPostForm(@json(route('historial-documentos.carga-masiva.aplicar')), {
                id_equipo: f.id, auxiliar: f.auxiliar ? 1 : '', tipo: propuesta.tipo,
                link: propuesta.link, vence: vence || '', emision: propuesta.emision || '',
                pisar: pisar ? 1 : '',
                // La fila pasa a "Aplicado" con la ULTIMA ficha, no con la primera: si el
                // reparto de un RACDA se corta a medias, el boton sigue ahi para terminarlo
                // (las que ya lo tienen responden "Ya estaba enlazado").
                cerrar: f === fichas[fichas.length - 1] ? 1 : 0
            }, 'No se pudo aplicar.')
                .then(function () { bien++; })
                .catch(function (e) {
                    var msg = (e && e.message) || 'No se pudo aplicar';
                    if (!pisar && e && e.requiere_pisar) {
                        // Con varias fichas (RACDA, ROTC de flota) se pregunta UNA vez y la
                        // respuesta vale para todas: si no, eran decenas de ventanas seguidas.
                        if (reemplazarTodas === null) {
                            reemplazarTodas = window.confirm(f.nombre + ' ya tiene ese documento.\n\n¿Reemplazarlo?'
                                + (fichas.length > 1 ? '\n(La respuesta vale para TODAS las de este documento que ya tengan uno.)' : '')
                                + '\nEl anterior se va a la PAPELERA de Drive: se recupera con un clic.');
                        }
                        if (reemplazarTodas) return enviar(f, true);
                        msg = 'No se reemplazó: ' + f.nombre + ' ya tiene ese documento.';
                    }
                    fallos.push(fichas.length > 1 ? f.nombre + ' (' + msg + ')' : msg);
                });
        }

        siguiente();
    };

    // Descartar lo subido y no aplicado: el PDF se va a la PAPELERA de Drive (se recupera con
    // un clic si fue un error) y la fila desaparece de la tabla.
    window.cpdfDescartarCarga = function (btn, link, archivo) {
        if (!window.confirm('¿Descartar "' + (archivo || 'este PDF') + '"?\n\nEl archivo se va a la papelera de Drive y la fila desaparece.')) return;
        btn.disabled = true;
        window.apiPostForm(@json(route('historial-documentos.carga-masiva.descartar')), { link: link }, 'No se pudo descartar.')
            .then(function () { window.toast('Descartado', 'success'); window.cpdfFiltrar(); })
            .catch(function (e) { btn.disabled = false; window.toast((e && e.message) || 'No se pudo descartar', 'error'); });
    };
    @endcan

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
        // Las fechas de emision tienen su campo en el panel (fecha_emision): lo que dice el
        // documento sale debajo con su "Usar", no como un campo aparte.
        var CAMPO = { NOMBRE_DEL_TITULAR: 'titular', ID_SEGURO: 'nombre_aseguradora',
                      FECHA_VENC_POLIZA: 'fecha_vencimiento', FECHA_ROTC: 'fecha_vencimiento', FECHA_RACDA: 'fecha_vencimiento',
                      FECHA_EMISION_PROPIEDAD: 'fecha_emision', FECHA_EMISION_POLIZA: 'fecha_emision',
                      FECHA_EMISION_ROTC: 'fecha_emision', FECHA_EMISION_RACDA: 'fecha_emision' };
        // El vencimiento de cada documento (VerificacionDocumento::CAMPO_VENCE).
        var VENCE = @json(\App\Models\VerificacionDocumento::CAMPO_VENCE),
            DIAS_ANTERIOR = @json(\App\Models\VerificacionDocumento::DIAS_ANTERIOR);
        // Solo cuenta si el visor es el que se abrio desde esta pantalla, para esa fila.
        var esEste = function (d) {
            var v = window._pdfVerif;
            return !!v && d.module === 'equipo' && String(v.equipoId) === String(d.equipoId) && v.tipo === d.docType;
        };

        document.addEventListener('vidalsa:pdf-cerrado', function () { window._pdfVerif = null; });

        // Para armar la tarjeta. Siempre textContent, nunca innerHTML: los valores salen de un PDF.
        var cpdfNodo = function (tag, clase, hijos) {
            var n = document.createElement(tag);
            if (clase) n.className = clase;
            [].concat(hijos == null ? [] : hijos).forEach(function (h) { n.append(h); });
            return n;
        };
        var cpdfIcono = function (nombre) { return cpdfNodo('i', 'material-icons', nombre); };
        // aaaa-mm-dd -> dd/mm/aaaa; lo demas tal cual.
        var cpdfFecha = function (v) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v));
            return m ? m[3] + '/' + m[2] + '/' + m[1] : String(v);
        };
        var cpdfValor = function (v, clase) {
            return v == null || v === '' ? cpdfNodo('span', clase + ' cpdf-rev-vacio', 'vacío') : cpdfNodo('span', clase, cpdfFecha(v));
        };

        document.addEventListener('vidalsa:metadata-pintada', function (e) {
            if (!esEste(e.detail)) return;
            // Con un documento sin cargar delante, el panel está pidiendo la fecha de ESE
            // documento (visor: pedirVencimientoEnVisor) y su campo sale vacío a propósito.
            // Las pistas de aquí son del documento que ya está en la ficha: colgarlas ahí
            // ofrecería "Usar" con la fecha del papel equivocado.
            if (window._pdfVencPendiente) return;
            var cont = document.getElementById('metaFieldsContainer');
            if (!cont) return;
            var dif = window._pdfVerif.dif || {}, otras = [];
            window._pdfVerif.extras = [];
            // Lecturas guardadas antes de la regla del PDF ANTERIOR (VerificacionDocumento::
            // documentoAnterior): si el documento vence antes de lo que ya dice la ficha, es el
            // viejo y nada suyo se ofrece para poner (ni con "Usar" ni en los campos de abajo).
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
                t.textContent = 'Documento:';
                b.textContent = cpdfFecha(valor);
                if (anterior) {
                    // Sin boton: es del PDF viejo.
                    var nota = document.createElement('em');
                    nota.textContent = CAMPO[campo] === 'fecha_vencimiento' ? 'más vieja que la ficha: PDF anterior' : 'del PDF anterior';
                    pista.append(t, b, nota);
                } else {
                    usar.type = 'button';
                    usar.textContent = 'Usar';
                    usar.onclick = function () { input.value = valor; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); };
                    pista.append(t, b, usar);
                    // La fecha de emision que la ficha tiene VACIA viene ya puesta: basta con
                    // Guardar (lo pidio el cliente); vaciar el campo = no ponerla.
                    if (CAMPO[campo] === 'fecha_emision' && !input.value) input.value = valor;
                }
                input.insertAdjacentElement('afterend', pista);
            });
            var aviso = document.createElement('div'), v = window._pdfVerif;
            aviso.className = 'cpdf-pista-aviso';
            // Lo MISMO que la fila de la tabla: que concluyo la revision y, dato a dato, que dice
            // la ficha y que dice el documento. Para comparar sin salir del visor.
            aviso.appendChild(cpdfNodo('div', 'cpdf-rev-cab', [cpdfNodo('small', '', 'Revisión'), cpdfNodo('span', 'cpdf-rev-estado', v.estado || '')]));
            if (v.motivo) aviso.appendChild(cpdfNodo('div', 'cpdf-rev-motivo', v.motivo));
            // Los datos que llevan su propio campo abajo (.cpdf-extra) no se repiten en la lista.
            var enExtra = {};
            otras.forEach(function (o) { enExtra[o[0]] = true; });
            var lista = cpdfNodo('div', 'cpdf-rev-lista');
            Object.keys(dif).forEach(function (campo) {
                if (enExtra[campo]) return;
                var d = dif[campo] || {};
                lista.appendChild(cpdfNodo('div', 'cpdf-rev-dif', [
                    cpdfNodo('div', 'cpdf-rev-campo', d.etiqueta || campo),
                    cpdfNodo('div', 'cpdf-rev-val', [cpdfValor(d.ficha, 'cpdf-rev-ficha'), cpdfIcono('arrow_forward'), cpdfValor(d.documento, 'cpdf-rev-doc')]),
                ]));
            });
            if (lista.childNodes.length) aviso.appendChild(lista);

            // Lo que el panel no tiene (el titular del ROTC): su propio campo,
            // relleno con lo que dice el documento. Al GUARDAR se pone en la ficha lo que diga el
            // campo, sin casilla que marcar (lo pidio el cliente); vaciarlo = no ponerlo. Sin esto
            // se perderia al dar la fila por revisada.
            // Del PDF ANTERIOR no se ofrece nada: es el viejo y dejaria la ficha peor.
            if (!anterior) otras.forEach(function (o) {
                var campo = o[0], d = o[1], valorDoc = o[2];
                var inp = document.createElement('input');
                inp.type = /^FECHA_/.test(campo) ? 'date' : 'text';
                inp.value = valorDoc;
                aviso.appendChild(cpdfNodo('div', 'cpdf-extra', [
                    cpdfNodo('div', 'cpdf-extra-tit', d.etiqueta || campo),
                    cpdfNodo('div', 'cpdf-extra-ficha', 'Ficha: ' + (d.ficha ? cpdfFecha(d.ficha) : 'vacío') + ' · documento:'),
                    inp,
                    // Si no se confirmo de que vehiculo es (o se leyo a medias), se avisa: quien
                    // guarda debe haberlo visto en el PDF.
                    cpdfNodo('div', 'cpdf-extra-nota', v.fiable ? 'Se pone en la ficha al guardar.'
                        : 'Se pone al guardar. Lectura no segura: confírmalo en el PDF o vacía el campo.'),
                ]));
                window._pdfVerif.extras.push({ campo: campo, input: inp });
            });
            cont.insertAdjacentElement('afterbegin', aviso);
        });

        // Deja la fila como la dejo el servidor al darla por revisada (marcarRevisadoPor):
        // "Coincide", con el motivo "Revisado a mano por ..." y sin diferencias. Ya no se puede
        // elegir para "Revisado" (como las demas que coinciden). La cuenta del filtro se pone
        // al dia la proxima vez que se abra la lista.
        var cpdfFilaRevisada = function (id, motivo) {
            var tr = document.querySelector('tr.cpdf-fila-sel[data-id="' + id + '"]');
            if (!tr) return;
            var celdas = tr.children, estado = tr.querySelector('.cpdf-estado');
            var hoy = new Date(), dos = function (n) { return (n < 10 ? '0' : '') + n; };
            var fecha = celdas[0] && celdas[0].querySelector('.hd-fecha');
            if (fecha) fecha.replaceChildren(
                cpdfNodo('span', '', dos(hoy.getDate()) + '/' + dos(hoy.getMonth() + 1) + '/' + hoy.getFullYear()),
                cpdfNodo('span', 'hd-hora', dos(hoy.getHours() % 12 || 12) + ':' + dos(hoy.getMinutes()) + (hoy.getHours() < 12 ? ' AM' : ' PM')));
            var detalle = tr.querySelector('.cpdf-ancha');
            if (detalle) detalle.replaceChildren(cpdfNodo('span', 'cpdf-revisada', motivo || 'Revisado a mano'));
            if (estado) {
                estado.className = 'cpdf-estado ' + @json(\App\Models\VerificacionDocumento::COINCIDE);
                estado.textContent = @json($estadosDoc[\App\Models\VerificacionDocumento::COINCIDE]);
                estado.title = motivo || '';
            }
            tr.classList.remove('cpdf-fila-sel', 'selected-row-maquinaria');
            tr.removeAttribute('onclick');
            tr.classList.add('cpdf-recien-revisada');
            if (typeof cpdfSelContar === 'function') cpdfSelContar();
        };

        document.addEventListener('vidalsa:metadata-guardada', function (e) {
            if (!esEste(e.detail)) return;
            // El aviso lo da esta pantalla al terminar (uno solo): el del visor se cancela.
            e.preventDefault();
            var id = window._pdfVerif.id, campos = {};
            (window._pdfVerif.extras || []).forEach(function (x) { if (x.input.value.trim() !== '') campos[x.campo] = x.input.value.trim(); });
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
                    // La fila se pone al dia EN SU SITIO, sin recargar la lista: recargar llevaba
                    // arriba (y a la primera pagina), y habia que buscar otra vez por donde se iba.
                    cpdfFilaRevisada(id, data.motivo);
                })
                .catch(function (err) {
                    window.toast('La ficha se guardó, pero no se pudo marcar la fila como revisada'
                        + (err && err.message && err.message !== 'sin exito' ? ': ' + err.message : ''), 'error');
                });
        });
    }
</script>
