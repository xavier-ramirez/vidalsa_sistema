{{-- Contenido del modal de detalle/recepción. Renderizado via AJAX desde
     TraspasoController@show (wantsJson). Variables: $traspaso, $puedeRecibir. --}}
@php
    $em = \App\Models\Traspaso::ESTADOS_META[$traspaso->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
    $puedeEnviar   = $traspaso->esBorrador()  && auth()->user()?->can('almacen.movimiento');
    $puedeCancelar = !$traspaso->esFinal() && auth()->user()?->can('almacen.movimiento');
    $neNumero = $traspaso->REFERENCIA ?: $traspaso->NUMERO;

    // Frente vs Destino: en almacenes de PROYECTO el nombre del almacén destino y el del
    // frente suelen coincidir → no repetir la fila "Frente" cuando dice lo mismo que
    // "Destino". La comparación (tolerante a tildes/mayúsculas/espacios) vive en el modelo,
    // que es la misma fuente que usa la bandeja de recepción.
    $frenteRedundante = $traspaso->frenteDestinoEsRedundante();
@endphp

<div class="dtm-header">
    <div class="dtm-title-row">
        <i class="material-icons dtm-title-icon">receipt_long</i>
        <span class="dtm-numero">{{ $neNumero }}</span>
        {{-- El pill de estado solo se muestra cuando NO está "En tránsito" (en la bandeja
             ya se sabe que está en tránsito; el cliente pidió quitar ese pill del modal).
             Para notas confirmadas/canceladas (vistas vía "Todas") sí se muestra. --}}
        @unless($traspaso->esEnviado())
        <span class="estado-pill" style="background:{{ $em[1] }};color:{{ $em[2] }};">{{ $em[0] }}</span>
        @endunless
        <button type="button" class="dtm-close" onclick="window.trCloseModal()" title="Cerrar">
            <i class="material-icons">close</i>
        </button>
    </div>

    <div class="dtm-meta">
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Origen</span>
            <span class="dtm-meta-value">{{ optional($traspaso->almacenOrigen)->NOMBRE }}</span>
        </div>
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Destino</span>
            <span class="dtm-meta-value">{{ optional($traspaso->almacenDestino)->NOMBRE }}</span>
        </div>
        @unless($frenteRedundante)
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Frente</span>
            <span class="dtm-meta-value">{{ optional($traspaso->frenteDestino)->NOMBRE_FRENTE ?: '—' }}</span>
        </div>
        @endunless
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Despachado</span>
            <span class="dtm-meta-value">
                {{ optional($traspaso->usuarioEnvio)->NOMBRE_COMPLETO ?: optional($traspaso->usuarioCreo)->NOMBRE_COMPLETO ?: '—' }}
                <span class="dtm-sub">{{ $traspaso->FECHA_ENVIO?->format('d/m/Y h:i A') }}</span>
            </span>
        </div>
        {{-- Quién confirmó la recepción (ID_USUARIO_RECEPCION) + cuándo. Solo cuando ya
             está confirmada (RECIBIDO / RECIBIDO_PARCIAL). El dato ya queda registrado en
             el traspaso y en el movimiento TRASPASO_ENTRADA del kardex. --}}
        @if($traspaso->esRecibido())
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Confirmado por</span>
            <span class="dtm-meta-value">
                {{ optional($traspaso->usuarioRecepcion)->NOMBRE_COMPLETO ?: '—' }}
                <span class="dtm-sub">{{ $traspaso->FECHA_RECEPCION?->format('d/m/Y h:i A') }}</span>
            </span>
        </div>
        @endif
    </div>
</div>

<div class="dtm-body">
    @if($traspaso->NOTAS)
        <div class="dtm-notas">
            <i class="material-icons" style="font-size:14px;color:#b45309;">sticky_note_2</i>
            <span>{{ $traspaso->NOTAS }}</span>
        </div>
    @endif

    <div class="dtm-lineas-header">
        <span>Materiales</span>
        @if($puedeRecibir)<span class="dtm-rec-hint"><i class="material-icons">touch_app</i> Toca los que llegaron</span>@endif
    </div>

    <div class="dtm-table-wrap">
        <table class="dtm-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Producto</th>
                    <th>Enviado</th>
                    <th>Recibido</th>
                    {{-- Las columnas Dif./Estado solo aplican a una nota ya cerrada (vista
                         vía "Todas"). En la recepción activa, "Recibido" se marca tocando la
                         fila (se resalta en azul). --}}
                    @unless($puedeRecibir)
                        <th>Dif.</th>
                        <th>Estado</th>
                    @endunless
                </tr>
            </thead>
            <tbody>
                @foreach($traspaso->lineas as $linea)
                    @php
                        $diff = $linea->diferencia;
                        // Metadata visual del estado de línea — single source of truth en el modelo.
                        $el = \App\Models\TraspasoLinea::ESTADOS_META[$linea->ESTADO_LINEA] ?? \App\Models\TraspasoLinea::ESTADO_META_DEFAULT;
                        $cantEnvFmt = rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, ',', '.'), '0'), ',');
                    @endphp
                    {{-- .dtm-linea + data-* los usa el JS. Cuando $puedeRecibir, .dtm-linea-rec
                         hace la fila clicable: al tocarla se marca .recibida (azul) y
                         trCollectLineas registra data-enviada como cantidad recibida. --}}
                    <tr class="dtm-linea{{ $puedeRecibir ? ' dtm-linea-rec' : '' }}" data-id-linea="{{ $linea->ID_LINEA }}" data-enviada="{{ (float) $linea->CANTIDAD_ENVIADA }}">
                        <td class="dtm-col-idx">{{ $loop->iteration }}</td>
                        <td class="dtm-td-prod">
                            <span class="dtm-linea-cod">{{ optional($linea->producto)->CODIGO }}</span>
                            <span class="dtm-linea-nom">{{ optional($linea->producto)->NOMBRE }}</span>
                            <span class="dtm-linea-um">{{ optional($linea->producto)->UM }}</span>
                        </td>
                        <td class="dtm-col-num">{{ $cantEnvFmt }}</td>
                        @if($puedeRecibir)
                            {{-- Recibido = TOCAR la fila (se resalta en azul + check verde).
                                 Muestra la cantidad enviada (lo que se registra como recibido al
                                 marcar). Sin marcar = no recibido → faltante. --}}
                            <td class="dtm-col-num dtm-rec-cant">
                                <span class="material-icons dtm-rec-ico">check_circle</span>{{ $cantEnvFmt }}
                            </td>
                        @elseif($traspaso->esRecibido() || $traspaso->esCancelado())
                            <td class="dtm-col-num" style="color:{{ $linea->CANTIDAD_RECIBIDA === null ? '#94a3b8' : ($diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#0f172a')) }};">{{ $linea->CANTIDAD_RECIBIDA === null ? '—' : rtrim(rtrim(number_format((float) $linea->CANTIDAD_RECIBIDA, 3, ',', '.'), '0'), ',') }}</td>
                            <td><span class="dtm-diff-value" style="color:{{ $diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#64748b') }};">{{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 3, ',', '.'), '0'), ',') }}</span></td>
                            <td><span class="pill-linea" style="background:{{ $el[1] }};color:{{ $el[2] }};">{{ $el[0] }}</span></td>
                        @else
                            <td>—</td>
                            <td>—</td>
                            <td>—</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($puedeRecibir || $puedeEnviar || ($traspaso->esEnviado() && $puedeCancelar && auth()->user()->can('super.admin')))
