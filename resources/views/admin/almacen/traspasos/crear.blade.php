@extends('layouts.estructura_base')

@section('title', 'Nuevo Envío')

@section('content')

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Nuevo Envío de Traspaso</span>
        </h1>
        <a href="{{ route('almacen.traspasos.index') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#e2e8f0;color:#475569;box-shadow:none;">
            <i class="material-icons" style="font-size:18px;">arrow_back</i><span class="desktop-text">Volver</span>
        </a>
    </div>
</section>

<style>
    .form-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:14px; }
    @media (max-width: 768px) { .form-grid { grid-template-columns:1fr; } }
    .form-grid label { display:block; font-size:12px; font-weight:700; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
    .form-grid input, .form-grid select { width:100%; height:42px; border:1px solid #cbd5e0; border-radius:8px; padding:0 12px; font-size:14px; background:#fbfcfd; outline:none; color:#1e293b; }
    .form-grid input:focus, .form-grid select:focus { border-color:#0067b1; background:#fff; }
    .lineas-table { width:100%; border-collapse:collapse; font-size:13px; }
    .lineas-table thead th { text-align:left; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; padding:8px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; white-space:nowrap; }
    .lineas-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .lineas-table input, .lineas-table select { width:100%; height:36px; border:1px solid #cbd5e0; border-radius:6px; padding:0 8px; font-size:13px; background:#fff; outline:none; }
    .btn-del-row { background:none; border:none; color:#dc2626; cursor:pointer; padding:6px; }
    .btn-del-row:hover { background:#fee2e2; border-radius:6px; }
</style>

<div class="admin-card" style="margin:0;padding:20px;">

    {{-- Cabecera --}}
    <div class="form-grid">
        <div>
            <label>Almacén Origen *</label>
            <select id="formOrigen">
                @foreach($almacenes as $a)
                    <option value="{{ $a->ID_ALMACEN }}" data-tipo="{{ $a->TIPO }}" {{ $origenSugerido && $a->ID_ALMACEN === $origenSugerido->ID_ALMACEN ? 'selected' : '' }}>
                        {{ $a->NOMBRE }} ({{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }})
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Almacén Destino *</label>
            <select id="formDestino" onchange="window.trCargarFrentesDestino()">
                <option value="">— elige destino —</option>
                @foreach($almacenes as $a)
                    <option value="{{ $a->ID_ALMACEN }}" data-tipo="{{ $a->TIPO }}">
                        {{ $a->NOMBRE }} ({{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }})
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Frente Destino <span style="color:#94a3b8;text-transform:none;font-weight:400;">(opcional)</span></label>
            <select id="formFrente">
                <option value="">— sin frente específico —</option>
                @foreach($frentes as $f)
                    <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Referencia (guía / orden)</label>
            <input type="text" id="formRef" maxlength="100" placeholder="Ej: GUIA-001">
        </div>
        <div style="grid-column:span 2;">
            <label>Motivo</label>
            <input type="text" id="formMotivo" maxlength="200" placeholder="Ej: Reabastecimiento mensual obra Norte">
        </div>
    </div>
    <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Notas</label>
        <textarea id="formNotas" rows="2" style="width:100%;border:1px solid #cbd5e0;border-radius:8px;padding:8px 12px;font-size:14px;background:#fbfcfd;outline:none;resize:vertical;"></textarea>
    </div>

    {{-- Líneas --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <h3 style="margin:0;font-size:14px;font-weight:800;color:#334155;">Productos a enviar</h3>
        <button type="button" class="btn-primary-maquinaria" style="height:36px;padding:0 12px;display:flex;align-items:center;gap:6px;font-size:13px;" onclick="window.trAddLine()">
            <i class="material-icons" style="font-size:16px;">add</i> Agregar producto
        </button>
    </div>

    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;">
        <table class="lineas-table">
            <thead>
                <tr>
                    <th style="width:55%;">Producto</th>
                    <th style="width:18%;text-align:right;">Cantidad</th>
                    <th style="width:18%;text-align:right;">Saldo en origen</th>
                    <th style="width:9%;text-align:center;"></th>
                </tr>
            </thead>
            <tbody id="lineasTbody">
                {{-- una línea vacía inicial --}}
            </tbody>
        </table>
    </div>

    {{-- Acciones --}}
    <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
        <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;height:42px;padding:0 18px;"
                onclick="window.location='{{ route('almacen.traspasos.index') }}'">Cancelar</button>
        <button type="button" class="btn-primary-maquinaria" style="background:#fff;color:#0067b1;border:1px solid #0067b1;box-shadow:none;height:42px;padding:0 18px;"
                onclick="window.trGuardar(false)">Guardar borrador</button>
        <button type="button" class="btn-primary-maquinaria" style="height:42px;padding:0 18px;display:flex;align-items:center;gap:6px;"
                onclick="window.trGuardar(true)">
            <i class="material-icons" style="font-size:18px;">local_shipping</i>
            Enviar ahora
        </button>
    </div>
</div>

<script>
(function () {
    'use strict';
    var ROUTE_STORE = @json(route('almacen.traspasos.store'));
    var PRODUCTOS   = @json($productos);

    function el(id) { return document.getElementById(id); }
    function v(id)  { var e = el(id); return e ? e.value : ''; }
    function toast(msg, tipo) { if (window.toast) window.toast(msg, tipo); else alert(msg); }

    // ── Cargar frentes del almacén destino: filtrar lista al elegir un PROYECTO con frentes asociados ──
    // (Para el MVP no consultamos los frentes vinculados al almacén; el usuario puede elegir cualquiera.
    //  Este hook queda preparado por si lo extendemos después.)
    window.trCargarFrentesDestino = function () {};

    // ── Líneas dinámicas ──
    var nextRowId = 0;
    function rowHtml(rid) {
        var opts = '<option value="">— elige producto —</option>';
        for (var i = 0; i < PRODUCTOS.length; i++) {
            var p = PRODUCTOS[i];
            opts += '<option value="' + p.ID_PRODUCTO + '">' + p.CODIGO + ' — ' + p.NOMBRE.replace(/</g, '&lt;') + ' (' + p.UM + ')</option>';
        }
        return '<tr data-rid="' + rid + '">' +
            '<td><select class="li-prod" onchange="window.trRowProductoChanged(' + rid + ')">' + opts + '</select></td>' +
            '<td><input type="number" min="0.001" step="0.001" class="li-cant" style="text-align:right;" placeholder="0"></td>' +
            '<td style="text-align:right;color:#64748b;font-weight:700;" class="li-saldo">—</td>' +
            '<td style="text-align:center;"><button type="button" class="btn-del-row" onclick="window.trDelLine(' + rid + ')" title="Quitar"><i class="material-icons">delete</i></button></td>' +
            '</tr>';
    }
    window.trAddLine = function () {
        var tb = el('lineasTbody'); if (!tb) return;
        tb.insertAdjacentHTML('beforeend', rowHtml(++nextRowId));
    };
    window.trDelLine = function (rid) {
        var row = document.querySelector('#lineasTbody tr[data-rid="' + rid + '"]');
        if (row) row.remove();
        if (el('lineasTbody').children.length === 0) window.trAddLine();
    };

    // Cuando cambia el producto en una línea, consultamos el saldo en el origen.
    window.trRowProductoChanged = function (rid) {
        var row    = document.querySelector('#lineasTbody tr[data-rid="' + rid + '"]'); if (!row) return;
        var saldo  = row.querySelector('.li-saldo');
        var idProd = row.querySelector('.li-prod').value;
        var idAlm  = v('formOrigen');
        if (!idProd || !idAlm) { saldo.textContent = '—'; return; }
        // No tenemos endpoint específico de "stock de un producto"; el sidebar de
        // /admin/almacen ya muestra los saldos. Aquí lo dejamos en blanco; el usuario
        // verá el error de stock al enviar si se pasa.
        saldo.textContent = '—';
    };

    // ── Guardar / enviar ──
    window.trGuardar = function (enviarAhora) {
        var lineas = [];
        document.querySelectorAll('#lineasTbody tr').forEach(function (r) {
            var idProd = r.querySelector('.li-prod').value;
            var cant   = r.querySelector('.li-cant').value;
            if (idProd && cant && parseFloat(cant) > 0) {
                lineas.push({ id_producto: parseInt(idProd, 10), cantidad: parseFloat(cant) });
            }
        });
        if (lineas.length === 0) { toast('Agrega al menos un producto con cantidad > 0.', 'error'); return; }
        if (!v('formOrigen') || !v('formDestino')) { toast('Elige almacén origen y destino.', 'error'); return; }
        if (v('formOrigen') === v('formDestino')) { toast('Origen y destino no pueden ser el mismo.', 'error'); return; }

        var payload = {
            id_almacen_origen:  parseInt(v('formOrigen'), 10),
            id_almacen_destino: parseInt(v('formDestino'), 10),
            id_frente_destino:  v('formFrente') ? parseInt(v('formFrente'), 10) : null,
            referencia:         v('formRef')    || null,
            motivo:             v('formMotivo') || null,
            notas:              v('formNotas')  || null,
            lineas:             lineas,
            enviar_ahora:       !!enviarAhora,
        };

        if (window.showPreloader) window.showPreloader();
        fetch(ROUTE_STORE, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok) {
                toast(res.b.message || 'Listo.', 'success');
                var idT = res.b.traspaso && res.b.traspaso.ID_TRASPASO;
                setTimeout(function () { window.location = idT ? (@json(url('/admin/almacen/traspasos')) + '/' + idT) : @json(route('almacen.traspasos.index')); }, 500);
            } else {
                toast((res.b && res.b.message) || 'No se pudo guardar.', 'error');
            }
        })
        .catch(function () { if (window.hidePreloader) window.hidePreloader(); toast('Error de red.', 'error'); });
    };

    // Una línea vacía al cargar.
    window.trAddLine();
})();
</script>
@endsection
