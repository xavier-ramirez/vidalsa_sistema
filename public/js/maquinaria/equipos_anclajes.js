/*
 * equipos_anclajes.js
 *
 * Este codigo vivia dentro del HTML de la vista y viajaba entero en CADA apertura del
 * modulo. Aqui se baja una sola vez y el navegador lo reutiliza.
 *
 * Es una FUNCION de arranque, no un bloque suelto, porque el modulo necesita volver a
 * correr en cada apertura para engancharse a la tabla nueva: la SPA no re-ejecuta un
 * <script src> ya cargado, asi que es el Blade quien llama a equiposAnclajesArrancar(CFG)
 * cada vez que se monta la pantalla. Lo que cambia entre una apertura y otra —rutas,
 * permisos y catalogos— llega en ese CFG.
 */
window.equiposAnclajesArrancar = function (EQANC_CFG) {
    EQANC_CFG = EQANC_CFG || {};
    function openAnclajesListModal() {
        document.getElementById('splitDropdownMenu').style.display = 'none';
        const modal = document.getElementById('anclajesListModal');
        modal.classList.add('active');
        document.getElementById('anclajesLoading').style.display = 'block';
        document.getElementById('anclajesBody').style.display = 'none';

        // Hereda los filtros activos del listado principal (id_frente, id_tipo).
        let fValue = '', tValue = '';
        const fInput = document.querySelector('input[name="id_frente"][data-filter-value]');
        const tInput = document.querySelector('input[name="id_tipo"][data-filter-value]');
        if (fInput && fInput.value && fInput.value !== 'all') fValue = fInput.value;
        if (tInput && tInput.value && tInput.value !== 'all') tValue = tInput.value;
        const _qsAnch = new URLSearchParams();
        if (fValue) _qsAnch.set('frente_id', fValue);
        if (tValue) _qsAnch.set('id_tipo', tValue);

        window.apiFetch(EQANC_CFG.rutaEquiposGetAnchors + (_qsAnch.toString() ? ('?' + _qsAnch.toString()) : ''))
            .then(res => res.json())
            .then(data => {
                window.lastAnclajesData = data; // Store globally for export
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';

                // Backend ahora retorna { pairs, aux }: pairs = anclajes equipo↔equipo,
                // aux = grupos equipo→auxiliares (1 host con N aux). Antes era array
                // plano de pares — defensivo: si el backend devuelve array (legacy),
                // lo tratamos como pairs sin aux.
                const pairs = Array.isArray(data) ? data : (Array.isArray(data.pairs) ? data.pairs : []);
                const auxGroups = (data && Array.isArray(data.aux)) ? data.aux : [];

                const grid = document.getElementById('anclajesGrid');
                const esc = window.escapeHtml;   // helper central (dom_helpers.js)

                // ── Encabezado de sección reutilizable ──────────────────────
                const sectionHeader = (icon, color, title, count) =>
                    `<div style="grid-column:1/-1; display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border-radius:10px; border-left:4px solid ${color}; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-top:4px;">
                        <i class="material-icons" style="font-size:18px;color:${color};">${icon}</i>
                        <span style="font-size:13px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.4px;flex:1;">${title}</span>
                        <span style="background:${color};color:#fff;font-size:11px;font-weight:800;padding:2px 10px;border-radius:10px;">${count}</span>
                    </div>`;

                let html = '';

                // ── Sección 1: Pares Remolcador / Remolcado ─────────────────
                html += sectionHeader('link', '#2563eb', 'Pares Remolcador / Remolcado', pairs.length);

                if (pairs.length === 0) {
                    html += `<div style="grid-column:1/-1; text-align:center; padding:18px; color:#94a3b8; background:#fff; border-radius:8px; border:1px dashed #cbd5e1; font-size:13px;">Sin pares de equipos anclados en esta selección.</div>`;
                }

                pairs.forEach(pair => {
                    const a = pair.eq_a;
                    const b = pair.eq_b;
                    if(!a || !b) return;

                    // Compute primary identification (Placa or Serial)
                    const aPlacaOrSerial = (a.placa && a.placa !== 'S/P') ? a.placa : (a.serial || 'N/A');
                    const bPlacaOrSerial = (b.placa && b.placa !== 'S/P') ? b.placa : (b.serial || 'N/A');

                    // Compute Tags (Type + Label)
                    const aEtiquetaHtml = a.etiqueta ? `<span style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; font-weight: 800; color: #475569; margin-left: 5px; font-size: 10px;">#${a.etiqueta}</span>` : '';
                    const bEtiquetaHtml = b.etiqueta ? `<span style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; font-weight: 800; color: #475569; margin-left: 5px; font-size: 10px;">#${b.etiqueta}</span>` : '';

                    const aFotoHtml = a.foto ? `<img src="${a.foto}" onerror="this.outerHTML='<div style=&quot;width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;&quot;><i class=&quot;material-icons&quot; style=&quot;color: #cbd5e1; font-size: 14px;&quot;>directions_car</i></div>'" style="width: 32px; height: 26px; object-fit: contain; border-radius: 5px; background: #fff; border: 1px solid #e2e8f0; flex-shrink: 0;">` : `<div style="width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;"><i class="material-icons" style="color: #cbd5e1; font-size: 14px;">directions_car</i></div>`;
                    const bFotoHtml = b.foto ? `<img src="${b.foto}" onerror="this.outerHTML='<div style=&quot;width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;&quot;><i class=&quot;material-icons&quot; style=&quot;color: #cbd5e1; font-size: 14px;&quot;>directions_car</i></div>'" style="width: 32px; height: 26px; object-fit: contain; border-radius: 5px; background: #fff; border: 1px solid #e2e8f0; flex-shrink: 0;">` : `<div style="width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;"><i class="material-icons" style="color: #cbd5e1; font-size: 14px;">directions_car</i></div>`;

                    html += `
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; display: flex; flex-direction: column; align-items: stretch; gap: 0; box-shadow: 0 1px 4px rgba(0,0,0,0.06); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,0.06)'">
                        
                        <!-- Equipo A -->
                        <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 5px 8px; border-radius: 6px; border: 1px solid #f1f5f9;">
                            ${aFotoHtml}
                            <div style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                                <span style="font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.4px;">${a.tipo || 'Sin Tipo'}${aEtiquetaHtml}</span>
                                <span style="font-size: 12px; font-weight: 800; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;">${aPlacaOrSerial}</span>
                            </div>
                        </div>
                        
                        <!-- Icono Link Central -->
                        <div style="display: flex; justify-content: center; align-items: center; height: 14px; position: relative;">
                            <div style="position: absolute; inset: 0 calc(50% - 1px); background: #e2e8f0; width: 1px; margin: 0 auto;"></div>
                            <div style="background: #dbeafe; width: 18px; height: 18px; border-radius: 50%; color: #2563eb; z-index: 2; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; position: relative;">
                                <i class="material-icons" style="font-size: 10px; transform: rotate(90deg);">link</i>
                            </div>
                        </div>

                        <!-- Equipo B -->
                        <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 5px 8px; border-radius: 6px; border: 1px solid #f1f5f9;">
                            ${bFotoHtml}
                            <div style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                                <span style="font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.4px;">${b.tipo || 'Sin Tipo'}${bEtiquetaHtml}</span>
                                <span style="font-size: 12px; font-weight: 800; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;">${bPlacaOrSerial}</span>
                            </div>
                        </div>

                    </div>`;
                });

                // ── Sección 2: Equipos con Auxiliares Anclados ──────────────
                html += sectionHeader('construction', '#d97706', 'Equipos con Auxiliares Anclados', auxGroups.length);

                if (auxGroups.length === 0) {
                    html += `<div style="grid-column:1/-1; text-align:center; padding:18px; color:#94a3b8; background:#fff7ed; border-radius:8px; border:1px dashed #fed7aa; font-size:13px;">Sin equipos con auxiliares anclados.<br><span style="font-size:11px;margin-top:4px;display:block;">Para vincular un auxiliar a un equipo host, edítalo en <strong>Equipos Auxiliares</strong>.</span></div>`;
                }

                // ─── Anclajes equipo→auxiliares (1 tarjeta por host con sus aux) ───
                auxGroups.forEach(g => {
                    const h = g.host || {};
                    const auxes = Array.isArray(g.auxes) ? g.auxes : [];
                    if (!h.id || auxes.length === 0) return;
                    const hostLabel = h.placa || h.serial || h.codigo || ('#' + h.id);
                    const hostType  = (h.tipo || 'Equipo').toString();
                    const hostMarca = h.marca ? esc(h.marca) : '';
                    const hostFotoHtml = h.foto
                        ? `<img src="${esc(h.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:22px;color:#1e40af;&quot;>directions_car</i>'">`
                        : '<i class="material-icons" style="font-size:22px;color:#1e40af;">directions_car</i>';

                    const auxRowsHtml = auxes.map(a => {
                        const auxLabel = a.serial || ((a.marca || '') + ' ' + (a.modelo || '')).trim() || '—';
                        const auxFotoHtml = a.foto
                            ? `<img src="${esc(a.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:16px;color:#f59e0b;&quot;>construction</i>'">`
                            : '<i class="material-icons" style="font-size:16px;color:#f59e0b;">construction</i>';
                        return `<div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#fff7ed; border-radius:6px; border:1px solid #fed7aa;">
                            <div style="background:#fff;padding:0;border-radius:5px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #fed7aa;">${auxFotoHtml}</div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.3px;">${esc(a.tipo_label || a.tipo || 'AUXILIAR')}</div>
                                <div style="font-size:12px; font-weight:800; color:#7c2d12; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(auxLabel)}</div>
                                ${a.marca || a.modelo ? `<div style="font-size:10px; color:#9a3412;">${esc(a.marca||'')} ${esc(a.modelo||'')}</div>` : ''}
                            </div>
                        </div>`;
                    }).join('');

                    html += `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                        <div style="display:flex; align-items:center; gap:10px; padding:8px 10px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                            <div style="background:#fff;padding:0;border-radius:6px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #bfdbfe;">${hostFotoHtml}</div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9.5px; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:0.4px;">${esc(hostType)}</div>
                                <div style="font-size:14px; font-weight:800; color:#1e3a8a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(hostLabel)}</div>
                                ${hostMarca ? `<div style="font-size:10.5px; color:#1d4ed8; margin-top:1px;">${hostMarca} ${esc(h.modelo||'')}</div>` : ''}
                            </div>
                            <span style="background:#10b981;color:white;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;flex-shrink:0;">${auxes.length}</span>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:5px;">${auxRowsHtml}</div>
                    </div>`;
                });

                grid.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading anchors:', err);
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';
                document.getElementById('anclajesGrid').innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 20px;">Error al cargar anclajes.</div>';
            });
    }

    // Exporta los anclajes a XLSX generado por PhpSpreadsheet (backend) con el
    // mismo encabezado corporativo de los demas reportes del sistema.
    window.exportAnclajesToExcel = function() {
        const data = window.lastAnclajesData || {};
        const _pairs = Array.isArray(data) ? data : (Array.isArray(data.pairs) ? data.pairs : []);
        const _aux   = (data && Array.isArray(data.aux)) ? data.aux : [];
        if (_pairs.length === 0 && _aux.length === 0) {
            if (typeof window.showToast === 'function') {
                window.showToast('No hay equipos anclados para exportar.', 'warning');
            } else {
                alert('No hay datos para exportar.');
            }
            return;
        }
        // Hereda los filtros activos (frente + tipo) del listado principal —
        // si el modal mostro N pares filtrados, el Excel descarga esos N
        // pares (no toda la flota). Mismo comportamiento del modulo de aux.
        const fValueElement = document.querySelector('input[name="id_frente"][data-filter-value]');
        const tValueElement = document.querySelector('input[name="id_tipo"][data-filter-value]');
        const fValue = (fValueElement && fValueElement.value && fValueElement.value !== 'all') ? fValueElement.value : '';
        const tValue = (tValueElement && tValueElement.value && tValueElement.value !== 'all') ? tValueElement.value : '';
        const _qsExp = new URLSearchParams();
        if (fValue) _qsExp.set('frente_id', fValue);
        if (tValue) _qsExp.set('id_tipo', tValue);
        const url = EQANC_CFG.rutaEquiposExportAnclajes + (_qsExp.toString() ? ('?' + _qsExp.toString()) : '');

        // Fetch + blob en lugar de <a href>.click(): evita el spinner nativo
        // de la pestaña del navegador. Mostramos el preloader global propio
        // de la app mientras se genera el XLSX en el servidor.
        if (typeof window.showPreloader === 'function') window.showPreloader();

        window.apiFetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const cd = r.headers.get('content-disposition') || '';
                const m  = cd.match(/filename="?([^";]+)"?/i);
                const fname = m ? m[1] : ('Anclajes_' + new Date().toISOString().slice(0,10) + '.xlsx');
                return r.blob().then(blob => ({ blob, fname }));
            })
            .then(({ blob, fname }) => {
                const blobUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = blobUrl;
                link.download = fname;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                setTimeout(() => {
                    document.body.removeChild(link);
                    URL.revokeObjectURL(blobUrl);
                }, 300);
                if (typeof window.showToast === 'function') {
                    window.showToast('Descarga lista: ' + fname, 'success');
                }
            })
            .catch(err => {
                console.error('[exportAnclajes]', err);
                if (typeof window.showToast === 'function') {
                    window.showToast('Error al descargar el Excel de anclajes.', 'error');
                } else {
                    alert('Error al descargar el Excel.');
                }
            })
            .finally(() => {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            });
    };

    // CAN_CREATE_EQUIPOS, CAN_ASSIGN_EQUIPOS, CAN_CHANGE_STATUS ya estan
    // definidos globalmente en layouts/estructura_base.blade.php — no se
    // redefinen aqui para evitar duplicidad.
    // CAN_CREATE_INFO es un alias historico requerido por equipos_index.js.
    window.CAN_CREATE_INFO = window.CAN_CREATE_EQUIPOS;
    window.CREATE_URL = EQANC_CFG.rutaEquiposCreate;

    // Estas funciones vivian sueltas en el <script> del HTML, asi que el navegador
    // las dejaba en window y los onclick= de la pagina las llaman por su nombre.
    // Aqui, dentro del arranque, serian privadas: se vuelven a publicar.
    window.openAnclajesListModal = openAnclajesListModal;
};
