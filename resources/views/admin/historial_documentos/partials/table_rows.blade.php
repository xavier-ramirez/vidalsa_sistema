@php
    // Etiquetas legibles de los campos de 'cambios'. Las claves son los nombres REALES
    // de columna que registra el EquipoObserver (getChanges → columnas de la tabla) y los
    // logs de metadata de documentación. Definido UNA vez aquí (antes era por cada cambio
    // de cada evento). ID_FRENTE_ACTUAL/DETALLE_UBICACION_ACTUAL/CONFIRMADO_EN_SITIO se
    // filtran en el controlador (movilizaciones/confirmaciones), por eso no están aquí.
    $hdFieldMap = [
        // Columnas de EQUIPO
        'MARCA'             => 'Marca',
        'MODELO'            => 'Modelo',
        'SERIAL_CHASIS'     => 'Serial de Chasis',
        'SERIAL_DE_MOTOR'   => 'Serial de Motor',
        'CODIGO_PATIO'      => 'Código de Patio',
        'NUMERO_ETIQUETA'   => 'Número de Etiqueta',
        'CATEGORIA_FLOTA'   => 'Categoría de Flota',
        'ANIO'              => 'Año',
        'COLOR'             => 'Color',
        'CAPACIDAD'         => 'Capacidad',
        'LINK_GPS'          => 'GPS',
        'ESTADO_OPERATIVO'  => 'Estatus',
        'id_tipo_equipo'    => 'Tipo',
        'FOTO_EQUIPO'       => 'Foto',
        'OBSERVACIONES'     => 'Observaciones',
        // Columnas de DOCUMENTACIÓN (logs de metadata)
        'PLACA'             => 'Placa',
        'ID_SEGURO'         => 'Aseguradora',
        'FECHA_VENC_POLIZA' => 'Vencimiento de la póliza',
        'FECHA_ROTC'        => 'Vencimiento del ROTC',
        'FECHA_RACDA'       => 'Vencimiento del RACDA',
        'FECHA_ADICIONAL'   => 'Vencimiento del certificado',
        'FECHA_ADICIONAL_2' => 'Fecha de la compraventa',
        'FECHA_VENCIMIENTO_CERT' => 'Vencimiento del certificado',
        'NUMERO_POLIZA'     => 'Nº Póliza',
        'NUMERO_ROTC'       => 'Nº ROTC',
        'NUMERO_RACDA'      => 'Nº RACDA',
        // Lo que escribe la verificación de documentos (docs:verificar-documentos). Sin esto
        // salían con el nombre de la columna en crudo ("Fecha Emision Poliza").
        'NOMBRE_DEL_TITULAR'      => 'Propietario',
        'FECHA_EMISION_PROPIEDAD' => 'Emisión del título',
        'FECHA_EMISION_POLIZA'    => 'Emisión de la póliza',
        'FECHA_EMISION_ROTC'      => 'Emisión del ROTC',
        'FECHA_EMISION_RACDA'     => 'Emisión del RACDA',
        '_origen'                 => 'Lo hizo',
        // Los demás que se guardan en el historial (vistos en la base el 20-10-2026). Sin esto
        // salían en crudo: "Rotc Gestion Frente Id", "Nro De Documento"…
        'NRO_DE_DOCUMENTO'        => 'Nº de documento',
        'COMBUSTIBLE'             => 'Combustible',
        'CONSUMO_PROMEDIO'        => 'Consumo promedio',
        'ID_ESPEC'                => 'Ficha del catálogo (nº)',
        'SERIAL'                  => 'Serial',
        'CODIGO_INTERNO'          => 'Código interno',
        'ID_EQUIPO_HOST'          => 'Anclado al equipo (nº)',
        'TIPO'                    => 'Tipo',
        'rotc_gestion_frente_id'  => 'Frente que tramita el ROTC (nº)',
        'rotc_gestion_fecha'      => 'Fecha del trámite del ROTC',
        'origen'                  => 'Cómo se cargó',
        'valor'                   => 'Detalle',
        'bl'                      => 'BL',
        'vin'                     => 'VIN',
        'serial_chasis'           => 'Serial de Chasis',
        'codigo_patio'            => 'Código de Patio',
        'etiqueta'                => 'Corrección',
        'accion'                  => 'Acción',
    ];
    // Valores que se guardan como clave y se leen mejor con palabras.
    $hdValorMap = ['accion' => ['restore' => 'Restaurado de la papelera']];
    // El PDF de cada documento: su enlace de Drive no le dice nada a nadie ("/storage/google/1vNH…"),
    // así que en vez del enlace se dice QUÉ pasó con el PDF. Para verlo está el botón de la fila.
    $hdPdfMap = [
        'LINK_DOC_PROPIEDAD'   => 'PDF del título',
        'LINK_POLIZA_SEGURO'   => 'PDF de la póliza',
        'LINK_ROTC'            => 'PDF del ROTC',
        'LINK_RACDA'           => 'PDF del RACDA',
        'LINK_DOC_ADICIONAL'   => 'PDF del certificado',
        'LINK_DOC_ADICIONAL_2' => 'PDF de la compraventa',
        'LINK_CERTIFICADO'     => 'PDF del certificado',
    ];
    // Quién y cuándo se subió un PDF (*_SUBIDO_POR, *_FECHA_SUBIDA) ya lo dicen las columnas
    // Usuario y Fecha de la fila; en la burbuja salían como "vacío → Sí" (el id del usuario 1
    // leído como sí/no) y "2026-10-20T19:19:34.806235Z".
    // Tampoco lo que es de uso interno: el id del archivo en Drive (archivo, archivo_url), el
    // nº del anexo, la marca de un borrado masivo o de una restauración (eso ya lo dice el tipo
    // de la fila) y la etiqueta del auxiliar (sale en la columna del equipo).
    $hdOculto = fn ($campo) => in_array($campo, ['_origen', 'archivo', 'archivo_url', 'anexo', 'bulk', 'deleted_at', 'deleted_by', '_aux_label'], true)
        || preg_match('/_(SUBIDO_POR|FECHA_SUBIDA)$/', $campo);
    // Un valor de 'cambios' tal como se lee: null/'' = vacío (se pinta aparte), 0/1 de un
    // campo sí/no (antes y después 0/1) = "No"/"Sí" y las fechas aaaa-mm-dd como dd/mm/aaaa. Una vez, no por cambio.
    $hdValor = function ($v, bool $esSiNo) {
        if ($v === null || $v === '') return null;
        if ($esSiNo) return in_array($v, [1, '1', true], true) ? 'Sí' : 'No';
        if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]00:00:00(?:\.0+)?Z?)?$/', $v, $f)) return "$f[3]/$f[2]/$f[1]";
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    };
    $hdSiNo = [0, 1, '0', '1', true, false, null, ''];
