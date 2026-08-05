@extends('layouts.estructura_base')

@section('title', ($traspaso->REFERENCIA ?: $traspaso->NUMERO) . ' — Nota de Entrega')

@section('content')
@php
    // Metadata visual de los estados — single source of truth en Traspaso::ESTADOS_META.
    $e = \App\Models\Traspaso::ESTADOS_META[$traspaso->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
    // $puedeRecibir / $puedeEnviar / $puedeCancelar llegan del controller (fuente única, con
    // las mismas condiciones que exigen sus endpoints). Antes se recalculaban aquí y en el
    // partial del modal — la misma regla en dos sitios, y ninguna igual a la del backend.

    // Frente vs Destino: en almacenes de PROYECTO el nombre del almacén destino y el del
    // frente suelen coincidir → no repetir la fila "Frente" cuando dice lo mismo que
    // "Destino". La comparación (tolerante a tildes/mayúsculas/espacios) vive en el modelo,
    // misma fuente que el modal y la bandeja de recepción.
    $frenteRedundante = $traspaso->frenteDestinoEsRedundante();
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}" style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;text-decoration:none;transition:background .15s;" title="Volver a la bandeja">
                <i class="material-icons" style="font-size:18px;">arrow_back</i>
            </a>
            <div>
                <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Nota de entrega</div>
                <h1 class="page-title" style="margin:0;">
                    <span class="page-title-line2" style="color:#000;font-family:monospace;font-size:20px;">{{ $traspaso->REFERENCIA ?: $traspaso->NUMERO }}</span>
                </h1>
            </div>
            <span style="background:{{ $e[1] }};color:{{ $e[2] }};padding:4px 12px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;">{{ $e[0] }}</span>
        </div>
    </div>
</section>

