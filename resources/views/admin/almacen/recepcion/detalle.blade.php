@extends('layouts.estructura_base')

@section('title', $traspaso->NUMERO)

@section('content')
@php
    $estilos = [
        'BORRADOR'         => ['Borrador',  '#f1f5f9', '#64748b'],
        'ENVIADO'          => ['Enviado',   '#fef3c7', '#b45309'],
        'RECIBIDO'         => ['Recibido',  '#dcfce7', '#15803d'],
        'RECIBIDO_PARCIAL' => ['Parcial',   '#fee2e2', '#b91c1c'],
        'CANCELADO'        => ['Cancelado', '#e2e8f0', '#475569'],
    ];
    $e = $estilos[$traspaso->ESTADO] ?? ['—', '#f1f5f9', '#64748b'];
    $puedeEnviar   = $traspaso->esBorrador()  && auth()->user()?->can('almacen.movimiento');
    $puedeCancelar = !$traspaso->esFinal() && auth()->user()?->can('almacen.movimiento');
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <h1 class="page-title" style="margin:0;">
                <span class="page-title-line2" style="color:#000;font-family:monospace;">{{ $traspaso->NUMERO }}</span>
            </h1>
            <span style="background:{{ $e[1] }};color:{{ $e[2] }};padding:5px 14px;border-radius:999px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">{{ $e[0] }}</span>
        </div>
        <a href="{{ route('almacen.recepcion.index') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#e2e8f0;color:#475569;box-shadow:none;">
            <i class="material-icons" style="font-size:18px;">arrow_back</i><span class="desktop-text">Volver</span>
        </a>
    </div>
</section>

