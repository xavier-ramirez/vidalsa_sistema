{{-- Panel de "Compresión de PDF" y "Documentos": las dos pestañas que viven DENTRO
     de Control de Auditoría (/admin/historial-documentos). Vive aparte para que esa pantalla
     lo incluya sin repetir su tabla, sus filtros ni su resumen.
     Los datos los arma App\Support\PanelDocumentos::datos(); quien escribe en la ficha, tanto
     desde el visor como desde la tarea de la noche, es App\Services\CorrectorFichaDocumento. --}}
<link rel="stylesheet" href="{{ asset('css/vistas/admin_compresion_pdf_panel.css') }}?v={{ @filemtime(public_path('css/vistas/admin_compresion_pdf_panel.css')) }}">


@php
    // Eliminar (descartar) lo de la carga masiva: borra de Drive, así que solo super.admin con la carga
    // masiva, y solo en la Revisión. Va en el contador de selección (partials/chip_seleccion).
    $puedeDescartar = $pestana === 'documentos' && auth()->user()->can('docs.carga.masiva') && auth()->user()->can('super.admin');
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
        // Los de la carga masiva: PDF recien soltados que todavia no estan en ninguna ficha.
        // Aqui es DONDE SE VE lo que se subio; el modal solo sirve para soltarlos.
        \App\Models\VerificacionDocumento::POR_ENGANCHAR  => 'Sin enlazar',
        \App\Models\VerificacionDocumento::SIN_FICHA      => 'Sin ficha reconocida',
        \App\Models\VerificacionDocumento::OTRO_DOCUMENTO => 'Otro documento (no se asoció)',
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
    // filtra por lo suyo (la de compresion, por documento; la de documentos, por tipo y estado:
    // el tipo primero, a la izquierda, y el buscador al final; pedido del cliente, 19-10-2026).
    $desplegables = $pestana === 'documentos' ? [
        ['id' => 'cpdfDocTipoSelect', 'nombre' => 'tipo_doc', 'etiqueta' => 'Filtrar Documento...', 'todos' => 'TODOS LOS DOCUMENTOS',
         'valor' => $tipoDoc, 'opciones' => $tiposDoc],
        ['id' => 'cpdfDocEstadoSelect', 'nombre' => 'estado_doc', 'etiqueta' => 'Filtrar Estado...', 'todos' => 'TODOS LOS ESTADOS',
         'valor' => $estadoDoc, 'opciones' => $opcionesEstadoDoc],
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
            @includeWhen($pestana !== 'documentos', 'admin.compresion_pdf.partials.buscador')

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

            @includeWhen($pestana === 'documentos', 'admin.compresion_pdf.partials.buscador')

            @include('admin.historial_documentos.partials.filtro_fechas', ['alCambiar' => 'window.cpdfFiltrar'])

            @include('admin.historial_documentos.partials.acciones')
        </div>

        @if ($pestana === 'documentos')
        <div class="cpdf-tabla-caja cpdf-docs">
            <table class="admin-table">
                <thead>
                    <tr class="tabla-cabecera">
                        {{-- Lo corto primero y el detalle al final, que es el que se lleva el ancho
                             que sobra (pedido del cliente, 20-10-2026). --}}
                        <th>Fecha</th>
                        <th>Documento</th>
                        <th>Equipo</th>
                        <th>Estado</th>
                        <th>Ficha y documento</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {{-- aaaa-mm-dd -> dd/mm/aaaa para la columna de diferencias; lo demás tal cual. --}}
                    @php $cpdfVer = fn ($v) => is_string($v) ? preg_replace('/^(\d{4})-(\d{2})-(\d{2})$/', '$3/$2/$1', $v) : $v; @endphp
                    @forelse ($docs as $d)
                        {{-- Toda fila se elige con un clic (cpdfSelFila, contador partials/chip_seleccion).
                             Las que además se pueden dar por REVISADAS en lote llevan cpdf-revisable:
                             las de la noche que no coinciden y, de la CARGA MASIVA, lo ya enlazado que
                             quedó para revisar (sin fecha, o un BL sin número). Lo demás son propuestas
                             que se enlazan solas (o dicen por qué no), y el servidor las deja fuera del
                             "Revisado" en lote (marcarRevisados). Revisar va con la carga masiva. --}}
                        @php
                            $revisable = auth()->user()->can('docs.carga.masiva') && (($d->ESTADO !== \App\Models\VerificacionDocumento::COINCIDE
                                  && $d->ORIGEN === \App\Models\VerificacionDocumento::DE_LA_NOCHE)
                                 || ($d->ORIGEN === \App\Models\VerificacionDocumento::DE_CARGA_MASIVA
                                  && $d->ESTADO === \App\Models\VerificacionDocumento::APLICADO && $d->A_MANO));
                        @endphp
                        {{-- data-descartar: el PDF de la carga masiva que se puede descartar (va a la papelera
                             de Drive y la fila desaparece) con el "Eliminar" del contador de selección. Solo lo
                             de la carga sin aplicar, y solo super.admin con la carga masiva (borra de Drive). --}}
                        <tr class="cpdf-fila-sel{{ $revisable ? ' cpdf-revisable' : '' }}" data-id="{{ $d->ID_REGISTRO }}" onclick="window.cpdfSelFila(event, this)"
                            @if ($puedeDescartar && $d->ORIGEN === \App\Models\VerificacionDocumento::DE_CARGA_MASIVA
                                 && in_array($d->ESTADO, \App\Models\VerificacionDocumento::DE_LA_CARGA, true) && ($d->PROPUESTA['link'] ?? null))
                                data-descartar="{{ $d->PROPUESTA['link'] }}"
                            @endif>
                            <td><div class="hd-fecha"><span>{{ $d->updated_at?->format('d/m/Y') }}</span><span class="hd-hora">{{ $d->updated_at?->format('h:i A') }}</span></div></td>
                            <td>
                                {{ $tiposDoc[$d->TIPO] ?? ($d->TIPO ?: 'Sin reconocer') }}
                                {{-- El nombre del archivo solo en lo recién soltado: es la única
                                     forma de saber cuál de los treinta PDF es cada fila. --}}
                                @if ($d->ARCHIVO)
                                    <small class="cpdf-sub">{{ \Illuminate\Support\Str::limit($d->ARCHIVO, 28) }}</small>
                                @endif
                            </td>
                            {{-- Un solo dato: serial de chasis, si no el de motor, si no la placa
                                 (VerificacionDocumento::identificador); sin ninguno, la celda vacía. --}}
                            <td><span class="cpdf-ident">{{ $d->identificador() }}</span></td>
                            <td><span class="cpdf-estado {{ $d->ESTADO }}" @if ($d->MOTIVO) title="{{ $d->motivoParaVer() }}" @endif>{{ $estadosDoc[$d->ESTADO] ?? $d->ESTADO }}</span></td>
                            {{-- Solo lo que NO cuadra: a la izquierda lo de la ficha (tachado), a la
                                 derecha lo que dice el PDF. Si todo cuadra, lo leido en gris. Lo que
                                 lee distinto la IA (IA_*) no se tacha: puede equivocarse ella, y se
                                 decide en el visor. Las fechas, dd/mm/aaaa como en todo el sistema.
                                 Cada valor lleva su rótulo, corto ("Ficha" = lo que hay ahora; "Leído"
                                 o "PDF" = lo propuesto): con solo el color no se sabía cuál era cuál
                                 (pedido del cliente, 21-10-2026). --}}
                            <td class="cpdf-ancha">
                                @forelse ($d->DIFERENCIAS ?? [] as $campo => $dif)
                                    @php
                                        $cpdfEsIa = str_starts_with($campo, 'IA_');
                                        // Un valor largo (un titular, una aseguradora) va en letra más chica:
                                        // si no, la fila se hacía de cuatro renglones (pedido del cliente, 22-10-2026).
                                        $cpdfLargo = max(mb_strlen((string) $cpdfVer($dif['ficha'] ?? '')), mb_strlen((string) $cpdfVer($dif['documento'] ?? ''))) > 28;
                                    @endphp
                                    <div class="cpdf-dif{{ $cpdfLargo ? ' largo' : '' }}">
                                        {{-- Sin "(revisión automática)": el rótulo del valor propuesto ya dice de dónde sale. --}}
                                        <span class="cpdf-dif-eti">{{ str_replace(\App\Models\VerificacionDocumento::MARCA_IA, '', \App\Models\VerificacionDocumento::textoParaVer($dif['etiqueta'])) }}:</span>
                                        {{-- Rótulo y valor van juntos (cpdf-dif-par): al partirse la línea
                                             no se quedaba el rótulo arriba y su valor abajo. La flecha va
                                             dentro del segundo, para no quedarse sola en un renglón. --}}
                                        <span class="cpdf-dif-par"><span class="cpdf-dif-rot">Ficha</span>
                                            <span class="cpdf-dif-ficha{{ $cpdfEsIa ? ' ia' : '' }}{{ ($dif['ficha'] ?? '') === '' ? ' vacio' : '' }}">{{ $cpdfVer($dif['ficha'] ?? '') ?: 'vacío' }}</span></span>
                                        <span class="cpdf-dif-par"><i class="material-icons cpdf-flecha">arrow_forward</i><span class="cpdf-dif-rot">{{ $cpdfEsIa ? 'Leído' : 'PDF' }}</span>
                                            <span class="cpdf-dif-doc">{{ $cpdfVer($dif['documento'] ?? '') }}</span></span>
                                    </div>
                                @empty
                                    <span class="cpdf-motivo">{{ $d->motivoParaVer() ?: 'Todo coincide con el documento' }}</span>
                                @endforelse
                                {{-- Un PDF de la carga que no se enlazó solo (no se supo de quién es, lo
                                     leyó la IA, venía con algo que mirar): se enlaza a mano buscando el
                                     equipo por serial, placa o código. El BL va VIN por VIN (abajo),
                                     "otro documento" no se enlaza (se descarta) y lo de varias unidades
                                     (un RACDA, un ROTC o una póliza de flota), unidad por unidad (abajo). --}}
                                @if ($d->ORIGEN === \App\Models\VerificacionDocumento::DE_CARGA_MASIVA
                                     && in_array($d->ESTADO, [\App\Models\VerificacionDocumento::POR_ENGANCHAR, \App\Models\VerificacionDocumento::SIN_FICHA], true)
                                     && $d->TIPO !== \App\Services\CargaMasivaDocumentos::EMBARQUE && ($d->PROPUESTA['link'] ?? null)
                                     && count($d->PROPUESTA['equipos'] ?? []) <= 1 && empty($d->PROPUESTA['flota_rotc']) && !isset($d->PROPUESTA['faltan']))
                                    @can('docs.carga.masiva')
                                        <div class="cpdf-vin-falta">
                                            <button type="button" class="cpdf-vin-btn"
                                                onclick="event.stopPropagation(); window.cpdfAtarDocumento(@js($d->PROPUESTA['link']), @js($d->SERIAL ?: $d->PLACA ?: ''), @js($d->TIPO), @js($tiposDoc[$d->TIPO] ?? $d->TIPO), @js(['emision' => $d->PROPUESTA['emision'] ?? null, 'vence' => $d->PROPUESTA['vence'] ?? null]))" title="Enlazar a un equipo"><i class="material-icons">link</i>Equipo</button>
                                        </div>
                                    @endcan
                                @endif
                                {{-- Un VIN del BL que no casó con ninguna ficha (el serial de la ficha
                                     difiere en algo): se ve aparte y se enlaza a mano buscando el equipo. --}}
                                @if ($d->ORIGEN === \App\Models\VerificacionDocumento::DE_CARGA_MASIVA
                                     && $d->TIPO === \App\Services\CargaMasivaDocumentos::EMBARQUE && ($d->PROPUESTA['link'] ?? null))
                                    @can('docs.carga.masiva')
                                        @foreach ($vinesFaltan[$d->ID_REGISTRO] ?? [] as $vin)
                                            <div class="cpdf-vin-falta">
                                                <span>Falta el VIN {{ $vin }}</span>
                                                <button type="button" class="cpdf-vin-btn"
                                                    onclick="event.stopPropagation(); window.cpdfAtarVin(@js($d->PROPUESTA['link']), @js($vin), @js(['emision' => $d->PROPUESTA['emision'] ?? null, 'vence' => null]))" title="Enlazar a un equipo"><i class="material-icons">link</i>Equipo</button>
                                            </div>
                                        @endforeach
                                    @endcan
                                @endif
                                {{-- Lo de varias unidades (un RACDA, un ROTC o una póliza de flota) que no se enlazó
                                     solo, como los VIN del BL: cada placa o serial que el PDF nombra y no está en
                                     el sistema y, mientras siga sin enlazar, cada equipo suyo que no entró. Se
                                     enlaza a mano (CargaMasivaDocumentos::atarFalta). --}}
                                @if (!empty($unidadesFaltan[$d->ID_REGISTRO]))
                                    @can('docs.carga.masiva')
                                        @foreach ($unidadesFaltan[$d->ID_REGISTRO] as $u)
                                            <div class="cpdf-vin-falta">
                                                <span>{{ $u['suyo'] ? 'Sin enlazar' : 'Falta' }} {{ $u['que'] === 'placa' ? 'la placa' : 'el serial' }} {{ $u['codigo'] }}</span>
                                                <button type="button" class="cpdf-vin-btn"
                                                    onclick="event.stopPropagation(); window.cpdfAtarFalta(@js($d->PROPUESTA['link']), @js($u['codigo']), @js($u['que']), @js($d->TIPO), @js($tiposDoc[$d->TIPO] ?? $d->TIPO), @js(['emision' => $d->PROPUESTA['emision'] ?? null, 'vence' => $d->PROPUESTA['vence'] ?? null]))" title="Enlazar a un equipo"><i class="material-icons">link</i>Equipo</button>
                                            </div>
                                        @endforeach
                                    @endcan
                                @endif
                            </td>
                            <td>
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
                                        onclick="window.cpdfRevisar(@js(['id' => $d->ID_REGISTRO, 'equipoId' => (int) $d->ID_EQUIPO, 'tipo' => $d->TIPO, 'dif' => (object) $d->diferenciasParaVer(), 'estado' => $estadosDoc[$d->ESTADO] ?? $d->ESTADO, 'motivo' => $d->motivoParaVer(), 'inicio' => \App\Models\VerificacionDocumento::inicioDelDocumento($d->LEIDO), 'fiable' => !($d->esLecturaParcial() || $d->sinConfirmar() || $d->esDeOtroVehiculo() || $d->esDocumentoAnterior())]), '/storage/google/{{ $d->DRIVE_ID }}', @js(($tiposDoc[$d->TIPO] ?? '') . ' ' . ($d->PLACA ?: $d->SERIAL ?: '')))">
                                        <i class="material-icons">description</i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cpdf-vacio">{{ $estadoDoc || $tipoDoc || $buscar !== '' || $conFechas ? 'Nada coincide con los filtros.' : 'Todavía no se ha revisado ningún documento.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $docs->links('vendor.pagination.custom-sliding') }}</div>
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
                        <tr class="cpdf-fila-sel" data-id="{{ $f->ID_REGISTRO }}" onclick="window.cpdfSelFila(event, this)">
                            <td><div class="hd-fecha"><span>{{ $f->created_at?->format('d/m/Y') }}</span><span class="hd-hora">{{ $f->created_at?->format('h:i A') }}</span></div></td>
                            <td>{{ $f->DOCUMENTO }}</td>
                            {{-- El serial toma el ancho que sobra: las cifras y el estado quedan juntos a
                                 la derecha, sin huecos blancos entre columnas. --}}
                            <td class="cpdf-ancha">{{ $f->SERIAL ?? '—' }}</td>
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
                        <tr><td colspan="8" class="cpdf-vacio">{{ $estado || $documento || $buscar !== '' || $conFechas ? 'Nada coincide con los filtros.' : 'No hay nada registrado todavía.' }}</td></tr>
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
            {{-- La lectura automática y "Revisar ahora": SOLO super.admin (pedido del cliente,
                 20-10-2026), igual que su ruta. --}}
            @can('super.admin')
            <div class="cpdf-caja cpdf-aviso {{ $activa ? 'ok' : 'apagada' }}">
                <i class="material-icons">{{ $activa ? 'schedule' : 'block' }}</i>
                <div>
                    @if ($activa)
                        <strong>Lectura automática</strong>
                        {{-- Corto a proposito. La zona solo se nombra si el servidor NO esta en la de Venezuela. --}}
                        <span>{{ ucfirst($horarioLectura) }}{{ $zona !== 'America/Caracas' ? ' (' . $zona . ')' : '' }}</span>
                        {{-- A cualquier hora: arranca ya y relee tambien los "No se pudo leer". Una opcion
                             por documento (fuera de la franja solo lee ese) y otra para todos. --}}
                        @if ($lecturaPedida)
                            <span class="cpdf-ahora-pedida">Pedida: {{ $lecturaPedidaTipos ? implode(', ', array_intersect_key(\App\Models\VerificacionDocumento::NOMBRES, array_flip($lecturaPedidaTipos))) : 'todos' }} · {{ \Carbon\Carbon::parse($lecturaPedida)->format('g:i a') }} (en curso).</span>
                        @endif
                        {{-- UN boton desplegable con los cuatro documentos y "Todos". El titulo sale
                             corto ("Titulo"); el nombre entero va en data-nombre (el aviso). Lo abre y
                             cierra el control comun de desplegables (solo uno abierto a la vez). --}}
                        <div class="cpdf-ahora-wrap">
                            <button type="button" class="cpdf-ahora-btn" onclick="window.cpdfToggleAhora()">
                                <i class="material-icons">play_arrow</i>Revisar ahora<i class="material-icons">expand_more</i>
                            </button>
                            <div id="cpdfAhoraMenu" class="cpdf-ahora-menu" style="display: none;">
                                @foreach (\App\Models\VerificacionDocumento::NOMBRES + ['' => 'Todos'] as $tipoAhora => $nombreAhora)
                                    <button type="button" class="cpdf-ahora {{ $tipoAhora === '' ? 'cpdf-ahora-todos' : '' }}" data-nombre="{{ $nombreAhora }}"
                                            onclick="window.cpdfCerrarAhora(); window.cpdfLeerAhora(this, @js($tipoAhora))">
                                        {{ $tipoAhora === \App\Models\VerificacionDocumento::PROPIEDAD ? 'Título' : $nombreAhora }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <strong>Lectura automática apagada</strong>
                        <span>{{ ucfirst($motivoActiva) }}.</span>
                    @endif
                </div>
            </div>
            @endcan
            {{-- Cada tarjeta filtra la lista de abajo: se pulsa y se ve QUE filas son. --}}
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => 'revisar', 'page' => null]) }}">
                <small>Para revisar a mano</small>
                <strong>{{ $docsParaRevisar }}</strong>
                <span>ilegibles, ajenos o sin archivo</span>
            </a>
            <a class="cpdf-caja cpdf-filtra" href="{{ request()->fullUrlWithQuery(['estado_doc' => \App\Models\VerificacionDocumento::POR_ENGANCHAR, 'page' => null]) }}">
                <small>Sin enlazar</small>
                <strong>{{ $resumenDocs[\App\Models\VerificacionDocumento::POR_ENGANCHAR] ?? 0 }}</strong>
                <span>de la carga masiva: reconocidos, no se pudieron enlazar solos</span>
            </a>
            <div class="cpdf-caja">
                <small>Última lectura</small>
                <strong style="font-size:16px;">{{ $ultimaLectura ? \Carbon\Carbon::parse($ultimaLectura)->format('d/m/Y H:i') : 'Todavía no' }}</strong>
                <span>de los 4 tipos</span>
            </div>

            {{-- Por donde va cada documento. Cuando los cuatro digan "listo", termino. --}}
            <div class="cpdf-caja cpdf-avance">
                <small><i class="material-icons">fact_check</i> Por dónde va la revisión</small>
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
                    <span>{{ ucfirst($horarioCompresion) }}{{ $zona !== 'America/Caracas' ? ' (' . $zona . ')' : '' }}</span>
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
@include('admin.historial_documentos.partials.chip_seleccion', ['id' => 'cpdf-selection-chip',
    'alTocar' => 'window.cpdfSelSolo', 'alLimpiar' => 'window.cpdfSelLimpiar',
    'revisado' => $pestana === 'documentos' && auth()->user()->can('docs.carga.masiva'),
    'eliminar' => $puedeDescartar ? 'window.cpdfEliminarElegidas' : null])
