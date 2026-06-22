{{-- Contenido del modal de detalle/recepción. Renderizado via AJAX desde
     TraspasoController@show (wantsJson). Variables: $traspaso, $puedeRecibir. --}}
@php
    $em = \App\Models\Traspaso::ESTADOS_META[$traspaso->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
    $puedeEnviar   = $traspaso->esBorrador()  && auth()->user()?->can('almacen.movimiento');
    $puedeCancelar = !$traspaso->esFinal() && auth()->user()?->can('almacen.movimiento');
    $neNumero = $traspaso->REFERENCIA ?: $traspaso->NUMERO;
@endphp

<div class="dtm-header">
    <div class="dtm-title-row">
        <span class="dtm-numero">{{ $neNumero }}</span>
        <span class="estado-pill" style="background:{{ $em[1] }};color:{{ $em[2] }};">{{ $em[0] }}</span>
        <button type="button" class="dtm-close" onclick="window.trCloseModal()" title="Cerrar">
            <i class="material-icons">close</i>
        </button>
    </div>

    <div class="dtm-meta">
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Origen</span>
            <span class="dtm-meta-value">{{ optional($traspaso->almacenOrigen)->NOMBRE }}@if(optional($traspaso->almacenOrigen)->TIPO !== 'GENERAL') <span class="alm-tipo-p">P</span>@endif</span>
        </div>
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Destino</span>
            <span class="dtm-meta-value">{{ optional($traspaso->almacenDestino)->NOMBRE }}@if(optional($traspaso->almacenDestino)->TIPO !== 'GENERAL') <span class="alm-tipo-p">P</span>@endif</span>
        </div>
        <div class="dtm-meta-item">
            <span class="dtm-meta-label">Frente</span>
            <span class="dtm-meta-value">{{ optional($traspaso->frenteDestino)->NOMBRE_FRENTE ?: '—' }}</span>
        </div>
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

    @if($puedeRecibir)
        <div class="dtm-banner">
            <i class="material-icons">pending_actions</i>
            Revisa las cantidades y confirma la recepción.
        </div>
    @endif

    <div class="dtm-lineas-header">
        <span>Materiales</span>
        <span class="dtm-lineas-count">{{ $traspaso->lineas->count() }} líneas</span>
    </div>

    <div class="dtm-table-wrap">
        <table class="dtm-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Enviado</th>
                    <th>Recibido</th>
                    <th>Dif.</th>
                    <th>{{ $puedeRecibir ? 'Dañado' : 'Estado' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($traspaso->lineas as $linea)
                    @php
                        $diff = $linea->diferencia;
                        // Metadata visual del estado de línea — single source of truth en el modelo.
                        $el = \App\Models\TraspasoLinea::ESTADOS_META[$linea->ESTADO_LINEA] ?? \App\Models\TraspasoLinea::ESTADO_META_DEFAULT;
                        $cantEnvFmt = rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, ',', '.'), '0'), ',');
                        $cantEnvRaw = rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, '.', ''), '0'), '.');
                    @endphp
                    {{-- La clase .dtm-linea + data-* se conservan en el <tr>: el JS los usa. --}}
                    <tr class="dtm-linea" data-id-linea="{{ $linea->ID_LINEA }}" data-enviada="{{ (float) $linea->CANTIDAD_ENVIADA }}">
                        <td class="dtm-td-prod">
                            <span class="dtm-linea-cod">{{ optional($linea->producto)->CODIGO }}</span>
                            <span class="dtm-linea-nom">{{ optional($linea->producto)->NOMBRE }}</span>
                            <span class="dtm-linea-um">{{ optional($linea->producto)->UM }}</span>
                        </td>
                        <td class="dtm-col-num">{{ $cantEnvFmt }}</td>
                        @if($puedeRecibir)
                            <td><input type="number" min="0" step="0.001" class="dtm-rec-input" value="{{ $cantEnvRaw }}"></td>
                            <td><span class="dtm-diff-value">0</span></td>
                            <td><input type="checkbox" class="dtm-rec-danado" title="Marcar como dañado"></td>
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
        <div style="flex:1;"></div>
        <button type="button" class="dt-btn dt-btn-confirm-all" onclick="window.trModalTodoOk()">
            <i class="material-icons">done_all</i> Todo OK
        </button>
        <button type="button" class="dt-btn dt-btn-primary" onclick="window.trModalConfirmar()">
            <i class="material-icons">check_circle</i> Confirmar
        </button>
    @elseif($puedeEnviar)
        <button type="button" class="dt-btn dt-btn-cancel" onclick="window.trModalCancelar('{{ addslashes($neNumero) }}')">Cancelar borrador</button>
        <div style="flex:1;"></div>
        <button type="button" class="dt-btn dt-btn-blue" onclick="window.trModalEnviar()">
            <i class="material-icons">local_shipping</i> Enviar
        </button>
    @elseif($traspaso->esEnviado() && $puedeCancelar && auth()->user()->can('super.admin'))
        <div style="flex:1;"></div>
        <button type="button" class="dt-btn dt-btn-cancel" onclick="window.trModalCancelar('{{ addslashes($neNumero) }}')">Cancelar y revertir</button>
    @endif
</div>
@endif