@endphp
@forelse ($events as $event)
    @php
        // Solo los cambios que se enseñan: si únicamente cambió lo oculto, la fila va sin burbuja.
        // Un dato suelto (sin antes/después) que viene vacío no dice nada: tampoco se enseña.
        $hdCambios = array_filter((array) ($event->cambios ?? []),
            fn ($v, $campo) => !$hdOculto($campo) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
    @endphp
    {{-- data-del-*: lo que hay que mandar para eliminar el registro (el "Eliminar" del contador de
         selección). Solo para super.admin y solo los de AUDITORÍA: una subida de documento o la
         creación de un vehículo no se borran desde aquí (deleteRegistro también los bloquea). --}}
    <tr class="hd-selectable-row {{ $hdCambios ? 'hd-has-cambios' : '' }}" data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}"
        @if (auth()->user()->can('super.admin') && !empty($event->del_source) && !in_array($event->del_source, ['doc', 'equipo_creacion'], true))
            data-del-source="{{ $event->del_source }}" data-del-id="{{ $event->del_id }}"
        @endif>
        <td>
            <div class="hd-fecha">
                <span>{{ $event->fecha->format('d/m/Y') }}</span>
                <span class="hd-hora">{{ $event->fecha->format('h:i A') }}</span>
            </div>
        </td>
        <td>
            <span class="badge-autor" @if(!empty($event->autor_nombre)) title="{{ $event->autor_nombre }}" @endif>
                <i class="material-icons">person</i>
                {{ $event->autor }}
            </span>
        </td>
        <td>
            <span class="badge-doc">
                <i class="material-icons">description</i>
                {{ $event->tipo }}
            </span>
        </td>
        <td>
            <div class="hd-equipo-nombre">{{ $event->equipo_nombre }}</div>
            @if($event->equipo_id)<div class="hd-equipo-id">{{ $event->equipo_id }}</div>@endif
            @if($hdCambios)
                {{-- Sin rótulo: la burbuja sale al pasar el ratón por la fila (en el
                     teléfono, al tocar la tarjeta). Ver hdAbrir en index.blade.php; el estilo
                     (.hd-cambios-*) vive allí. --}}
                <div class="hd-cambios-detail" style="display:none;">
                    <div class="hd-cambios-caja">
                        <div class="hd-cambios-cab">
                            <i class="material-icons">history</i>
                            <span>Cambios realizados</span>
                            <i class="material-icons hd-close-cambios" onclick="event.stopPropagation();window.hdCerrarCambios();">close</i>
                        </div>
                        <div class="hd-cambios-lista">
                            @foreach($hdCambios as $campo => $val)
                                @php
                                    $esDiff = is_array($val) && array_key_exists('antes', $val);
                                    $rawAntes = $esDiff ? $val['antes'] : null;
                                    $rawDespues = $esDiff ? $val['despues'] : $val;
                                    $esPdf = isset($hdPdfMap[$campo]);
                                    if ($esPdf) {
                                        // Sin enlaces: lo que pasó con el PDF, en una frase.
                                        $vacioA = $rawAntes === null || $rawAntes === '';
                                        $vacioD = $rawDespues === null || $rawDespues === '';
                                        $esDiff = false;
                                        $antes = null;
                                        $despues = $vacioD ? 'Se quitó el PDF' : ($vacioA ? 'Se cargó el PDF' : 'Se reemplazó por uno nuevo');
                                    } else {
                                        $esSiNo = $esDiff && in_array($rawAntes, $hdSiNo, true) && in_array($rawDespues, $hdSiNo, true);
                                        $antes = $hdValor($rawAntes, $esSiNo);
                                        $despues = $hdValor($rawDespues, $esSiNo);
                                        $antes = $hdValorMap[$campo][$antes] ?? $antes;
                                        $despues = $hdValorMap[$campo][$despues] ?? $despues;
                                    }
                                @endphp
                                <div class="hd-cambio">
                                    <div class="hd-cambio-campo">{{ $hdPdfMap[$campo] ?? $hdFieldMap[$campo] ?? ucwords(strtolower(str_replace('_', ' ', $campo))) }}</div>
                                    <div class="hd-cambio-valores">
                                        @if($esDiff)
                                            <span class="hd-cambio-antes {{ $antes === null ? 'hd-cambio-vacio' : '' }}">{{ $antes ?? 'vacío' }}</span>
                                            <i class="material-icons">arrow_forward</i>
                                        @endif
                                        <span class="hd-cambio-nuevo {{ $despues === null ? 'hd-cambio-vacio' : '' }}">{{ $despues ?? 'vacío' }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if(!empty($event->cambios['_origen']) && is_string($event->cambios['_origen']))
                            <div class="hd-cambios-pie">{{ $hdFieldMap['_origen'] }}: {{ $event->cambios['_origen'] }}</div>
                        @endif
                    </div>
                </div>
            @endif
        </td>
        <td style="text-align: center;">
            <div style="display:inline-flex;align-items:center;gap:6px;justify-content:center;">
                @if($event->link)
                    <button type="button" class="pdf-doc-btn" onclick="openPdfPreview('{{ addslashes($event->link) }}', '{{ $event->doc_key }}', '{{ addslashes($event->tipo ?? '') }}', '{{ $event->equipo_db_id ?? '' }}')" title="Ver PDF">
                        <i class="material-icons">description</i>
                    </button>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
            <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                <i class="material-icons" style="font-size: 48px; opacity: 0.3; margin: 0 auto;">inbox</i>
                <span>No hay registro de documentos actualizados todavía.</span>
            </div>
        </td>
    </tr>
@endforelse