@if ($pestana === 'documentos')
    @can('docs.carga.masiva')
        @include('admin.compresion_pdf.partials.atar_modal')
    @endcan
@endif
<script>
    // `texto` con los caracteres que difieren de `otro` dentro de <mark> (en rojo): lo que hay que
    // mirar. Lo usan la caja de enlazar (cada sugerencia frente a lo leído) y el visor de revisión
    // (la ficha frente al documento). Devuelve un fragmento: textContent, sin HTML (sale de un PDF).
    //  · comoServidor: solo letras y cifras, con O/0, I/1 y S/5 iguales, como compara el servidor,
    //    y solo con el mismo largo (un carácter mal leído, no algo escrito a medias).
    //  · si no: carácter a carácter con el mismo largo; con otro largo, lo que queda entre el
    //    principio y el final que comparten; si casi todo difiere, nada. Global: el visor lo usa
    //    desde un escuchador que se monta una sola vez.
    window.cpdfResaltar = function (texto, otro, comoServidor) {
        var t = String(texto == null ? '' : texto), T = t.toUpperCase(), o = String(otro == null ? '' : otro).toUpperCase();
        var distinto = [];
        if (comoServidor) {
            var limpio = function (v) { return v.replace(/[^A-Z0-9]/g, ''); };
            var igual = function (c) { return ({ O: '0', I: '1', S: '5' })[c] || c; };
            var b = limpio(o), j = 0;
            if (b !== '' && limpio(T).length === b.length) {
                for (var i = 0; i < T.length; i++) {
                    if (!/[A-Z0-9]/.test(T[i])) continue;
                    if (igual(T[i]) !== igual(b[j])) distinto[i] = true;
                    j++;
                }
            }
        } else if (o !== '' && T !== o) {
            if (T.length === o.length) {
                for (var k = 0; k < T.length; k++) if (T[k] !== o[k]) distinto[k] = true;
            } else {
                var ini = 0, fin = 0;
                while (ini < T.length && ini < o.length && T[ini] === o[ini]) ini++;
                while (fin < T.length - ini && fin < o.length - ini && T[T.length - 1 - fin] === o[o.length - 1 - fin]) fin++;
                for (var m = ini; m < T.length - fin; m++) distinto[m] = true;
            }
            // Si casi todo difiere no son "el mismo dato mal escrito" sino dos distintos: marcarlo
            // entero en rojo no dice nada.
            var cuantos = distinto.filter(Boolean).length;
            if (cuantos > Math.max(3, T.length * 0.4)) distinto = [];
        }
        // Los tramos seguidos, juntos: un <mark> por tramo, no por letra.
        var frag = document.createDocumentFragment(), tramo = '', marcado = false;
        for (var p = 0; p <= t.length; p++) {
            var d = p < t.length && !!distinto[p];
            if (p === t.length || d !== marcado) {
                if (tramo) {
                    if (marcado) { var mk = document.createElement('mark'); mk.textContent = tramo; frag.appendChild(mk); }
                    else frag.appendChild(document.createTextNode(tramo));
                }
                tramo = ''; marcado = d;
            }
            if (p < t.length) tramo += t[p];
        }
        return frag;
    };

    // ── Elegir filas (contador común partials/chip_seleccion) ───────────────────────────
    // Igual que en el Historial: un clic elige o suelta la fila, el número cuenta las elegidas,
    // tocarlo muestra SOLO esas (?ids=, lo filtra el servidor) y "Limpiar" las suelta. Aquí cada
    // filtro o página vuelve a pintar la pestaña, así que la selección se guarda en
    // window._cpdfSel mientras se siga en la MISMA pestaña; al cambiar de pestaña o salir del
    // módulo empieza de cero. `revisables` son las elegidas que se pueden dar por revisadas y
    // `descartables`, las que se pueden eliminar (id → enlace de su PDF, data-descartar).
    (function () {
        var pestana = @json($pestana), url = new URLSearchParams(location.search);
        var sel = window._cpdfSel;
        if (!sel || sel.pestana !== pestana) sel = window._cpdfSel = { pestana: pestana, ids: new Set(), revisables: new Set(), descartables: new Map(), solo: false };
        sel.solo = url.has('ids') && sel.ids.size > 0;
        if (!window.__cpdfSelSalida) {
            window.__cpdfSelSalida = true;
            // Fuera de estas dos listas (otro módulo o el Historial) la selección se olvida.
            window.addEventListener('spa:contentLoaded', function () {
                if (!document.getElementById('cpdf-selection-chip')) window._cpdfSel = null;
            });
        }
    })();
    // Las elegidas que se pueden dar por revisadas (las cpdf-revisable vistas en alguna página).
    var cpdfMarcadas = function () {
        var sel = window._cpdfSel;
        return sel ? Array.from(sel.ids).filter(function (id) { return sel.revisables.has(id); }) : [];
    };
    // Las elegidas que se pueden eliminar, con el enlace de su PDF: [[id, enlace], ...].
    var cpdfDescartables = function () {
        var sel = window._cpdfSel;
        return sel ? Array.from(sel.ids).filter(function (id) { return sel.descartables.has(id); })
            .map(function (id) { return [id, sel.descartables.get(id)]; }) : [];
    };
    var cpdfSelPintar = function () {
        var sel = window._cpdfSel; if (!sel) return;
        document.querySelectorAll('.cpdf-fila-sel').forEach(function (tr) {
            // Al día con lo que se ve: una fila ya revisada deja de contar para "Revisado".
            if (tr.classList.contains('cpdf-revisable')) sel.revisables.add(tr.dataset.id);
            else sel.revisables.delete(tr.dataset.id);
            if (tr.dataset.descartar) sel.descartables.set(tr.dataset.id, tr.dataset.descartar);
            else sel.descartables.delete(tr.dataset.id);
            tr.classList.toggle('selected-row-maquinaria', sel.ids.has(tr.dataset.id));
        });
        var chip = document.getElementById('cpdf-selection-chip');
        if (!chip) return;
        chip.classList.toggle('active', sel.ids.size > 0);
        document.getElementById('cpdf-selection-chip-cuenta').textContent = sel.ids.size;
        chip.querySelector('.selection-counter').classList.toggle('is-filtering', sel.solo);
        var rev = document.getElementById('cpdf-selection-chip-revisado'), n = cpdfMarcadas().length;
        if (rev) { rev.hidden = !n; rev.lastChild.textContent = ' Revisado' + (n > 1 ? ' (' + n + ')' : ''); }
        var eli = document.getElementById('cpdf-selection-chip-eliminar'), m = cpdfDescartables().length;
        if (eli) { eli.hidden = !m; eli.lastChild.textContent = ' Eliminar' + (m > 1 ? ' (' + m + ')' : ''); }
    };
    // Clic en cualquier parte de la fila la elige o la suelta; los botones y enlaces de la fila
    // (el PDF, enlazar) siguen haciendo lo suyo sin tocar la selección.
    window.cpdfSelFila = function (e, tr) {
        if (e.target.closest('button, a, input')) return;
        var sel = window._cpdfSel, id = tr.dataset.id;
        if (sel.ids.has(id)) sel.ids.delete(id); else sel.ids.add(id);
        if (sel.solo && !sel.ids.size) { sel.solo = false; window.cpdfFiltrar(); return; }
        cpdfSelPintar();
    };
    window.cpdfSelLimpiar = function () {
        var sel = window._cpdfSel; if (!sel) return;
        var estabaSolo = sel.solo;
        sel.ids.clear(); sel.solo = false;
        if (estabaSolo) { window.cpdfFiltrar(); return; }
        cpdfSelPintar();
    };
    window.cpdfSelSolo = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var sel = window._cpdfSel;
        if (!sel || !sel.ids.size) { window.toast('No hay filas elegidas todavía.', 'error'); return; }
        sel.solo = !sel.solo;
        window.cpdfFiltrar();
    };
    cpdfSelPintar();

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
            // "Ver solo las elegidas" (contador de selección): sus id.
            var sel = window._cpdfSel;
            if (sel && sel.solo && sel.ids.size) p.set('ids', Array.from(sel.ids).join(','));
            // Las fechas de Filtros Avanzados (partials/filtro_fechas).
            [['hdFechaDesde', 'fecha_desde'], ['hdFechaHasta', 'fecha_hasta']].forEach(function (par) {
                var f = document.getElementById(par[0]);
                if (f && f.value) p.set(par[1], f.value);
            });
        }
        var url = @json(route('historial-documentos.index')) + (p.toString() ? '?' + p.toString() : '');
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    };

    {{-- "Revisado": con la carga masiva, igual que sus rutas. --}}
    @can('docs.carga.masiva')
    // ── Dar por revisadas varias filas sin abrir el visor ──────────────────────────────
    // De la ficha solo se llenan las fechas que tiene VACIAS y el documento trae
    // (CorrectorFichaDocumento::fechasVacias); lo demas no cambia. Cada fila queda como
    // "Revisado a mano por ...". Las filas se eligen con el contador común (ver "Elegir filas").
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
                    // Se dice lo que de verdad pasó, incluidas las que NO se pudieron revisar y por
                    // qué: antes se contaban todas como hechas aunque alguna hubiera fallado.
                    var txt = (data.revisadas === 1 ? '1 fila revisada' : data.revisadas + ' filas revisadas')
                        + (data.fechas ? ' · ' + (data.fechas === 1 ? '1 fecha puesta' : data.fechas + ' fechas puestas') : '');
                    if (data.fallaron) {
                        txt += ' · ' + (data.fallaron === 1 ? '1 se quedó sin revisar' : data.fallaron + ' se quedaron sin revisar')
                             + (data.motivo ? ' (' + data.motivo + ')' : '');
                    }
                    window.toast(txt, data.fallaron ? 'warning' : 'success');
                    // Las revisadas salen de la selección (ya no hay nada que hacer con ellas).
                    ids.forEach(function (id) { window._cpdfSel.ids.delete(id); });
                    if (!window._cpdfSel.ids.size) window._cpdfSel.solo = false;
                    window.cpdfFiltrar();
                })
                .catch(function (err) {
                    window.toast('No se pudieron marcar como revisadas'
                        + (err && err.message && err.message !== 'sin exito' ? ': ' + err.message : ''), 'error');
                });
        });
    };
    @endcan

    @can('super.admin')
    // "Revisar ahora" (solo super.admin): pide la lectura a cualquier hora (ver VerificarDocumentos::pedirAhora).
    // Con tipo ('propiedad', 'poliza', 'rotc' o 'racda') solo ese documento; '' = todos.
    window.cpdfLeerAhora = function (btn, tipo) {
        btn.disabled = true;
        window.apiFetch(@json(route('compresion-pdf.documentos.leer-ahora')), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ tipo: tipo || null })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error('sin exito');
                window.toast('La revisión de ' + (tipo ? btn.dataset.nombre : 'todos los documentos')
                    + ' arranca en un minuto y sigue hasta que no quede nada', 'success');
                window.cpdfFiltrar();
            })
            .catch(function () {
                btn.disabled = false;
                window.toast('No se pudo pedir la revisión', 'error');
            });
    };
    @endcan

    // ── Carga masiva ──────────────────────────────────────────────────────────────
    // Lo que coincide se enlaza solo al leerlo (no hay botón de Aplicar). Aquí se enlaza a mano
    // lo que no se enlazó solo: un PDF sin enlazar, un VIN del BL o una unidad de un PDF de varias.
    @can('docs.carga.masiva')
    // ── Enlazar a un equipo (caja junto al PDF, partials/atar_modal) ────────────────
    // Lo usan atar un PDF sin enlazar, un VIN del BL y una unidad de un PDF de varias. `o` trae lo que se muestra (doc,
    // lo leído: leidoEti + leido, y las fechas del PDF), el PDF (link, que se abre al lado), con qué arranca el buscador (inicial), el tipo de
    // documento (para avisar de las fichas que ya lo tienen), si un equipo de otro BL se bloquea,
    // y atar(eq, ui), que hace el POST. ui deja a quien ata marcar la fila ocupada, avisar un
    // error o pedir reemplazo.
    // El script de la vista va suelto (global): nombres con prefijo para no chocar con nada.
    // Las fechas (aaaa-mm-dd) se muestran con window.formatearFecha (dom_helpers.js).
    var cpdfAtarEstado = { o: null, turno: 0, espera: null, activo: -1 };
    var cpdfAtarEl = function (id) { return document.getElementById(id); };
    var cpdfAtarAviso = function (texto) {
        var lista = cpdfAtarEl('cpdfAtarLista');
        lista.textContent = '';
        var p = document.createElement('div');
        p.className = 'cpdf-atar-nada';
        p.textContent = texto;
        lista.appendChild(p);
        cpdfAtarEstado.activo = -1;
    };
    // quieto: sin mover el panel (la primera, al llegar las sugerencias: el panel se queda arriba).
    var cpdfAtarMarcar = function (k, quieto) {
        var ops = cpdfAtarEl('cpdfAtarLista').querySelectorAll('.cpdf-atar-op');
        if (!ops.length) return;
        cpdfAtarEstado.activo = (k + ops.length) % ops.length;
        ops.forEach(function (op, n) { op.classList.toggle('activo', n === cpdfAtarEstado.activo); });
        if (!quieto) ops[cpdfAtarEstado.activo].scrollIntoView({ block: 'nearest' });
    };
    var cpdfAtarFila = function (eq, n) {
        var o = cpdfAtarEstado.o, bloqueado = o.bloquearConBl && !!eq.bl;
        var op = document.createElement('div');
        op.className = 'cpdf-atar-op' + (bloqueado ? ' bloqueado' : '');
        op.setAttribute('role', 'option');
        // textContent en todo: los datos vienen de la base y no se interpretan como HTML.
        var el = function (etiqueta, clase, texto) {
            var x = document.createElement(etiqueta);
            if (clase) x.className = clase;
            if (texto != null) x.textContent = texto;
            return x;
        };
        // Como el panel de datos del visor: cada dato con su rótulo arriba y el valor abajo, de dos
        // en dos (la rejilla .cpdf-atar-info).
        // El serial y la placa, con el carácter que NO coincide con lo buscado en rojo: es lo que
        // hay que comparar antes de enlazar. Solo si no es igual (distancia > 0).
        var buscado = eq.distancia > 0 ? cpdfAtarEl('cpdfAtarBuscar').value : '';
        var info = el('div', 'cpdf-atar-info');
        var campo = function (rotulo, valor) {
            if (valor == null || valor === '') return;
            var c = info.appendChild(el('div', 'cpdf-atar-campo'));
            c.appendChild(el('span', 'cpdf-atar-rot', rotulo));
            c.appendChild(el('span', 'cpdf-atar-val')).append(valor);
        };
        campo('Serial', window.cpdfResaltar(eq.serial || ('Equipo #' + eq.id), buscado, true));
        if (eq.placa) campo('Placa', window.cpdfResaltar(eq.placa, buscado, true));
        campo('Código', eq.codigo);
        campo('Tipo', eq.tipo);
        campo('Marca y modelo', eq.nombre);
        // Igual a lo leído, o en qué BL está.
        var marcas = el('div', 'cpdf-atar-marcas');
        if (eq.distancia === -1) {
            var igual = marcas.appendChild(el('span', 'cpdf-atar-et verde'));
            igual.appendChild(el('i', 'material-icons', 'check_circle'));
            igual.appendChild(document.createTextNode('Tiene ese mismo dato'));
        }
        if (bloqueado) marcas.appendChild(el('span', 'cpdf-atar-et rojo', 'Ya está en el BL ' + eq.bl + (eq.bl_desde ? ' desde el ' + window.formatearFecha(eq.bl_desde) : '')));
        if (marcas.childNodes.length) info.appendChild(marcas);
        if (!bloqueado && eq.tiene) {
            // Ya tiene este documento: sus fechas, para ver si el PDF que se enlaza es el MISMO.
            var a = eq.actual || {}, f = o.fechas || {};
            var mismo = (a.emision || a.vence) && (a.emision || null) === (f.emision || null) && (a.vence || null) === (f.vence || null);
            info.appendChild(el('div', 'cpdf-atar-marcas')).appendChild(el('span', 'cpdf-atar-et ' + (mismo ? 'verde' : 'ambar'), mismo ? 'Ya tiene este mismo documento' : 'Ya tiene este documento'));
            var datos = [a.emision && 'emitido el ' + window.formatearFecha(a.emision), a.vence && 'vence el ' + window.formatearFecha(a.vence),
                         a.cargado && 'cargado el ' + window.formatearFecha(a.cargado)].filter(Boolean).join(' · ');
            info.appendChild(el('div', 'cpdf-atar-actual', datos ? 'El que tiene: ' + datos : 'El que tiene no lleva fechas en la ficha.'));
        } else if (!bloqueado && o.tipo) {
            // Enlazando un documento (no un VIN): decir también cuando NO lo tiene, para no confundir
            // la falta de fechas con un dato que no se ve.
            info.appendChild(el('div', 'cpdf-atar-actual', 'No tiene este documento cargado.'));
        }
        var boton = el('button', 'cpdf-atar-btn');
        boton.type = 'button';
        boton.appendChild(el('i', 'material-icons', 'link'));
        var rotulo = boton.appendChild(el('span', null, 'Enlazar'));
        boton.disabled = bloqueado;   // uno que ya está en un embarque no se mueve desde aquí (el servidor tampoco)
        op.appendChild(info); op.appendChild(boton);
        var ui = {
            ocupado: function (si) { boton.disabled = si || bloqueado; rotulo.textContent = si ? 'Enlazando…' : 'Enlazar'; },
            error: function (msg) {
                ui.ocupado(false);
                var viejo = op.querySelector('.cpdf-atar-err'); if (viejo) viejo.remove();
                info.appendChild(el('div', 'cpdf-atar-err', msg));
            },
            // La ficha ya tiene ese documento: se pregunta en la propia fila (sin ventana del navegador).
            pedirReemplazo: function (msg, si) {
                ui.ocupado(false);
                if (op.querySelector('.cpdf-atar-pisar')) return;
                var caja = el('div', 'cpdf-atar-pisar');
                var t = el('span', null, (msg || 'Esa ficha ya tiene ese documento.') + ' ¿Reemplazarlo por este PDF?');
                var b1 = el('button', 'cpdf-atar-btn rojo', 'Reemplazar'); b1.type = 'button';
                var b2 = el('button', 'cpdf-atar-btn gris', 'No'); b2.type = 'button';
                b1.addEventListener('click', function (ev) { ev.stopPropagation(); caja.remove(); ui.ocupado(true); si(); });
                b2.addEventListener('click', function (ev) { ev.stopPropagation(); caja.remove(); });
                caja.appendChild(t); caja.appendChild(b1); caja.appendChild(b2);
                info.appendChild(caja);
            }
        };
        var elegir = function () { if (boton.disabled) return; ui.ocupado(true); o.atar(eq, ui); };
        boton.addEventListener('click', function (ev) { ev.stopPropagation(); elegir(); });
        op.addEventListener('click', function () { cpdfAtarMarcar(n); });
        op._elegir = elegir;
        return op;
    };
    var cpdfAtarBuscar = function () {
        var q = cpdfAtarEl('cpdfAtarBuscar').value.trim(), mio = ++cpdfAtarEstado.turno;
        if (q.length < 3) { cpdfAtarAviso('Escribe al menos 3 caracteres del serial, la placa o el código.'); return; }
        cpdfAtarAviso('Buscando…');
        var url = @json(route('historial-documentos.carga-masiva.buscar-equipo')) + '?q=' + encodeURIComponent(q)
            + (cpdfAtarEstado.o.tipo ? '&tipo=' + encodeURIComponent(cpdfAtarEstado.o.tipo) : '');
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (b) {
                if (mio !== cpdfAtarEstado.turno || !cpdfAtarEstado.o) return;   // llegó una búsqueda más nueva o se cerró
                var equipos = (b && b.equipos) || [];
                if (!equipos.length) { cpdfAtarAviso('Ningún equipo con ese serial, placa o código, ni parecido.'); return; }
                var lista = cpdfAtarEl('cpdfAtarLista');
                lista.textContent = '';
                // Solo los MÁS CERCANOS (vienen ordenados): el igual a lo leído, o los que lo
                // contienen, o el de menos caracteres distintos. El resto, detrás de un enlace,
                // para no tener que escoger entre seis tractores casi iguales.
                var d = equipos[0].distancia;
                var cerca = equipos.filter(function (eq) { return eq.distancia === d; });
                // El aviso de arriba solo cuando NO es igual (el igual lo dice su tarjeta).
                if (d !== -1) {
                    var nota = document.createElement('div');
                    nota.className = 'cpdf-atar-nota ' + (d === 0 ? 'azul' : 'ambar');
                    var ico = document.createElement('i');
                    ico.className = 'material-icons';
                    ico.textContent = d === 0 ? 'search' : 'warning_amber';
                    nota.appendChild(ico);
                    nota.appendChild(document.createTextNode(d === 0 ? 'Equipos que contienen lo escrito.'
                        : 'Ninguno es igual: ' + d + (d === 1 ? ' carácter distinto' : ' caracteres distintos') + ' (en rojo).'));
                    lista.appendChild(nota);
                }
                cerca.forEach(function (eq, n) { lista.appendChild(cpdfAtarFila(eq, n)); });
                var resto = equipos.slice(cerca.length);
                if (resto.length) {
                    var ver = document.createElement('button');
                    ver.type = 'button';
                    ver.className = 'cpdf-atar-ver';
                    ver.textContent = 'No es este: ver ' + resto.length + (resto.length === 1 ? ' parecido más' : ' parecidos más');
                    ver.addEventListener('click', function () {
                        ver.remove();
                        resto.forEach(function (eq, n) { lista.appendChild(cpdfAtarFila(eq, cerca.length + n)); });
                    });
                    lista.appendChild(ver);
                }
                // Queda elegida la primera que SÍ se puede atar (uno de otro BL no): así Enter ata.
                cpdfAtarMarcar(Math.max(0, cerca.findIndex(function (eq) { return !(cpdfAtarEstado.o.bloquearConBl && eq.bl); })), true);
            })
            .catch(function () { if (mio === cpdfAtarEstado.turno) cpdfAtarAviso('No se pudo buscar. Inténtalo de nuevo.'); });
    };
    window.cpdfAtarEscribe = function () { clearTimeout(cpdfAtarEstado.espera); cpdfAtarEstado.espera = setTimeout(cpdfAtarBuscar, 250); };
    window.cpdfAtarTecla = function (ev) {
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); cpdfAtarMarcar(cpdfAtarEstado.activo + (ev.key === 'ArrowDown' ? 1 : -1)); }
        else if (ev.key === 'Enter') {
            ev.preventDefault();
            var op = cpdfAtarEl('cpdfAtarLista').querySelectorAll('.cpdf-atar-op')[cpdfAtarEstado.activo];
            if (op) op._elegir();
        }
    };
    // La caja va al lado del PDF, dentro del visor (#pdfPreviewModal, del layout): se abre el PDF y
    // se monta ahí. Se queda hasta que el visor se cierra (vidalsa:pdf-cerrado: su X, Esc o al
    // enlazar), y entonces vuelve a su casa en la vista, escondida.
    window.cpdfAbrirAtar = function (o) {
        window.openPdfPreview(o.link, o.tipo || @js(\App\Services\CargaMasivaDocumentos::EMBARQUE), o.doc + ' · ' + o.leido, null, '', true);
        var visor = document.getElementById('pdfVisorIzq').parentNode;
        visor.classList.add('cpdf-atar-visor');
        visor.appendChild(cpdfAtarEl('cpdfAtarCaja'));
        cpdfAtarEstado.o = o;
        cpdfAtarEl('cpdfAtarDoc').textContent = o.doc;
        cpdfAtarEl('cpdfAtarLeidoEti').textContent = o.leidoEti;
        cpdfAtarEl('cpdfAtarLeido').textContent = o.leido || '';
        var f = o.fechas || {};
        cpdfAtarEl('cpdfAtarEmision').textContent = f.emision ? window.formatearFecha(f.emision) : '';
        cpdfAtarEl('cpdfAtarVence').textContent = f.vence ? window.formatearFecha(f.vence) : '';
        cpdfAtarEl('cpdfAtarEmisionCampo').hidden = !f.emision;
        cpdfAtarEl('cpdfAtarVenceCampo').hidden = !f.vence;
        var campo = cpdfAtarEl('cpdfAtarBuscar');
        campo.value = o.inicial || '';
        // Con ratón, el cursor ya en el buscador (lo leído seleccionado: se reemplaza al escribir).
        // En el teléfono no: abriría el teclado y taparía el PDF y las sugerencias. preventScroll:
        // el panel se queda arriba (en el teléfono acostado saltaba y escondía el título).
        if (!window.matchMedia('(pointer: coarse)').matches) { campo.focus({ preventScroll: true }); campo.select(); }
        cpdfAtarBuscar();
    };
    // La caja vuelve a su casa (o, si la vista ya no está, se va) y el visor queda como siempre.
    window.cpdfCerrarAtar = function () {
        document.querySelectorAll('.cpdf-atar-visor').forEach(function (v) {
            v.classList.remove('cpdf-atar-visor');
            v.querySelectorAll('.cpdf-atar-caja').forEach(function (c) {
                // A su casa; si la vista ya es otra (y su casa trae su propia caja), se va.
                var casa = document.getElementById('cpdfAtarCasa');
                if (casa && !casa.querySelector('.cpdf-atar-caja')) casa.appendChild(c); else c.remove();
            });
        });
        cpdfAtarEstado.o = null; cpdfAtarEstado.turno++; clearTimeout(cpdfAtarEstado.espera);
    };
    // Una caja que se quedó en el visor de una visita anterior (se navegó con él abierto) se va:
    // la de esta visita tiene los mismos id.
    window.cpdfCerrarAtar();
    // Cerrar el visor cierra la caja; Esc, con la caja montada, cierra el visor. En document y UNA
    // vez: la vista se vuelve a ejecutar en cada visita.
    if (!window.__cpdfAtarVisorBound) {
        window.__cpdfAtarVisorBound = true;
        document.addEventListener('vidalsa:pdf-cerrado', function () {
            if (document.querySelector('.cpdf-atar-visor') && window.cpdfCerrarAtar) window.cpdfCerrarAtar();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.querySelector('.cpdf-atar-visor')) window.closePdfPreview();
        });
    }

    var cpdfAtado = function (r) { window.closePdfPreview(); window.toast(r.message || 'Enlazado', 'success'); window.cpdfFiltrar(); };
    // El atar() de lo que puede pisar un documento que la ficha ya tiene: si el servidor responde
    // requiere_pisar, se pregunta en la fila y, con un sí, se manda otra vez con reemplazar.
    var cpdfAtarPreguntando = function (url, datos) {
        return function (eq, ui) {
            var enviar = function (reemplazar) {
                window.apiPostForm(url, Object.assign({}, datos, { id_equipo: eq.id, reemplazar: reemplazar ? 1 : '' }), 'No se pudo enlazar.')
                    .then(cpdfAtado)
                    .catch(function (e) {
                        if (e && e.requiere_pisar && !reemplazar) { ui.pedirReemplazo(e.message, function () { enviar(true); }); return; }
                        ui.error((e && e.message) || 'No se pudo enlazar.');
                    });
            };
            enviar(false);
        };
    };

    // Enlazar un VIN del BL que falta: el buscador arranca con el propio VIN.
    window.cpdfAtarVin = function (link, vin, fechas) {
        window.cpdfAbrirAtar({ doc: 'BL', leidoEti: 'VIN', leido: vin, fechas: fechas,
            link: link, inicial: vin, tipo: null, bloquearConBl: true, atar: function (eq, ui) {
                window.apiPostForm(@json(route('historial-documentos.carga-masiva.atar-vin')),
                    { link: link, vin: vin, id_equipo: eq.id }, 'No se pudo enlazar.')
                    .then(cpdfAtado)
                    .catch(function (e) { ui.error((e && e.message) || 'No se pudo enlazar.'); });
            } });
    };

    // Enlazar un PDF que no se enlazó solo a la ficha elegida. Si esa ficha ya tiene ese documento,
    // el servidor responde requiere_pisar y se pregunta en la fila antes de reemplazarlo.
    window.cpdfAtarDocumento = function (link, inicial, tipo, doc, fechas) {
        window.cpdfAbrirAtar({ doc: doc, leidoEti: 'Se leyó', leido: inicial || 'Ni placa ni serial', fechas: fechas,
            link: link, inicial: inicial, tipo: tipo, bloquearConBl: false,
            atar: cpdfAtarPreguntando(@json(route('historial-documentos.carga-masiva.atar-documento')), { link: link }) });
    };

    // Enlazar una unidad de un PDF de varias (RACDA, ROTC o póliza de flota) que no se enlazó sola: la
    // placa o el serial que el PDF nombra y no está en el sistema, o uno de sus equipos que no entró.
    // El buscador arranca con ella, como con un VIN del BL.
    window.cpdfAtarFalta = function (link, codigo, que, tipo, doc, fechas) {
        window.cpdfAbrirAtar({ doc: doc, leidoEti: que === 'placa' ? 'Placa' : 'Serial', leido: codigo, fechas: fechas,
            link: link, inicial: codigo, tipo: tipo, bloquearConBl: false,
            atar: cpdfAtarPreguntando(@json(route('historial-documentos.carga-masiva.atar-falta')), { link: link, codigo: codigo }) });
    };

    // "Eliminar" del contador de selección: descarta los PDF elegidos que se pueden descartar (lo
    // subido y no aplicado). Cada PDF se va a la PAPELERA de Drive (se recupera con un clic si fue
    // un error) y su fila desaparece. Uno por uno, con una sola pregunta.
    window.cpdfEliminarElegidas = function () {
        var lista = cpdfDescartables();
        if (!lista.length) return;
        if (!window.confirm('¿Descartar ' + (lista.length === 1 ? 'este PDF' : 'estos ' + lista.length + ' PDF') + '?\n\nSe van a la papelera de Drive y sus filas desaparecen.')) return;
        var ok = 0, fallos = [];
        lista.reduce(function (cadena, par) {
            return cadena.then(function () {
                return window.apiPostForm(@json(route('historial-documentos.carga-masiva.descartar')), { link: par[1] }, 'No se pudo descartar.')
                    .then(function () { ok++; window._cpdfSel.ids.delete(par[0]); window._cpdfSel.descartables.delete(par[0]); })
                    .catch(function (e) { fallos.push((e && e.message) || 'No se pudo descartar.'); });
            });
        }, Promise.resolve()).then(function () {
            if (ok) window.toast(ok === 1 ? 'Descartado' : ok + ' descartados', 'success');
            if (fallos.length) window.toast(fallos[0], 'error');
            window.cpdfFiltrar();
        });
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
        // Dar la fila por revisada va con la carga masiva (igual que su ruta). Sin ella, el visor
        // solo guarda la ficha, como en cualquier otro modulo.
        var PUEDE_REVISAR = @json(auth()->user()->can('docs.carga.masiva'));
        // Diferencia del verificador -> campo del panel del visor que la corrige.
        // Las fechas de emision tienen su campo en el panel (fecha_emision): lo que dice el
        // documento sale debajo con su "Usar", no como un campo aparte. El serial y la placa
        // (solo en el titulo) los elige la persona aqui: la tarea nunca los cambia.
        var CAMPO = { NOMBRE_DEL_TITULAR: 'titular', NRO_DE_DOCUMENTO: 'nro_documento', ID_SEGURO: 'nombre_aseguradora',
                      SERIAL_CHASIS: 'serial_chasis', PLACA: 'placa',
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
        // Mismo valor sin mirar mayusculas ni espacios de los extremos.
        var cpdfMismo = function (a, b) { return String(a).trim().toUpperCase() === String(b).trim().toUpperCase(); };
        // Con `otro`, lo que difiere de él en rojo (window.cpdfResaltar).
        var cpdfValor = function (v, clase, otro) {
            return v == null || v === '' ? cpdfNodo('span', clase + ' cpdf-rev-vacio', 'vacío')
                : cpdfNodo('span', clase, window.cpdfResaltar(cpdfFecha(v), otro == null ? '' : cpdfFecha(otro)));
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
            // Salvo que EMPIECE cuando la ficha o despues: es el vigente, mas corto (poliza de
            // flota prorrateada). Las fechas van aaaa-mm-dd, asi que se comparan como texto.
            var dv = VENCE[window._pdfVerif.tipo] && dif[VENCE[window._pdfVerif.tipo]],
                fv = cont.querySelector('[name="fecha_vencimiento"]'),
                fe = cont.querySelector('[name="fecha_emision"]'),
                inicio = window._pdfVerif.inicio,
                anterior = !!(dv && dv.documento && fv && fv.value
                    && (Date.parse(fv.value) - Date.parse(dv.documento)) / 86400000 > DIAS_ANTERIOR
                    && !(inicio && fe && fe.value && inicio >= fe.value));
            Object.keys(dif).forEach(function (campo) {
                var d = dif[campo] || {}, valor = d.documento == null ? '' : String(d.documento);
                // Lo que lee distinto la IA (IA_*, ver VerificarDocumentos::contrasteIa): las dos
                // opciones, la de la ficha y la de la IA, cada una con su "Usar", igual que con lo
                // del documento (pedido del cliente, 20-10-2026: antes solo se mostraba y no había
                // cómo elegir). NUNCA viene puesta: la IA se equivoca (A30BA2J: el título dice 14 y
                // leyó 28) y lo decide quien mira el PDF. Sin casilla en el panel, solo en la lista.
                var esIa = /^IA_/.test(campo), base = esIa ? campo.slice(3) : campo;
                var input = CAMPO[base] && cont.querySelector('[name="' + CAMPO[base] + '"]');
                if (esIa && !input) return;
                var rotDoc = esIa ? 'Revisión automática:' : 'Documento:';
                // La placa y el serial de un PDF "de otro vehiculo" (diferenciasParaVer): sin su
                // casilla en el panel solo van en la lista de arriba, nunca como campo aparte.
                if (!input && d.solo_ver) return;
                if (!input) { otras.push([campo, d, valor]); return; }
                // textContent, nunca innerHTML: el valor sale de un PDF.
                // Con `otro` (la otra opción), lo que difiere de ella en rojo.
                var pista = function (rotulo, v, otro) {
                    var fila = cpdfNodo('div', 'cpdf-pista', [cpdfNodo('span', '', rotulo),
                        cpdfNodo('b', '', v === '' ? 'vacío' : window.cpdfResaltar(cpdfFecha(v), otro ? cpdfFecha(otro) : ''))]);
                    fila.dataset.valor = v;
                    return fila;
                };
                if (anterior && !esIa) {
                    // Sin boton: es del PDF viejo.
                    var vieja = pista('Documento:', valor);
                    vieja.appendChild(cpdfNodo('em', '', CAMPO[base] === 'fecha_vencimiento' ? 'más vieja que la ficha: PDF anterior' : 'del PDF anterior'));
                    input.insertAdjacentElement('afterend', vieja);
                    return;
                }
                // Lo que la ficha tiene AHORA (el panel lo carga de la base), no lo que tenia al
                // leerse el PDF: si alguien la corrigio despues, la lista de arriba ya no es lo actual.
                var actual = input.value;
                // La fecha de emision que la ficha tiene VACIA viene ya puesta: basta con
                // Guardar (lo pidio el cliente); vaciar el campo = no ponerla.
                if (!esIa && CAMPO[base] === 'fecha_emision' && !input.value) input.value = valor;
                // La aseguradora sin poner llega como "(sin aseguradora)" (ficha_valor null): no es
                // un valor que se pueda volver a poner en el campo.
                var caja = cpdfNodo('div', 'cpdf-opciones'),
                    antes = d.ficha == null || d.ficha_valor === null ? '' : String(d.ficha),
                    opciones = [['Ficha:', actual], [rotDoc, valor]];
                if (cpdfMismo(actual, valor)) {
                    // La ficha ya dice lo del documento. Si al leerse el PDF decia otra cosa (alguien
                    // la corrigio despues), se sigue viendo como estaba, con su "Usar" para volver.
                    opciones = antes !== '' && !cpdfMismo(antes, valor) ? [['Antes en la ficha:', antes], [esIa ? 'Ficha y revisión automática:' : 'Ficha y documento:', valor]] : null;
                }
                if (!opciones) {
                    // Coinciden y siempre coincidieron: nada que elegir.
                    var igual = pista(esIa ? 'Ficha y revisión automática:' : 'Ficha y documento:', valor);
                    igual.appendChild(cpdfNodo('em', 'cpdf-igual', 'iguales'));
                    caja.appendChild(igual);
                } else {
                    // Las dos, cada una con su boton: decide quien mira el PDF (pedido del cliente,
                    // 19-10-2026). La que esta puesta en el campo sale marcada "En uso".
                    opciones.forEach(function (op, n) {
                        var fila = pista(op[0], op[1], opciones[1 - n][1]), usar = document.createElement('button');
                        usar.type = 'button';
                        usar.onclick = function () { input.value = op[1]; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); };
                        fila.appendChild(usar);
                        caja.appendChild(fila);
                    });
                    var marcar = function () {
                        caja.querySelectorAll('.cpdf-pista').forEach(function (fila) {
                            var enUso = cpdfMismo(fila.dataset.valor, input.value);
                            fila.classList.toggle('en-uso', enUso);
                            fila.querySelector('button').textContent = enUso ? 'En uso' : 'Usar';
                        });
                    };
                    input.addEventListener('input', marcar);
                    input.addEventListener('change', marcar);
                    marcar();
                }
                input.insertAdjacentElement('afterend', caja);
            });
            var aviso = document.createElement('div'), v = window._pdfVerif;
            aviso.className = 'cpdf-pista-aviso';
            // Lo MISMO que la fila de la tabla: que concluyo la revision y, dato a dato, que dice
            // la ficha y que dice el documento. Para comparar sin salir del visor.
            aviso.appendChild(cpdfNodo('div', 'cpdf-rev-cab', [cpdfNodo('small', '', 'Revisión'), cpdfNodo('span', 'cpdf-rev-estado', v.estado || '')]));
            // Los datos que llevan su propio campo abajo (.cpdf-extra) no se repiten en la lista.
            var enExtra = {};
            otras.forEach(function (o) { enExtra[o[0]] = true; });
            var lista = cpdfNodo('div', 'cpdf-rev-lista');
            // Lo que lee la IA va con el nombre del dato, sin "(revisión automática)": la tarjeta
            // ya enseña la ficha y lo leído, que es lo que hace falta para decidir (pedido del
            // cliente, 21-10-2026). Solo si el mismo dato sale también del documento se le deja
            // la marca, para no tener dos filas iguales (las etiquetas llegan ya con el nombre de
            // hoy: VerificacionDocumento::diferenciasParaVer).
            var etiquetas = Object.keys(dif).filter(function (c) { return !enExtra[c]; })
                .map(function (c) { return (dif[c] || {}).etiqueta || c; });
            var MARCA_IA = @json(\App\Models\VerificacionDocumento::MARCA_IA);
            var sinIa = function (e) { return String(e).split(MARCA_IA).join(''); };
            Object.keys(dif).forEach(function (campo) {
                if (enExtra[campo]) return;
                var d = dif[campo] || {}, etiqueta = d.etiqueta || campo;
                if (/^IA_/.test(campo)) etiqueta = sinIa(etiqueta) + (etiquetas.indexOf(sinIa(etiqueta)) === -1 ? '' : MARCA_IA);
                lista.appendChild(cpdfNodo('div', 'cpdf-rev-dif', [
                    cpdfNodo('div', 'cpdf-rev-campo', etiqueta),
                    cpdfNodo('div', 'cpdf-rev-val', [cpdfValor(d.ficha, 'cpdf-rev-ficha', d.documento), cpdfIcono('arrow_forward'), cpdfValor(d.documento, 'cpdf-rev-doc', d.ficha)]),
                ]));
            });
            // El motivo, corto: "de otro vehiculo" ya trae los dos seriales/placas, que salen en la
            // lista de abajo; repetirlos alargaba la tarjeta (pedido del cliente, 19-10-2026). Por
            // lo mismo se quita "La revisión automática lee distinto: <datos>" (el de las filas de
            // antes ya llega traducido, ver motivoParaVer): esos datos son las filas de abajo.
            var motivo = v.motivo || '';
            if (lista.childNodes.length) {
                if (/^El documento es de otro vehiculo/.test(motivo)) motivo = 'Es de otro vehículo';
                motivo = motivo.replace(/(^|\.\s*)La revisión automática lee distinto:.*$/, '').trim();
            }
            if (motivo) aviso.appendChild(cpdfNodo('div', 'cpdf-rev-motivo', motivo));
            if (lista.childNodes.length) aviso.appendChild(lista);

            // Lo que el panel de ese documento no tiene como campo propio: un campo aparte,
            // relleno con lo que dice el documento SOLO si la lectura es fiable (ver abajo). Al GUARDAR se pone en la
            // ficha lo que diga el campo, sin casilla que marcar (lo pidio el cliente); vaciarlo =
            // no ponerlo. Sin esto se perderia al dar la fila por revisada.
            // Del PDF ANTERIOR no se ofrece nada: es el viejo y dejaria la ficha peor.
            if (!anterior) otras.forEach(function (o) {
                var campo = o[0], d = o[1], valorDoc = o[2];
                var inp = document.createElement('input');
                inp.type = /^FECHA_/.test(campo) ? 'date' : 'text';
                // Con la lectura NO fiable (se leyó a medias, o no se confirmó de qué vehículo es)
                // el campo sale VACÍO, no con lo que creyó leer. Salía relleno y bastaba pulsar
                // Guardar para meter en la ficha un nombre cortado a la mitad — justo lo que el
                // sistema se niega a hacer solo porque la dejaría peor de como está. Quien lo
                // sepa lo escribe mirando el PDF, que para eso lo tiene delante.
                if (v.fiable) {
                    inp.value = valorDoc;
                } else {
                    inp.placeholder = 'Escríbelo mirando el PDF';
                }
                aviso.appendChild(cpdfNodo('div', 'cpdf-extra', [
                    cpdfNodo('div', 'cpdf-extra-tit', d.etiqueta || campo),
                    // Con la lectura no fiable el campo va vacío, así que el rótulo no puede
                    // anunciar un "documento:" que no está: ahí se dice lo que hay que hacer, y lo
                    // que el sistema creyó leer lo cuenta la nota de abajo.
                    cpdfNodo('div', 'cpdf-extra-ficha', 'Ficha: ' + (d.ficha ? cpdfFecha(d.ficha) : 'vacío')
                        + (v.fiable ? ' · documento:' : ' · escríbelo tú:')),
                    inp,
                    // Si no se confirmo de que vehiculo es (o se leyo a medias), se avisa: quien
                    // guarda debe haberlo visto en el PDF.
                    cpdfNodo('div', 'cpdf-extra-nota' + (v.fiable ? '' : ' cpdf-extra-ojo'), v.fiable
                        ? 'Se pone en la ficha al guardar.'
                        : 'Lectura NO segura: el sistema leyó «' + (valorDoc || '—') + '», pero puede estar a medias. ' +
                          'Escríbelo tú mirando el PDF, o déjalo vacío para no tocar la ficha.'),
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
            // Sigue pudiéndose elegir (como toda fila), pero ya no cuenta para "Revisado".
            tr.classList.remove('cpdf-revisable');
            tr.classList.add('cpdf-recien-revisada');
            cpdfSelPintar();
        };

        document.addEventListener('vidalsa:metadata-guardada', function (e) {
            if (!PUEDE_REVISAR || !esEste(e.detail)) return;
            // El aviso lo da esta pantalla al terminar (uno solo): el del visor se cancela.
            e.preventDefault();
            var id = window._pdfVerif.id, campos = {};
            (window._pdfVerif.extras || []).forEach(function (x) { if (x.input.value.trim() !== '') campos[x.campo] = x.input.value.trim(); });
            window._pdfVerif = null;
            // El botón de guardar del visor sigue girando hasta que la fila quede revisada.
            e.detail.esperar(window.apiFetch(URL_REVISADO.replace(/\/0\/revisado$/, '/' + id + '/revisado'), {
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
                }));
        });
    }
</script>
