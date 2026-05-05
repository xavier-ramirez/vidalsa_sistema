/* global vidalsaDB, vidalsaOutbox, vidalsaSync */
/**
 * Vista offline de /admin/equipos.
 *
 * Cuando se detecta que estamos en /admin/equipos (o subrutas) Y sin conexión,
 * este módulo:
 *  1. Reemplaza el contenido de <main class="main-viewport"> con una vista
 *     simplificada generada desde SQLite local.
 *  2. Lista equipos como cards (con buscador in-memory).
 *  3. Click en card → modal con detalles + botones según permisos cacheados.
 *  4. Las acciones (movilizar, cambiar estado, reportar falla) se encolan
 *     en vidalsaOutbox y aparecen como "pendientes" hasta que vuelva la red.
 *
 * NUNCA reemplaza la vista cuando hay internet — la PWA se comporta como siempre.
 */
(function () {
    'use strict';

    const SUPPORTED_PATHS = ['/admin/equipos'];

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

    async function getCachedUser() {
        await vidalsaDB.init();
        const r = vidalsaDB.query("SELECT * FROM user_session LIMIT 1");
        if (!r.length) return null;
        const u = r[0];
        return {
            id: u.id, correo: u.correo, nombre: u.nombre, nivel: u.nivel,
            puede_editar:    !!u.puede_editar,
            puede_movilizar: !!u.puede_movilizar,
            puede_estado:    !!u.puede_estado,
            es_super_admin:  !!u.es_super_admin,
        };
    }

    function renderShell(user) {
        return `
            <div id="off-equipos-root" style="padding: 16px; max-width: 800px; margin: 0 auto;">
                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:10px 14px; margin-bottom:14px; color:#92400e; font-size:13px; display:flex; align-items:center; gap:8px;">
                    <i class="material-icons" style="font-size:18px;">cloud_off</i>
                    <span><strong>Modo sin conexión.</strong> Datos de la última sincronización. Las acciones se enviarán al servidor cuando vuelva Internet.</span>
                </div>
                <div style="display:flex; gap:8px; align-items:center; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:10px 14px; margin-bottom:14px;">
                    <i class="material-icons" style="color:#94a3b8;">search</i>
                    <input type="text" id="off-eq-search" placeholder="Buscar por código, marca, modelo, placa..." autocomplete="off"
                        style="flex:1; border:none; outline:none; font-size:14px; background:transparent;">
                </div>
                <div id="off-eq-counter" style="font-size:12px; color:#64748b; margin-bottom:8px;"></div>
                <div id="off-eq-list" style="display:flex; flex-direction:column; gap:8px;"></div>
            </div>

            <!-- Modal detalles offline -->
            <div id="off-eq-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9000; align-items:flex-end; justify-content:center;">
                <div style="background:white; width:100%; max-width:500px; max-height:92vh; border-top-left-radius:16px; border-top-right-radius:16px; display:flex; flex-direction:column; overflow:hidden;">
                    <div style="background:#00004d; color:white; padding:16px 20px; display:flex; justify-content:space-between; align-items:flex-start;">
                        <div style="flex:1; min-width:0;">
                            <h3 id="off-modal-tipo" style="margin:0; font-size:16px; font-weight:800; letter-spacing:0.04em;">EQUIPO</h3>
                            <div id="off-modal-sub" style="font-size:12px; color:rgba(255,255,255,0.8); margin-top:4px;"></div>
                        </div>
                        <button type="button" id="off-modal-close"
                            style="background:rgba(255,255,255,0.15); border:none; color:white; width:30px; height:30px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                            <i class="material-icons" style="font-size:18px;">close</i>
                        </button>
                    </div>
                    <div id="off-modal-body" style="padding:16px; overflow-y:auto;"></div>
                </div>
            </div>
        `;
    }

    function listEquipos(filterText = '') {
        const filter = filterText.trim().toUpperCase();
        const all = vidalsaDB.query("SELECT * FROM equipos ORDER BY CODIGO_PATIO");
        if (!filter) return all;
        return all.filter(e => {
            const hay = [e.CODIGO_PATIO, e.MARCA, e.MODELO, e.SERIAL_CHASIS, e.tipo_nombre, e.frente_nombre]
                .filter(Boolean).join(' ').toUpperCase();
            return hay.includes(filter);
        });
    }

    function renderList(equipos) {
        const list = document.getElementById('off-eq-list');
        const counter = document.getElementById('off-eq-counter');
        if (!list) return;
        if (counter) counter.textContent = `${equipos.length} equipo${equipos.length === 1 ? '' : 's'}`;

        if (equipos.length === 0) {
            list.innerHTML = `
                <div style="text-align:center; padding:32px; color:#64748b; background:white; border:1px dashed #cbd5e1; border-radius:12px;">
                    <i class="material-icons" style="font-size:32px; color:#cbd5e1;">search_off</i>
                    <p style="margin:8px 0 0; font-size:13px;">Sin coincidencias.</p>
                </div>`;
            return;
        }

        list.innerHTML = equipos.map(e => {
            const estado = (e.ESTADO_OPERATIVO || '').toUpperCase();
            const badgeStyle = estado === 'OPERATIVO' ? 'background:#dcfce7;color:#16a34a'
                : estado === 'INOPERATIVO' ? 'background:#fee2e2;color:#dc2626'
                : 'background:#fef3c7;color:#b45309';
            return `
                <div class="off-eq-card" data-id="${e.ID_EQUIPO}"
                    style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-weight:800; color:#0f172a; font-size:14px;">${escapeHtml(e.CODIGO_PATIO || 'S/C')}</span>
                        <span style="font-size:10px; padding:3px 8px; border-radius:12px; font-weight:800; ${badgeStyle};">${escapeHtml(estado || '—')}</span>
                    </div>
                    <div style="font-size:13px; color:#1e293b; font-weight:600;">${escapeHtml(e.MARCA || '')} ${escapeHtml(e.MODELO || '')}</div>
                    <div style="font-size:11px; color:#64748b; margin-top:4px; display:flex; gap:12px; flex-wrap:wrap;">
                        <span><i class="material-icons" style="font-size:12px; vertical-align:middle;">place</i> ${escapeHtml(e.frente_nombre || 'Sin frente')}</span>
                        <span>${escapeHtml(e.tipo_nombre || '')}</span>
                    </div>
                </div>`;
        }).join('');

        list.querySelectorAll('.off-eq-card').forEach(card => {
            card.addEventListener('click', () => openModal(card.getAttribute('data-id')));
        });
    }

    async function openModal(id) {
        const equipos = vidalsaDB.query("SELECT * FROM equipos WHERE ID_EQUIPO = ?", [id]);
        if (!equipos.length) return;
        const eq = equipos[0];
        const docs = vidalsaDB.query("SELECT * FROM documentacion WHERE ID_EQUIPO = ?", [id]);
        const doc = docs.length ? docs[0] : {};
        const responsables = vidalsaDB.query(
            "SELECT * FROM responsables WHERE ID_EQUIPO = ? ORDER BY FECHA_ASIGNACION DESC LIMIT 3", [id]
        );

        const u = await getCachedUser() || {};

        document.getElementById('off-modal-tipo').textContent = (eq.tipo_nombre || eq.TIPO_EQUIPO || 'EQUIPO').toUpperCase();
        document.getElementById('off-modal-sub').textContent  = `Serial: ${eq.SERIAL_CHASIS || 'S/S'}`;

        const row = (label, value) => `
            <div style="display:flex; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px solid #f1f5f9;">
                <span style="color:#64748b; font-size:12px;">${escapeHtml(label)}</span>
                <span style="color:#0f172a; font-size:13px; font-weight:600; text-align:right;">${escapeHtml(value || '—')}</span>
            </div>`;

        const accionBtn = (id, label, icon, color) =>
            `<button type="button" data-action="${id}"
                style="flex:1 1 calc(50% - 4px); display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:10px; border:none; cursor:pointer; border-radius:8px; background:${color}; color:white; font-weight:700; font-size:12px; font-family:inherit;">
                <i class="material-icons" style="font-size:16px;">${icon}</i> ${label}
            </button>`;

        const acciones = [];
        if (u.puede_movilizar) acciones.push(accionBtn('movilizar', 'Movilizar', 'local_shipping', '#0067b1'));
        if (u.puede_estado)    acciones.push(accionBtn('estado',    'Estado',    'swap_horiz',     '#d97706'));
        acciones.push(accionBtn('falla', 'Falla', 'report_problem', '#dc2626'));

        document.getElementById('off-modal-body').innerHTML = `
            ${doc.PLACA ? row('Placa', doc.PLACA) : ''}
            ${row('Tipo',           eq.tipo_nombre || eq.TIPO_EQUIPO)}
            ${row('Marca / Modelo', `${eq.MARCA || ''} ${eq.MODELO || ''}`)}
            ${row('Año',            eq.ANIO)}
            ${row('Categoría',      eq.CATEGORIA_FLOTA)}
            ${row('Estado',         eq.ESTADO_OPERATIVO)}
            ${row('Frente',         eq.frente_nombre || 'Sin asignar')}
            ${row('Detalle Ubic.',  eq.DETALLE_UBICACION_ACTUAL)}
            ${doc.NRO_DE_DOCUMENTO ? row('Nro. Documento', doc.NRO_DE_DOCUMENTO) : ''}
            ${responsables.length ? `<div style="margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0;">
                <div style="font-size:12px; color:#64748b; font-weight:700; margin-bottom:6px;">RESPONSABLES</div>
                ${responsables.map(r => `<div style="font-size:13px; color:#1e293b;">${escapeHtml(r.PERSONA_ASIGNADA || '')} <span style="color:#94a3b8; font-size:11px;">(CI ${escapeHtml(r.CEDULA_RESPONSABLE || '—')})</span></div>`).join('')}
            </div>` : ''}
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid #e2e8f0; display:flex; flex-wrap:wrap; gap:6px;">
                ${acciones.join('')}
            </div>
        `;

        // Bind acciones offline (encolan a outbox con permiso check)
        document.querySelectorAll('#off-modal-body button[data-action]').forEach(btn => {
            btn.addEventListener('click', () => handleAction(btn.getAttribute('data-action'), eq, u));
        });

        document.getElementById('off-eq-modal').style.display = 'flex';
    }

    async function handleAction(action, eq, u) {
        let result;
        if (action === 'estado') {
            if (!u.puede_estado) { window.showToast?.('No tienes permiso para cambiar estado.', 'error'); return; }
            const nuevo = prompt('Nuevo estado:\nOPERATIVO / EN MANTENIMIENTO / INOPERATIVO / DESINCORPORADO');
            if (!nuevo) return;
            const valor = nuevo.trim().toUpperCase();
            if (!['OPERATIVO','EN MANTENIMIENTO','INOPERATIVO','DESINCORPORADO'].includes(valor)) {
                window.showToast?.('Estado inválido.', 'error'); return;
            }
            // Optimistic update local
            vidalsaDB.run("UPDATE equipos SET ESTADO_OPERATIVO = ? WHERE ID_EQUIPO = ?", [valor, eq.ID_EQUIPO]);
            await vidalsaDB.persist();
            await vidalsaOutbox.enqueue({
                op: 'PATCH',
                endpoint: `/admin/equipos/${eq.ID_EQUIPO}/estado`,
                body: { estado: valor },
            });
            window.showToast?.(`Estado pendiente de sincronizar: ${valor}`, 'success');
            closeModal();
            renderList(listEquipos(document.getElementById('off-eq-search')?.value || ''));
        } else if (action === 'movilizar') {
            if (!u.puede_movilizar) { window.showToast?.('No tienes permiso para movilizar.', 'error'); return; }
            const frentes = vidalsaDB.query("SELECT * FROM frentes_trabajo WHERE ESTATUS_FRENTE = 'ACTIVO' ORDER BY NOMBRE_FRENTE");
            if (!frentes.length) { window.showToast?.('Sin frentes en cache.', 'error'); return; }
            const opciones = frentes.map((f, i) => `${i + 1}. ${f.NOMBRE_FRENTE}`).join('\n');
            const idx = parseInt(prompt(`Seleccione frente destino (número):\n${opciones}`), 10);
            if (!idx || idx < 1 || idx > frentes.length) return;
            const dest = frentes[idx - 1];
            // Optimistic local
            vidalsaDB.run("UPDATE equipos SET ID_FRENTE_ACTUAL = ?, frente_nombre = ? WHERE ID_EQUIPO = ?",
                [dest.ID_FRENTE, dest.NOMBRE_FRENTE, eq.ID_EQUIPO]);
            await vidalsaDB.persist();
            await vidalsaOutbox.enqueue({
                op: 'POST',
                endpoint: `/admin/movilizaciones`,
                body: { ID_EQUIPO: eq.ID_EQUIPO, ID_FRENTE_DESTINO: dest.ID_FRENTE },
            });
            window.showToast?.(`Movilización pendiente: ${dest.NOMBRE_FRENTE}`, 'success');
            closeModal();
            renderList(listEquipos(document.getElementById('off-eq-search')?.value || ''));
        } else if (action === 'falla') {
            const desc = prompt('Describe la falla (mínimo 5 caracteres):');
            if (!desc || desc.trim().length < 5) return;
            await vidalsaOutbox.enqueue({
                op: 'POST',
                endpoint: `/admin/fallas`,
                body: {
                    ACTIVO_TIPO: 'equipo',
                    ACTIVO_ID:   eq.ID_EQUIPO,
                    DESCRIPCION_AVERIA: desc.trim(),
                    PRIORIDAD: 'MEDIA',
                    TIPO_INTERVENCION: 'CORRECTIVA',
                },
            });
            window.showToast?.('Falla pendiente de sincronizar.', 'success');
            closeModal();
        }
        if (window.vidalsaOfflineShim) window.vidalsaOfflineShim.refreshOutbox();
    }

    function closeModal() {
        const m = document.getElementById('off-eq-modal');
        if (m) m.style.display = 'none';
    }

    async function activate() {
        const host = findHost();
        if (!host) return;

        const u = await getCachedUser();
        if (!u) {
            host.innerHTML = `
                <div style="padding:30px; text-align:center;">
                    <i class="material-icons" style="font-size:48px; color:#94a3b8;">cloud_off</i>
                    <h3 style="color:#0f172a;">Sin sesión local</h3>
                    <p style="color:#64748b; font-size:13px;">Necesitas iniciar sesión con Internet al menos una vez para usar el modo offline.</p>
                </div>`;
            return;
        }

        host.innerHTML = renderShell(u);

        renderList(listEquipos(''));

        const search = document.getElementById('off-eq-search');
        if (search) search.addEventListener('input', () => renderList(listEquipos(search.value)));

        document.getElementById('off-modal-close')?.addEventListener('click', closeModal);
        document.getElementById('off-eq-modal')?.addEventListener('click', e => {
            if (e.target.id === 'off-eq-modal') closeModal();
        });
    }

    async function maybeActivate() {
        if (navigator.onLine) return;       // online: no tocamos la página real
        if (!isSupportedPath()) return;
        await vidalsaDB.init();
        await activate();
    }

    document.addEventListener('DOMContentLoaded', () => {
        maybeActivate();
        window.addEventListener('offline', maybeActivate);
        // Si vuelve la red estando en la vista offline, recargamos para volver a la vista normal del server
        window.addEventListener('online', () => {
            if (document.getElementById('off-equipos-root') && navigator.onLine) {
                location.reload();
            }
        });
    });
})();
