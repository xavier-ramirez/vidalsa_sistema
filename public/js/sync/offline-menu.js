/* global vidalsaDB */
/**
 * Vista offline para /menu y /dashboard cuando no hay conexión.
 *
 * Muestra:
 *  - Saludo con nombre cacheado
 *  - KPIs leídos del SQLite local (total equipos, por estado, movs últimos 30 días)
 *  - Accesos directos a las vistas offline soportadas
 *  - Botón sincronizar (delegado al shim)
 */
(function () {
    'use strict';

    const SUPPORTED_PATHS = ['/menu', '/dashboard'];

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

    function getCachedUser() {
        const r = vidalsaDB.query("SELECT * FROM user_session LIMIT 1");
        return r.length ? r[0] : null;
    }

    function computeKpis() {
        const equipos = vidalsaDB.query("SELECT * FROM equipos");
        const total = equipos.length;
        const operativo    = equipos.filter(e => (e.ESTADO_OPERATIVO||'').toUpperCase() === 'OPERATIVO').length;
        const inoperativo  = equipos.filter(e => (e.ESTADO_OPERATIVO||'').toUpperCase() === 'INOPERATIVO').length;
        const mantenimiento = equipos.filter(e => (e.ESTADO_OPERATIVO||'').toUpperCase() === 'EN MANTENIMIENTO').length;
        const movs = vidalsaDB.query(`
            SELECT COUNT(*) AS c FROM movilizacion_historial
            WHERE FECHA_DESPACHO >= datetime('now', '-30 days')
        `);
        const movs30 = movs.length ? movs[0].c : 0;
        return { total, operativo, inoperativo, mantenimiento, movs30 };
    }

    function kpiCard(label, value, icon, color) {
        return `
            <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:14px; flex:1 1 calc(50% - 4px); min-width:140px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="display:flex; align-items:center; gap:8px; color:${color};">
                    <i class="material-icons" style="font-size:22px;">${icon}</i>
                    <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">${escapeHtml(label)}</span>
                </div>
                <div style="font-size:26px; font-weight:800; color:#0f172a; margin-top:6px;">${value}</div>
            </div>`;
    }

    function linkCard(label, icon, href, color) {
        return `
            <a href="${href}" style="text-decoration:none; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:14px; flex:1 1 calc(50% - 4px); min-width:140px; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; align-items:center; gap:10px; color:#0f172a;">
                <span style="background:${color}; color:white; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center;">
                    <i class="material-icons" style="font-size:20px;">${icon}</i>
                </span>
                <span style="font-size:13px; font-weight:700;">${escapeHtml(label)}</span>
            </a>`;
    }

    async function activate() {
        await vidalsaDB.init();
        const host = findHost();
        if (!host) return;
        const u = getCachedUser();
        const k = computeKpis();

        host.innerHTML = `
            <div style="padding:16px; max-width:900px; margin:0 auto;">
                <div style="background:linear-gradient(135deg,#0067b1,#004481); color:white; border-radius:14px; padding:18px 20px; margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                        <div style="min-width:0;">
                            <div style="font-size:11px; opacity:0.85; font-weight:700; letter-spacing:0.05em;">MODO SIN CONEXIÓN</div>
                            <div style="font-size:18px; font-weight:800; margin-top:4px;">Hola, ${escapeHtml(u?.nombre || u?.correo || 'usuario')}</div>
                            <div style="font-size:12px; opacity:0.85; margin-top:4px;">Nivel ${escapeHtml(u?.nivel || '—')} · Datos de la última sincronización</div>
                        </div>
                        <i class="material-icons" style="font-size:34px; opacity:0.7;">cloud_off</i>
                    </div>
                </div>

                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:0.06em; margin-bottom:8px;">RESUMEN LOCAL</div>
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px;">
                    ${kpiCard('Equipos', k.total, 'precision_manufacturing', '#0067b1')}
                    ${kpiCard('Operativos', k.operativo, 'check_circle', '#16a34a')}
                    ${kpiCard('Inoperativos', k.inoperativo, 'block', '#dc2626')}
                    ${kpiCard('Mantenimiento', k.mantenimiento, 'build', '#d97706')}
                    ${kpiCard('Movs. 30 días', k.movs30, 'local_shipping', '#7c3aed')}
                </div>

                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:0.06em; margin-bottom:8px;">DISPONIBLE OFFLINE</div>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    ${linkCard('Equipos',         'precision_manufacturing', '/admin/equipos',         '#0067b1')}
                    ${linkCard('Movilizaciones',  'local_shipping',           '/admin/movilizaciones',  '#7c3aed')}
                </div>

                <div style="margin-top:18px; padding:12px 14px; background:#f1f5f9; border-radius:10px; font-size:12px; color:#475569;">
                    <i class="material-icons" style="font-size:14px; vertical-align:middle; color:#64748b;">info</i>
                    Tus cambios se guardan en este dispositivo y se enviarán automáticamente cuando vuelva la red.
                </div>
            </div>
        `;
    }

    function isOfflineMode() {
        // Tres formas: navegador offline, modo forzado en sessionStorage, o ?offline=1 en URL
        if (!navigator.onLine) return true;
        if (sessionStorage.getItem('vidalsa_offline_mode') === '1') return true;
        if (new URLSearchParams(location.search).get('offline') === '1') return true;
        return false;
    }

    async function maybeActivate() {
        if (!isOfflineMode()) return;
        if (!isSupportedPath()) return;
        await activate();
    }

    document.addEventListener('DOMContentLoaded', () => {
        maybeActivate();
        window.addEventListener('offline', maybeActivate);
        window.addEventListener('online', () => {
            if (!navigator.onLine) return;
            // Si la pantalla offline está activa, recargamos para mostrar el menú real del server
            const off = document.querySelector('a[href="/admin/equipos"]');
            if (off && document.body.textContent.includes('MODO SIN CONEXIÓN')) location.reload();
        });
    });
})();
