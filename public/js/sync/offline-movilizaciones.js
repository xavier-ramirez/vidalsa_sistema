/* global vidalsaDB */
/**
 * Vista offline de /admin/movilizaciones (historial).
 *
 * Lee de movilizacion_historial en SQLite. Solo activa cuando offline.
 * Filtros simples: texto libre (origen/destino/equipo) + por equipo.
 */
(function () {
    'use strict';

    const SUPPORTED_PATHS = ['/admin/movilizaciones'];

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function isSupportedPath() {
        const p = location.pathname;
        return SUPPORTED_PATHS.some(prefix => p === prefix || p.startsWith(prefix + '/'));
    }

    function findHost() {
        return document.querySelector('main.main-viewport') || document.querySelector('main') || document.body;
    }

    function fmtDate(iso) {
        if (!iso) return '—';
        try {
            const d = new Date(iso);
            return d.toLocaleString('es-VE', { day:'2-digit', month:'short', year:'2-digit', hour:'2-digit', minute:'2-digit' });
        } catch { return iso; }
    }

    function listMovs(filterText = '') {
        const filter = filterText.trim().toUpperCase();
        const all = vidalsaDB.query(`
            SELECT m.*, e.CODIGO_PATIO, e.MARCA, e.MODELO
            FROM movilizacion_historial m
            LEFT JOIN equipos e ON e.ID_EQUIPO = m.ID_EQUIPO
            ORDER BY m.FECHA_DESPACHO DESC
            LIMIT 500
        `);
        if (!filter) return all;
        return all.filter(m => {
            const hay = [m.CODIGO_CONTROL, m.NOMBRE_ORIGEN, m.NOMBRE_DESTINO, m.CODIGO_PATIO, m.MARCA, m.MODELO, m.USUARIO_REGISTRO]
                .filter(Boolean).join(' ').toUpperCase();
            return hay.includes(filter);
        });
    }

    function render(equipos) {
        const list = document.getElementById('off-mov-list');
        const counter = document.getElementById('off-mov-counter');
        if (!list) return;
        if (counter) counter.textContent = `${equipos.length} movilización${equipos.length === 1 ? '' : 'es'}`;

        if (equipos.length === 0) {
            list.innerHTML = `
                <div style="text-align:center; padding:32px; color:#64748b; background:white; border:1px dashed #cbd5e1; border-radius:12px;">
                    <i class="material-icons" style="font-size:32px; color:#cbd5e1;">history</i>
                    <p style="margin:8px 0 0; font-size:13px;">Sin historial offline.</p>
                </div>`;
            return;
        }

        list.innerHTML = equipos.map(m => {
            return `
                <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-weight:800; color:#0067b1; font-size:13px;">${escapeHtml(m.CODIGO_CONTROL || 'S/C')}</span>
                        <span style="font-size:11px; color:#64748b;">${fmtDate(m.FECHA_DESPACHO)}</span>
                    </div>
                    <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:4px;">
                        ${escapeHtml(m.CODIGO_PATIO || '')} · ${escapeHtml(m.MARCA || '')} ${escapeHtml(m.MODELO || '')}
                    </div>
                    <div style="font-size:12px; color:#1e293b; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <span>${escapeHtml(m.NOMBRE_ORIGEN || '—')}</span>
                        <i class="material-icons" style="font-size:14px; color:#94a3b8;">arrow_forward</i>
                        <span>${escapeHtml(m.NOMBRE_DESTINO || '—')}</span>
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">
                        ${escapeHtml(m.TIPO_MOVIMIENTO || '')} · ${escapeHtml(m.USUARIO_REGISTRO || '')}
                    </div>
                </div>`;
        }).join('');
    }

    function renderShell() {
        return `
            <div style="padding:16px; max-width:800px; margin:0 auto;">
                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:10px 14px; margin-bottom:14px; color:#92400e; font-size:13px; display:flex; align-items:center; gap:8px;">
                    <i class="material-icons" style="font-size:18px;">cloud_off</i>
                    <span><strong>Modo sin conexión.</strong> Historial de los últimos 90 días según última sincronización.</span>
                </div>
                <div style="display:flex; gap:8px; align-items:center; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:10px 14px; margin-bottom:14px;">
                    <i class="material-icons" style="color:#94a3b8;">search</i>
                    <input type="text" id="off-mov-search" placeholder="Buscar código, equipo, frente..." autocomplete="off"
                        style="flex:1; border:none; outline:none; font-size:14px; background:transparent;">
                </div>
                <div id="off-mov-counter" style="font-size:12px; color:#64748b; margin-bottom:8px;"></div>
                <div id="off-mov-list" style="display:flex; flex-direction:column; gap:8px;"></div>
            </div>
        `;
    }

    async function activate() {
        const host = findHost();
        if (!host) return;
        host.innerHTML = renderShell();
        render(listMovs(''));
        const search = document.getElementById('off-mov-search');
        if (search) search.addEventListener('input', () => render(listMovs(search.value)));
    }

    async function maybeActivate() {
        if (navigator.onLine) return;
        if (!isSupportedPath()) return;
        await vidalsaDB.init();
        await activate();
    }

    document.addEventListener('DOMContentLoaded', () => {
        maybeActivate();
        window.addEventListener('offline', maybeActivate);
        window.addEventListener('online', () => {
            // Si está en la vista offline custom, recargar para volver a la vista server
            if (document.getElementById('off-mov-list') && navigator.onLine) {
                location.reload();
            }
        });
    });
})();
