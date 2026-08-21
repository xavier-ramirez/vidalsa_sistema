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
    ];
@endphp
@forelse ($events as $event)
    <tr class="hd-selectable-row {{ !empty($event->cambios) ? 'hd-has-cambios' : '' }}" data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}">
        <td>
            <div style="display: flex; flex-direction: column;">
                <span style="font-weight: 600;">{{ $event->fecha->format('d/m/Y') }}</span>
                <span style="font-size: 12px; color: #94a3b8;">{{ $event->fecha->format('h:i A') }}</span>
            </div>
        </td>
        <td>
            <span class="badge-autor">
                <i class="material-icons" style="font-size: 16px;">person</i>
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
            <div style="font-weight: 600; color: #334155; line-height: 1.3;">{{ $event->equipo_nombre }}</div>
            @if($event->equipo_id)<div style="font-size: 12px; color: #475569; font-weight: 600;">{{ $event->equipo_id }}</div>@endif
            @if(!empty($event->cambios))
                <span class="hd-ver-cambios-chip" style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;color:#0067b1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 6px;margin-top:4px;user-select:none;">
                    <i class="material-icons" style="font-size:12px;line-height:1;">history</i>
                    ver cambios
                </span>
                <div class="hd-cambios-detail" style="display:none;margin-top:8px;">
                    <div style="background:#1e293b;border-radius:10px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                        <div style="padding:8px 12px;display:flex;align-items:center;gap:6px;border-bottom:1px solid #334155;">
                            <i class="material-icons" style="font-size:14px;color:#38bdf8;">history</i>
                            <span style="font-size:11px;font-weight:700;color:#cbd5e1;text-transform:uppercase;letter-spacing:0.5px;">Cambios realizados</span>
                            <i class="material-icons hd-close-cambios" style="margin-left:auto;font-size:18px;color:#94a3b8;cursor:pointer;" onclick="event.stopPropagation();window.hdCerrarCambios();">close</i>
                        </div>
                        <div style="padding:8px 12px;display:flex;flex-direction:column;gap:6px;">
                            @foreach($event->cambios as $campo => $val)
                                @php
                                    $label = $hdFieldMap[$campo] ?? ucwords(strtolower(str_replace('_', ' ', $campo)));
                                    $esDiff = is_array($val) && array_key_exists('antes', $val);
                                    $humanize = function($v) {
                                        if ($v === 0 || $v === '0' || $v === false) return 'No';
                                        if ($v === 1 || $v === '1' || $v === true) return 'Sí';
                                        return $v;
                                    };
                                    $rawAntes = $esDiff ? $val['antes'] : null;
                                    $rawDespues = $esDiff ? $val['despues'] : $val;
                                    $ambosBool = $esDiff
                                        && in_array($rawAntes, [0, 1, '0', '1', true, false, null, ''], true)
                                        && in_array($rawDespues, [0, 1, '0', '1', true, false, null, ''], true);
                                    $antes = $esDiff
                                        ? ($rawAntes !== null && $rawAntes !== '' ? ($ambosBool ? $humanize($rawAntes) : $rawAntes) : '(vacío)')
                                        : null;
                                    $despues = $esDiff
                                        ? ($rawDespues !== null && $rawDespues !== '' ? ($ambosBool ? $humanize($rawDespues) : $rawDespues) : '(vacío)')
                                        : ($ambosBool ? $humanize($rawDespues) : $rawDespues);
                                @endphp
                                <div style="border-bottom:1px solid rgba(255,255,255,0.06);padding-bottom:6px;">
                                    <div style="font-weight:700;color:#cbd5e1;text-transform:uppercase;font-size:10px;margin-bottom:4px;letter-spacing:0.5px;">{{ $label }}</div>
                                    @if($antes !== null)
                                        <div style="display:flex;flex-direction:column;gap:3px;">
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <span style="background:#475569;color:#e2e8f0;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;flex-shrink:0;">ANTES</span>
                                                <span style="color:#94a3b8;text-decoration:line-through;font-size:12px;word-break:break-word;">{{ $antes }}</span>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <span style="background:#0067b1;color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;flex-shrink:0;">NUEVO</span>
                                                <span style="color:#ffffff;font-weight:700;font-size:12px;word-break:break-word;">{{ $despues }}</span>
                                            </div>
                                        </div>
                                    @else
                                        <span style="color:#ffffff;font-size:12px;word-break:break-word;">{{ $despues }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </td>
        <td style="text-align: center;">
            <div style="display:inline-flex;align-items:center;gap:6px;justify-content:center;">
                @if($event->link)
                    <button type="button" class="btn-view-pdf" onclick="openPdfPreview('{{ $event->link }}', '{{ $event->doc_key }}', '{{ $event->tipo }}', '{{ $event->equipo_db_id ?? '' }}')" title="Visualizar Documento">
                        <i class="material-icons" style="font-size: 20px;">picture_as_pdf</i>
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