<style>
    /* ── Detalle de nota — layout WMS profesional ── */

    /* Metadata: barra compacta con key-values inline */
    .dt-meta {
        display:flex; flex-wrap:wrap; gap:0;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
        overflow:hidden; margin-bottom:16px;
    }
    .dt-meta-item {
        flex:1 1 180px; min-width:0;
        padding:10px 14px;
        border-right:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0;
    }
    .dt-meta-item:last-child { border-right:none; }
    .dt-meta-label { display:block; font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
    .dt-meta-value { font-size:13.5px; font-weight:600; color:#1e293b; word-break:break-word; }
    .dt-meta-value .sub { font-weight:400; font-size:12px; color:#64748b; }

    /* Banner de confirmación pendiente */
    .dt-confirm-banner {
        display:flex; align-items:center; gap:10px;
        padding:10px 16px; margin-bottom:14px;
        background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px;
        font-size:13px; font-weight:600; color:#1e40af;
    }
    .dt-confirm-banner i { font-size:20px; }

    /* Tabla de líneas */
    .lineas-detalle { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .lineas-detalle thead tr { background:#1e293b; }
    .lineas-detalle thead th { text-align:left; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 14px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .lineas-detalle thead th:last-child { border-right:none; }
    .lineas-detalle tbody td { padding:10px 14px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; vertical-align:middle; }
    .lineas-detalle tbody td:last-child { border-right:none; }
    .lineas-detalle tbody tr:hover td { background:#f8fafc; }
    .lineas-detalle input[type="number"] {
        width:100%; height:36px; border:1px solid #93c5fd; border-radius:6px;
        padding:0 8px; font-size:13.5px; font-weight:700; background:#eff6ff;
        outline:none; color:#1e3a5f; text-align:right;
    }
    .lineas-detalle input[type="number"]:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.15); background:#fff; }
    .pill-linea { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.2px; }

    /* Notas amarillas */
    .dt-notas-box {
        background:#fffbeb; border:1px solid #fef3c7; border-radius:8px;
        padding:10px 14px; margin-bottom:14px;
    }

    /* Footer de acciones */
    .dt-footer {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin-top:16px; padding:14px 16px;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
    }
    .dt-btn {
        height:42px; padding:0 20px; border-radius:10px; cursor:default;
        font-size:13.5px; font-weight:700; letter-spacing:.2px;
        display:inline-flex; align-items:center; gap:6px;
        transition:background .15s, transform .1s;
    }
    .dt-btn i { font-size:18px; }
    .dt-btn-cancel {
        background:#fff; color:#dc2626; border:1px solid #fca5a5;
    }
    .dt-btn-cancel:hover { background:#fee2e2; border-color:#dc2626; }
    .dt-btn-confirm-all {
        background:#065f46; color:#fff; border:none;
    }
    .dt-btn-confirm-all:hover { background:#064e3b; }
    .dt-btn-primary {
        background:#16a34a; color:#fff; border:none;
        box-shadow:0 4px 8px -2px rgba(22,163,74,0.3);
    }
    .dt-btn-primary:hover { background:#15803d; }
    .dt-btn-primary:active, .dt-btn-confirm-all:active { transform:scale(0.98); }
    .dt-btn-blue {
        background:var(--maquinaria-blue,#0067b1); color:#fff; border:none;
        box-shadow:0 4px 8px -2px rgba(0,103,177,0.3);
    }
    .dt-btn-blue:hover { background:#005391; }

    @media (max-width: 900px) {
        .dt-meta-item { flex:1 1 45%; }
    }
    @media (max-width: 480px) {
        .dt-meta-item { flex:1 1 100%; border-right:none; }
        .dt-footer { flex-direction:column; }
        .dt-footer .dt-btn { width:100%; justify-content:center; }
    }
</style>

<div class="admin-card" style="margin:0;padding:18px;">

    {{-- ── Metadata compacta ── --}}
    <div class="dt-meta">
        <div class="dt-meta-item">
            <span class="dt-meta-label">Origen</span>
            <span class="dt-meta-value">{{ optional($traspaso->almacenOrigen)->NOMBRE }}</span>
        </div>
        <div class="dt-meta-item">
            <span class="dt-meta-label">Destino</span>
            <span class="dt-meta-value">{{ optional($traspaso->almacenDestino)->NOMBRE }}</span>
        </div>
        @unless($frenteRedundante)
        <div class="dt-meta-item">
            <span class="dt-meta-label">Frente</span>
            <span class="dt-meta-value">{{ optional($traspaso->frenteDestino)->NOMBRE_FRENTE ?: '—' }}</span>
        </div>
        @endunless
        <div class="dt-meta-item">
            <span class="dt-meta-label">Despachado por</span>
            <span class="dt-meta-value">
                {{ optional($traspaso->usuarioEnvio)->NOMBRE_COMPLETO ?: optional($traspaso->usuarioCreo)->NOMBRE_COMPLETO ?: '—' }}
                <span class="sub">{{ $traspaso->FECHA_ENVIO?->format('d/m/Y h:i A') ?: $traspaso->created_at?->format('d/m/Y h:i A') }}</span>
            </span>
        </div>
        <div class="dt-meta-item">
            <span class="dt-meta-label">Confirmado por</span>
            <span class="dt-meta-value">
                {{ optional($traspaso->usuarioRecepcion)->NOMBRE_COMPLETO ?: '—' }}
                <span class="sub">{{ $traspaso->FECHA_RECEPCION?->format('d/m/Y h:i A') ?: '—' }}</span>
            </span>
        </div>
        <div class="dt-meta-item">
            <span class="dt-meta-label">Motivo</span>
            <span class="dt-meta-value">{{ $traspaso->MOTIVO ?: '—' }}</span>
        </div>
    </div>

    @if($traspaso->NOTAS)
        <div class="dt-notas-box">
            <div style="font-size:10.5px;font-weight:700;color:#b45309;text-transform:uppercase;margin-bottom:3px;">Notas</div>
            <div style="font-size:13px;color:#92400e;white-space:pre-wrap;">{{ $traspaso->NOTAS }}</div>
        </div>
    @endif

    @if($puedeRecibir)
        <div class="dt-confirm-banner">
            <i class="material-icons">pending_actions</i>
            Pendiente de confirmación — revisa las cantidades y confirma la recepción.
        </div>
    @endif

    {{-- ── Líneas ── --}}
    <div style="display:flex;align-items:center;margin-bottom:8px;">
        <span style="font-size:13px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.5px;">Materiales</span>
    </div>
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:0;">
        <table class="lineas-detalle">
            <thead>
                <tr>
                    <th style="width:44%;">Producto</th>
                    <th style="width:16%;text-align:right;">Enviado</th>
                    @if($puedeRecibir || $traspaso->esRecibido() || $traspaso->esCancelado())
                        <th style="width:16%;text-align:right;">Recibido</th>
                        <th style="width:12%;text-align:right;">Dif.</th>
                        <th style="width:12%;text-align:center;">Estado</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($traspaso->lineas as $linea)
                    @php
                        $diff = $linea->diferencia;
                        // Metadata visual del estado de línea — single source of truth en el modelo.
                        $el = \App\Models\TraspasoLinea::ESTADOS_META[$linea->ESTADO_LINEA] ?? \App\Models\TraspasoLinea::ESTADO_META_DEFAULT;
                        // Ya confirmada en una recepción anterior (nota RECIBIDO_PARCIAL que se
                        // reabre para completar lo que falta): se muestra en solo lectura como en
                        // una nota cerrada. Sin esto salía un input con la cantidad enviada
                        // precargada, invitando a re-confirmar algo que el servidor ignora
                        // (ver TraspasoService::recibir) — mismo criterio que en detalle_modal.
                        $lineaConfirmada = $linea->estaConfirmada();
                        // Formateo de cantidades en UN solo sitio (igual que en detalle_modal):
                        // la misma expresión estaba escrita a mano más abajo.
                        $fmtCant    = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
                        $cantRecFmt = $lineaConfirmada ? $fmtCant($linea->CANTIDAD_RECIBIDA) : '—';
                    @endphp
                    <tr data-id-linea="{{ $linea->ID_LINEA }}">
                        <td>
                            <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                                <span style="font-family:monospace;font-weight:800;font-size:12px;color:#0f172a;white-space:nowrap;letter-spacing:.3px;">{{ optional($linea->producto)->CODIGO }}</span>
                                <span style="color:#475569;font-size:13px;font-weight:600;">{{ optional($linea->producto)->NOMBRE }}</span>
                                <span style="color:#94a3b8;font-size:11px;font-weight:700;text-transform:uppercase;">{{ optional($linea->producto)->UM }}</span>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;font-family:monospace;font-size:13.5px;color:#0f172a;">{{ rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, ',', '.'), '0'), ',') }}</td>
                        @if($puedeRecibir && !$lineaConfirmada)
                            <td style="text-align:right;">
                                {{-- name/aria-label por línea: sin ellos Chrome avisa en consola
                                     ("A form field element should have an id or name attribute")
                                     una vez por fila. El JS los localiza por CLASE, así que el
                                     name es solo identificación (no hay <form>: se envía por AJAX). --}}
                                <input type="number" min="0" step="0.001" class="rec-cantidad"
                                       name="rec_cant_{{ $linea->ID_LINEA }}"
                                       aria-label="Cantidad recibida de {{ optional($linea->producto)->NOMBRE }}"
                                       value="{{ rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, '.', ''), '0'), '.') }}">
                            </td>
                            <td class="rec-diff" style="text-align:right;color:#64748b;font-weight:700;font-family:monospace;font-size:13px;">0</td>
                            <td style="text-align:center;">
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#b45309;cursor:default;">
                                    <input type="checkbox" class="rec-danado" name="rec_danado_{{ $linea->ID_LINEA }}" style="margin:0;accent-color:#b45309;">Dañado
                                </label>
                            </td>
                        @elseif($lineaConfirmada || $traspaso->esRecibido() || $traspaso->esCancelado())
                            <td style="text-align:right;font-weight:700;font-family:monospace;font-size:13.5px;color:{{ !$lineaConfirmada ? '#94a3b8' : ($diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#0f172a')) }};">
                                {{ $cantRecFmt }}
                            </td>
                            <td style="text-align:right;font-family:monospace;font-size:13px;color:{{ $diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#64748b') }};font-weight:700;">
                                {{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 3, ',', '.'), '0'), ',') }}
                            </td>
                            <td style="text-align:center;">
                                <span class="pill-linea" style="background:{{ $el[1] }};color:{{ $el[2] }};">{{ $el[0] }}</span>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Acciones ── --}}
    @if($puedeRecibir || $puedeEnviar || $puedeCancelar)
    <div class="dt-footer">
        @if($puedeRecibir)
            {{-- "Anular nota" reversa el stock: solo para quien puede de verdad (nota ya
                 enviada = super.admin con el almacén origen visible). Ver $puedeCancelar en
                 TraspasoController@show, que replica las condiciones de cancelar().
                 Vive SOLO aquí: el modal de la bandeja ya no lo ofrece (pedido del cliente),
                 porque es una acción irreversible dentro de la pantalla de trabajo diario. --}}
            @if($puedeCancelar)
            <button type="button" class="dt-btn dt-btn-cancel"
                    onclick="window.trCancelar('{{ $traspaso->REFERENCIA ?: $traspaso->NUMERO }}')">
                <i class="material-icons">block</i> Anular nota
            </button>
            @endif
            <div style="flex:1;"></div>
            <button type="button" class="dt-btn dt-btn-confirm-all"
                    onclick="window.trConfirmarTodoOk()">
                <i class="material-icons">done_all</i> Todo OK
            </button>
            <button type="button" id="btnRecibir" class="dt-btn dt-btn-primary"
                    onclick="window.trConfirmarRecepcion()">
                <i class="material-icons">check_circle</i> Confirmar recepción
            </button>
        @elseif($puedeEnviar)
            <button type="button" class="dt-btn dt-btn-cancel"
                    onclick="window.trCancelar('{{ $traspaso->NUMERO }}')">Cancelar borrador</button>
            <div style="flex:1;"></div>
            <button type="button" class="dt-btn dt-btn-blue"
                    onclick="window.trEnviar()">
                <i class="material-icons">local_shipping</i> Enviar ahora
            </button>
        @elseif($puedeCancelar)
            <div style="flex:1;"></div>
            <button type="button" class="dt-btn dt-btn-cancel"
                    onclick="window.trCancelar('{{ $traspaso->NUMERO }}')">Cancelar y revertir</button>
        @endif
    </div>
    @endif
</div>

<script>
(function () {
    'use strict';
    var ID_T   = {{ $traspaso->ID_TRASPASO }};
    var BASE   = @json(url('/admin/almacen/recepcion'));

    // El helper global es window.showToast (uicomponents.js). Preguntaba por window.toast,
    // que no existe en ninguna parte, así que esta página SIEMPRE caía al alert() del
    // navegador mientras el resto del módulo mostraba toasts. Mismo patrón que nueva.blade.
    function toast(m, t) { if (window.showToast) window.showToast(m, t || 'success'); else if (t === 'error') alert(m); }
    function pre()  { if (window.showPreloader) window.showPreloader(); }
    function unp()  { if (window.hidePreloader) window.hidePreloader(); }
    var csrf = window.getCsrf;   // helper central (dom_helpers.js)

    function post(url, body, onOk) {
        pre();
        window.apiFetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json'},
            body: JSON.stringify(body || {})
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unp();
            if (res.ok) { toast(res.b.message || 'Listo.', 'success'); onOk && onOk(res.b); }
            else { toast((res.b && res.b.message) || 'Error.', 'error'); }
        })
        .catch(function () { unp(); toast('Error de red.', 'error'); });
    }

    // ── Recepción: cálculo de diferencia en vivo ──
    document.querySelectorAll('.rec-cantidad').forEach(function (inp) {
        // El "Enviado" se renderiza en formato latino (1.234,5): los puntos son miles y la
        // coma el decimal. Hay que quitar los puntos y pasar la coma a punto ANTES de
        // parseFloat — si no, "1.234,5" se parsea como 1.2345 (mismo criterio que trConfirmarTodoOk).
        var enviada = parseFloat(inp.closest('tr').children[1].textContent.replace(/\./g, '').replace(',', '.')) || 0;
        var diffCell = inp.closest('tr').querySelector('.rec-diff');
        function recalc() {
            var rec = parseFloat(inp.value) || 0;
            var d = (rec - enviada);
            diffCell.textContent = (d > 0 ? '+' : '') + d.toFixed(3).replace(/\.?0+$/, '');
            diffCell.style.color = d === 0 ? '#64748b' : (d < 0 ? '#dc2626' : '#1d4ed8');
        }
        inp.addEventListener('input', recalc);
        recalc();
    });

    window.trConfirmarTodoOk = function () {
        var lineas = [];
        document.querySelectorAll('tr[data-id-linea]').forEach(function (tr) {
            // Sin input = línea YA confirmada en una recepción anterior (nota RECIBIDO_PARCIAL
            // reabierta). Mismo criterio que trConfirmarRecepcion. El servidor igual las ignora,
            // pero si se enviaran, el conteo del mensaje ("TODOS los materiales, N líneas")
            // prometería confirmar más de lo que realmente se va a procesar.
            if (!tr.querySelector('.rec-cantidad')) return;
            var enviada = parseFloat(tr.children[1].textContent.replace(/\./g, '').replace(',', '.')) || 0;
            lineas.push({
                id_linea:          parseInt(tr.dataset.idLinea, 10),
                cantidad_recibida: enviada,
                estado:            null,
            });
        });
        if (lineas.length === 0) { toast('No hay líneas para recibir.', 'error'); return; }
        window.confirmarAccion({
            title:       'Recibir todo',
            message:     '¿Confirmar que TODOS los materiales (' + lineas.length + ' línea' + (lineas.length > 1 ? 's' : '') + ') llegaron completos y en buen estado?',
            confirmText: 'Sí, llegó todo',
        }, function () {
            post(BASE + '/' + ID_T + '/recibir', { lineas: lineas, fecha_recepcion: new Date().toISOString().slice(0,10) }, function () {
                setTimeout(function () { window.location.reload(); }, 700);
            });
        });
    };

    window.trConfirmarRecepcion = function () {
        var lineas = [];
        document.querySelectorAll('tr[data-id-linea]').forEach(function (tr) {
            var inp     = tr.querySelector('.rec-cantidad'); if (!inp) return;
            var danado  = tr.querySelector('.rec-danado');
            lineas.push({
                id_linea:          parseInt(tr.dataset.idLinea, 10),
                cantidad_recibida: parseFloat(inp.value) || 0,
                estado:            (danado && danado.checked) ? 'DANADO' : null,
            });
        });
        if (lineas.length === 0) { toast('No hay líneas para recibir.', 'error'); return; }
        window.confirmarAccion({
            title:       'Confirmar recepción',
            message:     'Vas a confirmar la nota de entrega (' + lineas.length + ' material' + (lineas.length > 1 ? 'es' : '') + ').',
            confirmText: 'Confirmar',
        }, function () {
            post(BASE + '/' + ID_T + '/recibir', { lineas: lineas, fecha_recepcion: new Date().toISOString().slice(0,10) }, function () {
                setTimeout(function () { window.location.reload(); }, 700);
            });
        });
    };

    window.trEnviar = function () {
        window.confirmarAccion({
            title:       'Despachar la nota',
            message:     'El stock se restará del almacén de origen y la nota quedará EN TRÁNSITO.',
            confirmText: 'Despachar',
        }, function () {
            post(BASE + '/' + ID_T + '/enviar', {}, function () { setTimeout(function () { window.location.reload(); }, 700); });
        });
    };

    // Misma acción y MISMA confirmación que el botón del modal de la bandeja
    // (window.trModalCancelar): modal estándar en rojo, no el confirm() del navegador.
    window.trCancelar = function (numero) {
        var anular = function () {
            // ?force=1: tras anular volvemos a la BANDEJA (no a recepcion/nueva).
            post(BASE + '/' + ID_T + '/cancelar', {}, function () { setTimeout(function () { window.location = @json(route('almacen.recepcion.index', ['force' => 1])); }, 700); });
        };
        window.confirmarAccion({
            type:        'danger',
            title:       'Anular la nota',
            message:     'La nota <strong>' + numero + '</strong> quedará ANULADA. Si ya estaba en tránsito, el stock volverá al almacén de origen. No se puede deshacer.',
            confirmText: 'Anular',
            cancelText:  'No, volver',
        }, anular);
    };
})();
</script>
@endsection
