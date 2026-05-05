/* global vidalsaDB, vidalsaOutbox, vidalsaScheduler, vidalsaSync */
/**
 * Offline shim para la PWA.
 *
 * Pinta:
 *  - Banner naranja "Modo sin conexión" arriba cuando navigator.onLine === false.
 *  - Píldora "X pendiente(s)" en el banner si hay operaciones en outbox.
 *  - Botón "Sincronizar" siempre visible que dispara vidalsaScheduler.runManual().
 *  - Toast cuando vuelve la red y la outbox se sube.
 *
 * No reemplaza vistas — solo agrega indicadores. La adaptación de las vistas
 * (leer SQLite cuando offline) se hace por vista en próximas iteraciones.
 */
(function () {
    'use strict';

    let _bar = null;
    let _outboxBadge = null;
    let _onlineLabel = null;

    function buildBar() {
        if (_bar) return _bar;
        const bar = document.createElement('div');
        bar.id = 'vidalsa-offline-bar';
        bar.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 8px 14px;
            display: none;
            align-items: center; justify-content: space-between; gap: 10px;
            z-index: 9990;
            font-size: 13px; font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.18);
            font-family: inherit;
        `;
        bar.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                <i class="material-icons" style="font-size:18px;">cloud_off</i>
                <span id="vidalsa-offline-label">Modo sin conexión</span>
                <span id="vidalsa-outbox-badge" style="display:none; background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:999px; font-size:11px;"></span>
            </div>
            <button type="button" id="vidalsa-sync-btn"
                style="background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:white; padding:5px 12px; border-radius:8px; cursor:pointer; font-weight:700; font-size:12px; font-family:inherit; display:inline-flex; align-items:center; gap:5px;">
                <i class="material-icons" style="font-size:14px;">sync</i> Sincronizar
            </button>
        `;
        document.body.appendChild(bar);
        _bar = bar;
        _onlineLabel = bar.querySelector('#vidalsa-offline-label');
        _outboxBadge = bar.querySelector('#vidalsa-outbox-badge');
        bar.querySelector('#vidalsa-sync-btn').addEventListener('click', onSyncClick);
        return bar;
    }

    async function onSyncClick(ev) {
        const btn = ev.currentTarget;
        if (btn.dataset.busy) return;
        btn.dataset.busy = '1';
        btn.style.opacity = '0.6';
        try {
            if (window.vidalsaScheduler && typeof window.vidalsaScheduler.runManual === 'function') {
                const r = await window.vidalsaScheduler.runManual();
                if (window.showToast) {
                    if (r && !r.error) window.showToast('Sincronización completa.', 'success');
                    else window.showToast('No se pudo sincronizar: ' + (r?.error || 'sin red'), 'error');
                }
            }
        } catch (e) {
            if (window.showToast) window.showToast('Error: ' + e.message, 'error');
        } finally {
            btn.dataset.busy = '';
            btn.style.opacity = '1';
            await refreshOutboxBadge();
        }
    }

    function setVisible(yes) {
        const bar = buildBar();
        bar.style.display = yes ? 'flex' : 'none';
        // Ajustar padding-top del body para no tapar contenido cuando se muestra
        if (yes) document.body.style.paddingTop = (bar.offsetHeight || 36) + 'px';
        else     document.body.style.paddingTop = '';
    }

    async function refreshOutboxBadge() {
        if (!_outboxBadge || !window.vidalsaOutbox) return;
        try {
            const count = await window.vidalsaOutbox.pendingCount();
            if (count > 0) {
                _outboxBadge.style.display = '';
                _outboxBadge.textContent = `${count} pendiente${count === 1 ? '' : 's'}`;
            } else {
                _outboxBadge.style.display = 'none';
            }
        } catch { /* ignore */ }
    }

    function updateState() {
        const offline = !navigator.onLine;
        if (offline) {
            buildBar();
            if (_onlineLabel) _onlineLabel.textContent = 'Modo sin conexión';
            setVisible(true);
            refreshOutboxBadge();
        } else {
            // Si hay outbox pendientes, dejamos visible la barra (en azul) hasta sincronizar
            refreshOutboxBadge().then(() => {
                if (_outboxBadge && _outboxBadge.style.display !== 'none') {
                    buildBar();
                    if (_bar) _bar.style.background = 'linear-gradient(135deg, #0067b1, #004481)';
                    if (_onlineLabel) _onlineLabel.textContent = 'Conectado';
                    setVisible(true);
                } else {
                    setVisible(false);
                    if (_bar) _bar.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateState();
        window.addEventListener('online',  updateState);
        window.addEventListener('offline', updateState);
        // Refresca el badge cada minuto (por si la outbox cambió desde otra pestaña)
        setInterval(refreshOutboxBadge, 60_000);
    });

    window.vidalsaOfflineShim = {
        refresh: updateState,
        refreshOutbox: refreshOutboxBadge,
    };
})();