<div class="dtm-footer">
    @if($puedeRecibir)
        <button type="button" class="dt-btn dt-btn-cancel" onclick="window.trModalCancelar('{{ addslashes($neNumero) }}')">
            <i class="material-icons">block</i> Cancelar
        </button>
        {{-- "Confirmar (N)": confirma SOLO las filas tildadas. Oculto hasta que el usuario
             toca al menos una fila (lo muestra trUpdateConfirmBtn). Texto corto para que el
             botón no quede desproporcionado. --}}
        <button type="button" id="trConfirmSelBtn" class="dt-btn dt-btn-blue" style="display:none;" onclick="window.trModalConfirmarSeleccionados()">
            <i class="material-icons">playlist_add_check</i> Confirmar (<span class="tr-confirm-sel-count">0</span>)
        </button>
        {{-- "Confirmar todo": un solo toque marca todo y confirma (caso "llegó todo"). --}}
        <button type="button" class="dt-btn dt-btn-primary" onclick="window.trModalConfirmarTodo()">
            <i class="material-icons">check_circle</i> Confirmar todo
        </button>
    @elseif($puedeEnviar)
        <button type="button" class="dt-btn dt-btn-cancel" onclick="window.trModalCancelar('{{ addslashes($neNumero) }}')">Cancelar borrador</button>
        <button type="button" class="dt-btn dt-btn-blue" onclick="window.trModalEnviar()">
            <i class="material-icons">local_shipping</i> Enviar
        </button>
    @elseif($traspaso->esEnviado() && $puedeCancelar && auth()->user()->can('super.admin'))
        <button type="button" class="dt-btn dt-btn-cancel" onclick="window.trModalCancelar('{{ addslashes($neNumero) }}')">Cancelar y revertir</button>
    @endif
</div>
@endif