<style>
    .info-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:18px; }
    @media (max-width: 900px) { .info-grid { grid-template-columns:1fr 1fr; } }
    @media (max-width: 480px) { .info-grid { grid-template-columns:1fr; } }
    .info-cell { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; }
    .info-cell .lbl { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
    .info-cell .val { font-size:14px; font-weight:600; color:#1e293b; word-break:break-word; }
    .lineas-detalle { width:100%; border-collapse:collapse; font-size:13px; }
    .lineas-detalle thead th { text-align:left; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; padding:9px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; white-space:nowrap; }
    .lineas-detalle tbody td { padding:9px 10px; border-bottom:1px solid #f1f5f9; }
    .lineas-detalle input[type="number"], .lineas-detalle input[type="text"] { width:100%; height:32px; border:1px solid #cbd5e0; border-radius:6px; padding:0 8px; font-size:12.5px; background:#fff; outline:none; }
    .pill-linea { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.2px; }
</style>

<div class="admin-card" style="margin:0;padding:20px;">

    {{-- ── Info general ── --}}
    <div class="info-grid">
        <div class="info-cell">
            <div class="lbl">Origen</div>
            <div class="val">{{ optional($traspaso->almacenOrigen)->NOMBRE }}</div>
        </div>
        <div class="info-cell">
            <div class="lbl">Destino</div>
            <div class="val">{{ optional($traspaso->almacenDestino)->NOMBRE }}</div>
        </div>
        <div class="info-cell">
            <div class="lbl">Frente Destino</div>
            <div class="val">{{ optional($traspaso->frenteDestino)->NOMBRE_FRENTE ?: '—' }}</div>
        </div>
        <div class="info-cell">
            <div class="lbl">Creado por</div>
            <div class="val">{{ optional($traspaso->usuarioCreo)->NOMBRE_COMPLETO ?: '—' }}<br>
                <span style="font-weight:400;font-size:12px;color:#64748b;">{{ $traspaso->created_at?->format('d-M-Y H:i') }}</span>
            </div>
        </div>
        <div class="info-cell">
            <div class="lbl">Enviado por</div>
            <div class="val">{{ optional($traspaso->usuarioEnvio)->NOMBRE_COMPLETO ?: '—' }}<br>
                <span style="font-weight:400;font-size:12px;color:#64748b;">{{ $traspaso->FECHA_ENVIO?->format('d-M-Y H:i') ?: '—' }}</span>
            </div>
        </div>
        <div class="info-cell">
            <div class="lbl">Recibido por</div>
            <div class="val">{{ optional($traspaso->usuarioRecepcion)->NOMBRE_COMPLETO ?: '—' }}<br>
                <span style="font-weight:400;font-size:12px;color:#64748b;">{{ $traspaso->FECHA_RECEPCION?->format('d-M-Y H:i') ?: '—' }}</span>
            </div>
        </div>
        <div class="info-cell">
            <div class="lbl">Referencia</div>
            <div class="val">{{ $traspaso->REFERENCIA ?: '—' }}</div>
        </div>
        <div class="info-cell">
            <div class="lbl">Motivo</div>
            <div class="val">{{ $traspaso->MOTIVO ?: '—' }}</div>
        </div>
    </div>

    @if($traspaso->NOTAS)
        <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:8px;padding:10px 14px;margin-bottom:18px;">
            <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;margin-bottom:4px;">Notas</div>
            <div style="font-size:13.5px;color:#92400e;white-space:pre-wrap;">{{ $traspaso->NOTAS }}</div>
        </div>
    @endif

    {{-- ── Líneas ── --}}
    <h3 style="margin:0 0 8px 0;font-size:14px;font-weight:800;color:#334155;">Productos del envío</h3>
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px;">
        <table class="lineas-detalle">
            <thead>
                <tr>
                    <th style="width:36%;">Producto</th>
                    <th style="width:14%;text-align:right;">Enviado</th>
                    @if($puedeRecibir || $traspaso->esRecibido() || $traspaso->esCancelado())
                        <th style="width:14%;text-align:right;">{{ $puedeRecibir ? 'Recibido (editable)' : 'Recibido' }}</th>
                        <th style="width:12%;text-align:right;">Diferencia</th>
                        <th style="width:10%;text-align:center;">Estado</th>
                        <th style="width:14%;">Notas</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($traspaso->lineas as $linea)
                    @php
                        $diff = $linea->diferencia;
                        $estilosLinea = [
                            'PENDIENTE' => ['Pendiente','#f1f5f9','#64748b'],
                            'OK'        => ['OK',       '#dcfce7','#15803d'],
                            'FALTANTE'  => ['Faltante', '#fee2e2','#b91c1c'],
                            'SOBRANTE'  => ['Sobrante', '#dbeafe','#1d4ed8'],
                            'DANADO'    => ['Dañado',   '#fef3c7','#b45309'],
                        ];
                        $el = $estilosLinea[$linea->ESTADO_LINEA] ?? ['—','#f1f5f9','#64748b'];
                    @endphp
                    <tr data-id-linea="{{ $linea->ID_LINEA }}">
                        <td>
                            <div style="font-family:monospace;font-weight:700;color:#0f172a;">{{ optional($linea->producto)->CODIGO }}</div>
                            <div style="color:#475569;font-size:12.5px;">{{ optional($linea->producto)->NOMBRE }} <span style="color:#94a3b8;">({{ optional($linea->producto)->UM }})</span></div>
                        </td>
                        <td style="text-align:right;font-weight:700;color:#0f172a;">{{ rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, '.', ','), '0'), '.') }}</td>
                        @if($puedeRecibir)
                            <td style="text-align:right;">
                                <input type="number" min="0" step="0.001" class="rec-cantidad" style="text-align:right;"
                                       value="{{ rtrim(rtrim(number_format((float) $linea->CANTIDAD_ENVIADA, 3, '.', ''), '0'), '.') }}">
                            </td>
                            <td class="rec-diff" style="text-align:right;color:#64748b;font-weight:700;">0</td>
                            <td style="text-align:center;">
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#b45309;cursor:pointer;">
                                    <input type="checkbox" class="rec-danado" style="margin:0;">Dañado
                                </label>
                            </td>
                            <td><input type="text" class="rec-notas" placeholder="Observaciones…" maxlength="1000"></td>
                        @elseif($traspaso->esRecibido() || $traspaso->esCancelado())
                            <td style="text-align:right;font-weight:700;color:{{ $linea->CANTIDAD_RECIBIDA === null ? '#94a3b8' : ($diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#0f172a')) }};">
                                {{ $linea->CANTIDAD_RECIBIDA === null ? '—' : rtrim(rtrim(number_format((float) $linea->CANTIDAD_RECIBIDA, 3, '.', ','), '0'), '.') }}
                            </td>
                            <td style="text-align:right;color:{{ $diff < 0 ? '#dc2626' : ($diff > 0 ? '#1d4ed8' : '#64748b') }};font-weight:700;">
                                {{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 3, '.', ','), '0'), '.') }}
                            </td>
                            <td style="text-align:center;">
                                <span class="pill-linea" style="background:{{ $el[1] }};color:{{ $el[2] }};">{{ $el[0] }}</span>
                            </td>
                            <td style="font-size:12.5px;color:#64748b;">{{ $linea->NOTAS_LINEA ?: '—' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Acciones según estado ── --}}
    <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
        @if($puedeRecibir)
            <button type="button" class="btn-primary-maquinaria" style="background:#fff;color:#dc2626;border:1px solid #dc2626;box-shadow:none;height:42px;padding:0 18px;"
                    onclick="window.trCancelar('{{ $traspaso->NUMERO }}')">Cancelar envío</button>
            <button type="button" id="btnRecibir" class="btn-primary-maquinaria" style="background:#16a34a;border:none;height:42px;padding:0 22px;display:flex;align-items:center;gap:8px;color:#fff;"
                    onclick="window.trConfirmarRecepcion()">
                <i class="material-icons" style="font-size:18px;">check_circle</i> Confirmar recepción
            </button>
        @elseif($puedeEnviar)
            @if($puedeCancelar)
                <button type="button" class="btn-primary-maquinaria" style="background:#fff;color:#dc2626;border:1px solid #dc2626;box-shadow:none;height:42px;padding:0 18px;"
                        onclick="window.trCancelar('{{ $traspaso->NUMERO }}')">Cancelar borrador</button>
            @endif
            {{-- "Editar borrador" deshabilitado en el MVP: el endpoint PATCH existe pero la vista
                 crear.blade.php todavía no soporta el modo edit (no precarga los datos). Cuando
                 se implemente, este botón vuelve. Mientras tanto el usuario puede cancelar y crear
                 otro, o llamar el PATCH directo con un cliente HTTP. --}}
            <button type="button" class="btn-primary-maquinaria" style="height:42px;padding:0 18px;display:flex;align-items:center;gap:6px;"
                    onclick="window.trEnviar()">
                <i class="material-icons" style="font-size:18px;">local_shipping</i> Enviar ahora
            </button>
        @elseif($traspaso->esEnviado() && $puedeCancelar && auth()->user()->can('super.admin'))
            <button type="button" class="btn-primary-maquinaria" style="background:#fff;color:#dc2626;border:1px solid #dc2626;box-shadow:none;height:42px;padding:0 18px;"
                    onclick="window.trCancelar('{{ $traspaso->NUMERO }}')">Cancelar y revertir</button>
        @endif
    </div>
