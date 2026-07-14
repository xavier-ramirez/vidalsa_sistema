/**
 * Modo OFFLINE Fase 2 — Motor de sincronización del OUTBOX.
 * ---------------------------------------------------------------------------
 * Sube al servidor (POST /offline/sync) las acciones que el usuario hizo SIN
 * internet (movilizar, cambiar estado, recepción directa), encoladas por la UI
 * en IndexedDB vía window.OfflineDB.enqueue(...).
 *
 * Política LAST-WRITE-WINS: el servidor aplica cada operación sin comparar
 * versiones; el último cambio en llegar gana. Respuesta por ítem:
 * 'synced' (aplicado, sale del outbox) | 'error' (se reintenta). La cola
 * persiste en IndexedDB, así sobrevive recargas y cierres.
 *
 * API pública (window.OfflineOutbox):
 *   .add(item)   -> encola una acción y avisa a la UI (evento 'outbox-actualizado').
 *   .drain()     -> intenta subir lo pendiente AHORA (no-op si offline).
 *   .count()     -> Promise<{pending, error}>.
 *   .razon(key)  -> texto legible de un motivo de error.
 * Evento: window dispatch 'outbox-actualizado' tras encolar y tras cada drain.
 */
(function () {
    'use strict';

    function toast(m, t) { if (window.showToast) window.showToast(m, t || 'info'); }
    // CSRF leído FRESCO en cada drain (no cachear: tras re-login el token cambia).
    function csrf() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }
    function avisar() { window.dispatchEvent(new CustomEvent('outbox-actualizado')); }

    var RAZONES = {
        frente_destino_inexistente:  'El frente destino ya no existe.',
        equipo_no_encontrado:        'El equipo ya no existe.',
        falla_abierta:               'El equipo tiene un reporte de falla abierto; se reintentará.',
        sin_permiso:                 'No tienes permiso para esta acción.',
        payload_invalido:            'Datos inválidos.',
        datos_incompletos:           'Datos incompletos.',
        accion_desconocida:          'Acción no reconocida.',
        error_servidor:              'Error del servidor; se reintentará.',
    };

    var drenando = false;

    async function drain() {
        if (drenando || !navigator.onLine || !window.OfflineDB) return;

        var todos = await window.OfflineDB.outboxList();
        var aSubir = todos.filter(function (it) { return it.status === 'pending' || it.status === 'error'; });
        if (!aSubir.length) return;

        drenando = true;
        try {
            var operations = aSubir.map(function (it) {
                return { client_uuid: it.client_uuid, action: it.action, payload: it.payload, client_ts: it.created };
            });

            var res;
            try {
                res = await fetch('/offline/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ operations: operations }),
                });
            } catch (e) {
                return; // red caída a mitad: la cola queda intacta, se reintenta luego
            }

            // Sesión expirada: NO descartar la cola; pedir re-login y reintentar después.
            if (res.status === 401 || res.status === 419) {
                if (window.showModal) {
                    window.showModal({
                        type: 'error', title: 'Sesión expirada',
                        message: 'Tu sesión expiró. Inicia sesión de nuevo para subir los cambios pendientes.',
                        confirmText: 'Iniciar sesión', hideCancel: true,
                        onConfirm: function () { window.location.href = '/login'; },
                    });
                } else {
                    toast('Sesión expirada. Inicia sesión para subir los pendientes.', 'error');
                }
                return;
            }
            if (!res.ok) return; // error servidor: dejar como está, reintentar luego

            var data = await res.json().catch(function () { return null; });
            if (!data || !Array.isArray(data.results)) return;

            var huboSynced = false, nError = 0;
            for (var i = 0; i < data.results.length; i++) {
                var r = data.results[i];
                if (r.status === 'synced') {
                    await window.OfflineDB.outboxRemove(r.client_uuid);
                    huboSynced = true;
                } else {
                    await window.OfflineDB.outboxUpdate(r.client_uuid, { status: 'error', reason: r.reason || '' });
                    nError++;
                }
            }

            if (huboSynced) {
                toast('Cambios sin conexión sincronizados con el servidor.', 'success');
                if (window.OfflineDB.sync) window.OfflineDB.sync(true);
            }
            if (nError) {
                toast(nError + ' cambio(s) no se pudieron aplicar; se reintentarán.', 'error');
            }
        } finally {
            drenando = false;
            avisar();
        }
    }

    // UUID v4 para identificar cada acción/lote (idempotencia en el servidor).
    function uuid() {
        if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        return 'oid-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    window.OfflineOutbox = {
        uuid: uuid,
        add: function (item) {
            if (!window.OfflineDB) return Promise.resolve(false);
            return window.OfflineDB.enqueue(item).then(function () {
                avisar();
                // Si HAY internet, subirlo al servidor AL INSTANTE (no esperar a recargar
                // ni a un evento 'online'): así una movilización hecha en modo consulta
                // local se refleja de inmediato para TODOS los usuarios. drain() es no-op
                // si ya se está drenando o si no hay conexión, así que es seguro llamarlo.
                if (navigator.onLine) drain();
                return true;
            });
        },
        drain: drain,
        razon: function (key) { return RAZONES[key] || key || 'Motivo desconocido'; },
        count: function () {
            if (!window.OfflineDB) return Promise.resolve({ pending: 0, error: 0 });
            return Promise.all([window.OfflineDB.countPending(), window.OfflineDB.countError ? window.OfflineDB.countError() : Promise.resolve(0)])
                .then(function (a) { return { pending: a[0], error: a[1] }; });
        },
    };

    // Disparadores automáticos: al cargar con internet y al volver la conexión.
    // (estructura_base.blade.php también llama drain() al confirmar servidor en
    //  comprobarConexion(), para el caso "wifi activo pero servidor estuvo caído".)
    if (navigator.onLine) setTimeout(drain, 800);
    window.addEventListener('online', function () { setTimeout(drain, 500); });
})();
