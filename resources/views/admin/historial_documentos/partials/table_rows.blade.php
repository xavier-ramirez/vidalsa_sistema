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
        'FECHA_VENC_POLIZA' => 'Fecha Póliza',
        'FECHA_ROTC'        => 'Fecha ROTC',
        'FECHA_RACDA'       => 'Fecha RACDA',
        'FECHA_ADICIONAL'   => 'Fecha Certificado',
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
    ];
    // Un valor de 'cambios' tal como se lee: null/'' = vacío (se pinta aparte), 0/1 de un
    // campo sí/no (antes y después 0/1) = "No"/"Sí" y las fechas aaaa-mm-dd como dd/mm/aaaa. Una vez, no por cambio.
    $hdValor = function ($v, bool $esSiNo) {
        if ($v === null || $v === '') return null;
        if ($esSiNo) return in_array($v, [1, '1', true], true) ? 'Sí' : 'No';
        if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: 00:00:00)?$/', $v, $f)) return "$f[3]/$f[2]/$f[1]";
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    };
    $hdSiNo = [0, 1, '0', '1', true, false, null, ''];
@endphp
@forelse ($events as $event)
    <tr class="hd-selectable-row {{ !empty($event->cambios) ? 'hd-has-cambios' : '' }}" data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}">
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
            @if(!empty($event->cambios))
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
                            @foreach($event->cambios as $campo => $val)
                                @continue($campo === '_origen')
                                @php
                                    $esDiff = is_array($val) && array_key_exists('antes', $val);
                                    $rawAntes = $esDiff ? $val['antes'] : null;
                                    $rawDespues = $esDiff ? $val['despues'] : $val;
                                    $esSiNo = $esDiff && in_array($rawAntes, $hdSiNo, true) && in_array($rawDespues, $hdSiNo, true);
                                    $antes = $hdValor($rawAntes, $esSiNo);
                                    $despues = $hdValor($rawDespues, $esSiNo);
                                @endphp
                                <div class="hd-cambio">
                                    <div class="hd-cambio-campo">{{ $hdFieldMap[$campo] ?? ucwords(strtolower(str_replace('_', ' ', $campo))) }}</div>
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
                    <button type="button" class="pdf-doc-btn" onclick="openPdfPreview('{{ $event->link }}', '{{ $event->doc_key }}', '{{ $event->tipo }}', '{{ $event->equipo_db_id ?? '' }}')" title="Ver PDF">
                        <i class="material-icons">description</i>
                    </button>
                @endif
                @can('super.admin')
                    {{-- Eliminar registro del historial. Solo los de AUDITORÍA se borran de verdad;
                         doc/vehículo el backend (deleteRegistro) los bloquea con un mensaje. --}}
                    <button type="button" class="btn-hd-del"
                            onclick="window.hdDeleteRegistro('{{ $event->del_source ?? '' }}', '{{ $event->del_id ?? '' }}', this)"
                            title="Eliminar registro del historial">
                        <i class="material-icons" style="font-size: 16px;">delete</i>
                    </button>
                @endcan
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