</div>

<script>
(function () {
    'use strict';
    var ID_T   = {{ $traspaso->ID_TRASPASO }};
    var BASE   = @json(url('/admin/almacen/recepcion'));

    function toast(m, t) { if (window.toast) window.toast(m, t); else alert(m); }
    function pre()  { if (window.showPreloader) window.showPreloader(); }
    function unp()  { if (window.hidePreloader) window.hidePreloader(); }
    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

    function post(url, body, onOk) {
        pre();
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {}),
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
        var enviada = parseFloat(inp.closest('tr').children[1].textContent.replace(/,/g, '')) || 0;
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

    window.trConfirmarRecepcion = function () {
        var lineas = [];
        document.querySelectorAll('tr[data-id-linea]').forEach(function (tr) {
            var inp     = tr.querySelector('.rec-cantidad'); if (!inp) return;
            var danado  = tr.querySelector('.rec-danado');
            var notas   = tr.querySelector('.rec-notas');
            lineas.push({
                id_linea:          parseInt(tr.dataset.idLinea, 10),
                cantidad_recibida: parseFloat(inp.value) || 0,
                estado:            (danado && danado.checked) ? 'DANADO' : null,
                notas:             notas ? notas.value.trim() : null,
            });
        });
        if (lineas.length === 0) { toast('No hay líneas para recibir.', 'error'); return; }
        if (!confirm('Vas a confirmar la recepción de ' + lineas.length + ' línea(s). ¿Continuar?')) return;
        post(BASE + '/' + ID_T + '/recibir', { lineas: lineas, fecha_recepcion: new Date().toISOString().slice(0,10) }, function () {
            setTimeout(function () { window.location.reload(); }, 700);
        });
    };

    window.trEnviar = function () {
        if (!confirm('Vas a marcar el traspaso como ENVIADO. El stock se restará del origen. ¿Continuar?')) return;
        post(BASE + '/' + ID_T + '/enviar', {}, function () { setTimeout(function () { window.location.reload(); }, 700); });
    };

    window.trCancelar = function (numero) {
        if (!confirm('¿Cancelar ' + numero + '? Si ya estaba enviado, se revertirá el stock al origen.')) return;
        post(BASE + '/' + ID_T + '/cancelar', {}, function () { setTimeout(function () { window.location = @json(route('almacen.recepcion.index')); }, 700); });
    };
})();
</script>
@endsection
