/**
 * Equipos — render OFFLINE + acciones sin internet (Fase 2)
 * --------------------------------------------------------
 * Llena la tabla (#equiposTableBody) desde IndexedDB, reproduciendo partials/table_rows.
 * NO se activa solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión".
 * El botón de detalle abre el HISTORIAL DE MOVILIZACIONES del equipo (también local).
 * La foto no viaja offline (vive en Drive) → ícono placeholder.
 *
 * Fase 2 (escritura sin internet): el chip de estado es clickeable (encola un cambio
 * de estado, salvo INOPERATIVO que requiere reporte de falla) y la acción masiva
 * "Movilizar" abre un modal simple (frente EXISTENTE del snapshot). Ambas encolan en
 * el outbox (window.OfflineOutbox) y se suben al volver internet. Update optimista en
 * la copia local (kv.equipos) + repintado.
 *
 * Global + (re)init en DOMContentLoaded y 'spa:contentLoaded'; render() reconsulta el DOM.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;
    const esc = OM.esc;
    const COLS = 6;

    // Inyecta una sola vez la regla .eq-hide-mobile: la versión online la trae en el
    // <style> del partial, pero al repintar offline reemplazamos el tbody y ese style
    // desaparece. Sin esto, las tarjetas offline mostrarían CATEGORÍA/MODELO/AÑO que la
    // online oculta en móvil → diseño distinto.
    function ensureHideStyle() {
        if (document.getElementById('eqOfflineHideStyle')) return;
        const st = document.createElement('style');
        st.id = 'eqOfflineHideStyle';
        st.textContent = '@media(max-width:900px){.eq-hide-mobile{display:none!important;}}';
        document.head.appendChild(st);
    }

    const ESTADOS = {
        'OPERATIVO':        { color: '#16a34a', icon: 'check_circle', label: 'OPERATIVO' },
        'INOPERATIVO':      { color: '#dc2626', icon: 'cancel',       label: 'INOPERATIVO' },
        'EN MANTENIMIENTO': { color: '#d97706', icon: 'engineering',  label: 'MANTENIMIENTO' },
        'DESINCORPORADO':   { color: '#475569', icon: 'archive',      label: 'DESINCORP.' },
    };

    function getBody() { return document.getElementById('equiposTableBody'); }

    function valFiltro(sel) {
        var el = document.querySelector(sel);
        return el && el.value && el.value.trim() !== '' ? el.value.trim() : null;
    }

    function aplicarFiltro() {
        const tbody = getBody(); if (!tbody) return;
        const q = (valFiltro('#searchInput') || '').toLowerCase();
        const fFrente = valFiltro('input[name="id_frente"]');
        const fTipo   = valFiltro('input[name="id_tipo"]');
        const fEstado = valFiltro('input[name="estado"]');
        const fMarca  = valFiltro('input[name="marca"]');
        const fModelo = valFiltro('input[name="modelo"]');
        const fAnio   = valFiltro('input[name="anio"]');
        const fCat    = valFiltro('input[name="categoria"]');
        const fUbic   = valFiltro('input[name="detalle_ubicacion"]');
        const fConf   = valFiltro('input[name="confirmado"]');

        tbody.querySelectorAll('tr[data-offline]').forEach(function (tr) {
            var ok = true;
            if (q) { var blob = (tr.getAttribute('data-buscar') || ''); if (blob.indexOf(q) < 0) ok = false; }
            if (ok && fFrente && fFrente !== 'all') { var fid = tr.getAttribute('data-frente-id') || ''; if (fFrente === 'none' ? fid !== '' : fid !== fFrente) ok = false; }
            if (ok && fTipo && fTipo !== 'all' && tr.getAttribute('data-tipo-id') !== fTipo) ok = false;
            if (ok && fEstado && tr.getAttribute('data-estado') !== fEstado) ok = false;
            if (ok && fMarca && (tr.getAttribute('data-marca') || '').indexOf(fMarca.toLowerCase()) < 0) ok = false;
            if (ok && fModelo && (tr.getAttribute('data-modelo') || '').indexOf(fModelo.toLowerCase()) < 0) ok = false;
            if (ok && fAnio && tr.getAttribute('data-anio') !== fAnio) ok = false;
            if (ok && fCat && (tr.getAttribute('data-categoria') || '').indexOf(fCat.toLowerCase()) < 0) ok = false;
            if (ok && fUbic && (tr.getAttribute('data-ubicacion') || '').indexOf(fUbic.toLowerCase()) < 0) ok = false;
            if (ok && fConf) { var cv = tr.getAttribute('data-confirmado'); var fc = fConf === 'SI' ? '1' : fConf === 'NO' ? '0' : fConf; if (fc === '1' && cv !== '1') ok = false; if (fc === '0' && cv !== '0') ok = false; }
            tr.style.display = ok ? '' : 'none';
        });
    }

    function filaEquipo(e) {
        const est = ESTADOS[e.estado] || ESTADOS['DESINCORPORADO'];
        const buscar = [e.serial_chasis, e.serial_motor, e.placa, e.etiqueta, e.marca, e.modelo, e.tipo, e.codigo_patio]
            .filter(Boolean).join(' ').toLowerCase();

        // FINALIZADO: mismo badge que online (wrapper flex centrado + icono ⚠).
        const finalizado = e.frente_finalizado
            ? '<div style="display:flex;align-items:center;justify-content:center;gap:3px;margin-top:3px;">' +
                '<span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:2px;border:1px solid #fecaca;">' +
                    '<i class="material-icons" style="font-size:10px;">warning</i>FINALIZADO</span>' +
              '</div>' : '';
        const etiqueta = e.etiqueta
            ? '<span style="font-weight:700;color:var(--maquinaria-blue);margin-left:6px;white-space:nowrap;"><i class="material-icons" style="font-size:13px;vertical-align:-2px;">tag</i>' + esc(e.etiqueta) + '</span>' : '';
        // eq-hide-mobile: CATEGORIA/MODELO/AÑO se OCULTAN en móvil (≤900px), igual que la
        // tabla online (partials/table_rows.blade.php) — así la tarjeta offline luce idéntica.
        const categoria = e.categoria
            ? '<div class="eq-hide-mobile" style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;margin-top:5px;letter-spacing:0.3px;">' + esc(e.categoria) + '</div>' : '';
        const modelo = e.modelo ? '<div class="eq-hide-mobile" style="font-size:13.5px;color:#475569;font-weight:500;text-transform:uppercase;margin-top:4px;line-height:1.3;">' + esc(e.modelo) + '</div>' : '';
        const anio = e.anio ? '<div class="eq-hide-mobile" style="font-size:12.5px;color:#64748b;margin-top:5px;font-weight:500;">Año: ' + esc(e.anio) + '</div>' : '';
        const motor = e.serial_motor ? '<div style="line-height:1.5;margin-top:3px;word-break:break-all;"><strong style="color:#64748b;">M:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_motor) + '</span></div>' : '';
        const placa = e.placa
            ? '<div style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">P:</strong> <span style="color:var(--maquinaria-blue);font-weight:700;text-transform:uppercase;">' + esc(e.placa) + '</span></div>'
            : '<div style="line-height:1.4;margin-top:3px;"><strong style="color:#64748b;">P:</strong> <span style="color:#a0aec0;font-style:italic;">Sin Placa</span></div>';

        return '' +
            '<tr data-offline="1" data-buscar="' + esc(buscar) + '"' +
            ' data-frente-id="' + (e.id_frente || '') + '"' +
            ' data-tipo-id="' + (e.id_tipo || '') + '"' +
            ' data-estado="' + esc(e.estado || '') + '"' +
            ' data-marca="' + esc((e.marca || '').toLowerCase()) + '"' +
            ' data-modelo="' + esc((e.modelo || '').toLowerCase()) + '"' +
            ' data-anio="' + esc(e.anio || '') + '"' +
            ' data-categoria="' + esc((e.categoria || '').toLowerCase()) + '"' +
            ' data-ubicacion="' + esc((e.ubicacion || '').toLowerCase()) + '"' +
            ' data-confirmado="' + (e.confirmado ? '1' : '0') + '">' +
            '<td class="table-cell-custom table-cell-center" style="padding:6px 4px;width:150px;">' +
                '<div class="tooltip-wrapper" style="font-size:13px;color:#000;margin-bottom:5px;line-height:1.25;font-weight:700;text-align:center;text-transform:uppercase;word-wrap:break-word;position:relative;cursor:default;">' +
                    '<span style="display:inline-flex;align-items:center;gap:3px;justify-content:center;">' + esc(e.frente || 'SIN ASIGNAR') +
                        '<i class="material-icons" style="font-size:14px;color:' + (e.confirmado ? '#16a34a' : '#cbd5e0') + ';" title="' + (e.confirmado ? 'Confirmado en sitio' : 'Sin confirmar') + '">' + (e.confirmado ? 'check_circle' : 'radio_button_unchecked') + '</i>' +
                    '</span>' + finalizado +
                '</div>' +
                '<div class="table-image-wrapper placeholder"><span class="material-icons">image_not_supported</span></div>' +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14.5px;color:#000;word-wrap:break-word;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.tipo || '—') + etiqueta + '</div>' + categoria +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:13px;color:#000;word-wrap:break-word;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.marca || '—') + '</div>' + modelo + anio +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14px;color:#4a5568;">' +
                '<div style="line-height:1.5;word-break:break-all;"><strong style="color:#64748b;">S:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_chasis || '—') + '</span></div>' +
                motor + placa +
                '<div style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">ID:</strong> <span style="color:#1e293b;font-weight:600;">#' + esc(e.codigo_patio || '—') + '</span></div>' +
            '</td>' +
            // Estatus: mismo look que el trigger online (chip blanco + chevron + sombra).
            // Fase 2: clickeable sin conexión → menú con OPERATIVO/MANTENIMIENTO/DESINCORP.
            // (INOPERATIVO no: requiere reporte de falla). data-status/data-label los usa
            // el flujo de cambio; al elegir se encola y se repinta.
            '<td class="table-cell-custom" style="padding:8px 2px;width:145px;">' +
                '<div title="Cambiar estado (sin conexión)" data-status="' + esc(e.estado || '') + '" data-label="' + esc(e.tipo || ('#' + (e.codigo_patio || e.id))) + '"' +
                    ' onclick="window.eqOffEstadoMenu(event, this, ' + e.id + ')"' +
                    ' style="padding:6px 10px;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:5px;font-size:12.5px;font-weight:700;background:white;border:1px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.05);cursor:pointer;">' +
                    '<div style="display:flex;align-items:center;gap:5px;color:' + est.color + ';">' +
                        '<i class="material-icons" style="font-size:16px;">' + est.icon + '</i><span style="color:#334155;text-transform:uppercase;">' + est.label + '</span>' +
                    '</div>' +
                    '<i class="material-icons" style="font-size:16px;color:#94a3b8;">expand_more</i>' +
                '</div>' +
            '</td>' +
            // Acciones: MISMO botón "Ver Detalles" que online (showDetailsImproved abre
            // #detailsModal, incluido en index.blade y cacheado por la PWA). Los data-* se
            // llenan con lo que hay en el snapshot; los campos no descargados (seguros, docs,
            // GPS) el modal los muestra como "N/A"/"Sin Documento" — degrada sin romper, NO
            // hace llamadas de red al abrir.
            '<td class="table-cell-center" style="padding:8px 5px;width:72px;text-align:center;vertical-align:middle;">' +
                '<div style="display:flex;justify-content:center;align-items:center;gap:4px;">' +
                    '<button type="button" class="btn-details-mini" title="Ver Detalles"' +
                        ' data-equipo-id="' + e.id + '"' +
                        ' data-codigo="' + esc(e.codigo_patio || '') + '"' +
                        ' data-chasis="' + esc(e.serial_chasis || '') + '"' +
                        ' data-placa="' + esc(e.placa || 'N/A') + '"' +
                        ' data-tipo="' + esc(e.tipo || 'SIN TIPO') + '"' +
                        ' data-anio="' + esc(e.anio || '') + '"' +
                        ' data-categoria="' + esc(e.categoria || '') + '"' +
                        ' onclick="showDetailsImproved(this, event)">' +
                        '<i class="material-icons">visibility</i>' +
                    '</button>' +
                '</div>' +
            '</td>' +
            '</tr>';
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        ensureHideStyle();
        const equipos = await window.OfflineDB.get('equipos').catch(() => []);

        if (!equipos || !equipos.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.</td></tr>';
            return;
        }

        tbody.innerHTML = equipos.map(filaEquipo).join('');
        aplicarFiltro();
    }

    // ── Fase 2: CAMBIO DE ESTADO sin internet ────────────────────────────────
    // Lógica ÚNICA (la reusa también changeStatusLite en equipos_index.js cuando
    // está activo el modo offline). Encola + actualiza copia local + repinta.
    window.eqOffSetEstado = function (id, status, label) {
        if (!window.OfflineOutbox || !window.OfflineDB) return;
        window.OfflineOutbox.add({
            client_uuid: window.OfflineOutbox.uuid(),
            action: 'estado',
            payload: { id_equipo: Number(id), status: status },
            status: 'pending', created: Date.now(),
            label: 'Estado · ' + (label || ('Equipo #' + id)) + ' → ' + status,
        });
        window.OfflineDB.get('equipos').then(function (arr) {
            var e = arr.find(function (x) { return Number(x.id) === Number(id); });
            if (e) { e.estado = status; window.OfflineDB.put('equipos', arr).then(render); }
            else { render(); }
        });
        if (window.showToast) window.showToast('Cambio guardado. Se subirá al volver internet.', 'success');
    };

    // Menú flotante de estado sobre el chip (solo estados permitidos offline).
    window.eqOffEstadoMenu = function (ev, chip, id) {
        ev.stopPropagation();
        document.querySelectorAll('.eq-off-menu').forEach(function (m) { m.remove(); });
        var permitidos = ['OPERATIVO', 'EN MANTENIMIENTO', 'DESINCORPORADO'];
        var menu = document.createElement('div');
        menu.className = 'eq-off-menu';
        menu.style.cssText = 'position:absolute;z-index:10002;background:white;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,0.15);overflow:hidden;min-width:175px;';
        permitidos.forEach(function (s) {
            var it = ESTADOS[s];
            var row = document.createElement('div');
            row.style.cssText = 'padding:9px 12px;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12.5px;font-weight:700;color:#334155;text-transform:uppercase;';
            row.innerHTML = '<i class="material-icons" style="font-size:16px;color:' + it.color + ';">' + it.icon + '</i>' + it.label;
            row.onmouseover = function () { row.style.background = '#f1f5f9'; };
            row.onmouseout = function () { row.style.background = 'white'; };
            row.onmousedown = function (e) {
                e.preventDefault(); menu.remove();
                if ((chip.dataset.status || '') === s) return; // sin cambio
                window.eqOffSetEstado(id, s, chip.dataset.label);
            };
            menu.appendChild(row);
        });
        document.body.appendChild(menu);
        var r = chip.getBoundingClientRect();
        menu.style.top = (window.scrollY + r.bottom + 4) + 'px';
        menu.style.left = (window.scrollX + r.left) + 'px';
        var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
        setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
    };

    // ── Fase 2: MOVILIZAR sin internet (modal simple, frente EXISTENTE) ──────
    // Lo llama openBulkModal (equipos_index.js) cuando el modo offline está activo.
    // selectedList = valores de window.selectedEquipos: {id, tipo, frenteId, ...}.
    window.abrirModalMovilizarOffline = function (selectedList) {
        if (!selectedList || !selectedList.length) {
            if (window.showToast) window.showToast('Selecciona equipos primero.', 'error');
            return;
        }
        document.querySelectorAll('.eq-off-mov-modal').forEach(function (m) { m.remove(); });

        window.OfflineDB.get('frentes').then(function (frentes) {
            frentes = (frentes || []).slice().sort(function (a, b) { return (a.nombre || '').localeCompare(b.nombre || ''); });

            var sel = { id: null, nombre: null };
            var overlay = document.createElement('div');
            overlay.className = 'eq-off-mov-modal';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10001;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(3px);';
            var box = document.createElement('div');
            box.style.cssText = 'background:white;border-radius:16px;width:90%;max-width:440px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);';
            box.innerHTML =
                '<div style="background:#1e293b;padding:16px;color:white;display:flex;align-items:center;justify-content:center;gap:8px;position:relative;">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:20px;">cloud_off</i>' +
                    '<h2 style="margin:0;font-size:15px;font-weight:700;">Movilizar sin conexión</h2>' +
                    '<button type="button" id="eqOffMovClose" style="position:absolute;right:12px;background:transparent;border:none;color:white;cursor:pointer;opacity:.8;"><i class="material-icons">close</i></button>' +
                '</div>' +
                '<div style="padding:18px 20px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;">' +
                    '<div style="font-size:13px;color:#475569;"><strong>' + selectedList.length + '</strong> equipo(s) a un frente <strong>existente</strong>. El acta estará disponible al sincronizar.</div>' +
                    '<div>' +
                        '<label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Frente de destino *</label>' +
                        '<input type="text" id="eqOffMovSearch" placeholder="Buscar frente..." autocomplete="off" style="width:100%;box-sizing:border-box;border:2px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none;">' +
                        '<div id="eqOffMovList" style="margin-top:6px;max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;"></div>' +
                    '</div>' +
                    '<div id="eqOffMovSel" style="font-size:12.5px;color:#0067b1;font-weight:700;min-height:16px;"></div>' +
                '</div>' +
                '<div style="padding:14px 20px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #f1f5f9;">' +
                    '<button type="button" id="eqOffMovCancel" class="btn-primary-maquinaria btn-secondary">Cancelar</button>' +
                    '<button type="button" id="eqOffMovOk" class="btn-primary-maquinaria" disabled style="opacity:.5;">Movilizar</button>' +
                '</div>';
            overlay.appendChild(box);
            document.body.appendChild(overlay);

            var listEl = box.querySelector('#eqOffMovList');
            var selEl  = box.querySelector('#eqOffMovSel');
            var okBtn  = box.querySelector('#eqOffMovOk');

            function pintarLista(q) {
                q = (q || '').toLowerCase().trim();
                var arr = frentes.filter(function (f) { return !q || (f.nombre || '').toLowerCase().indexOf(q) >= 0; });
                if (!arr.length) { listEl.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:12px;">Sin frentes en la copia local.</div>'; return; }
                listEl.innerHTML = arr.map(function (f) {
                    return '<div class="eq-off-mov-opt" data-id="' + f.id + '" data-nombre="' + esc(f.nombre || '') + '" style="padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;">' + esc(f.nombre || '') + '</div>';
                }).join('');
            }
            pintarLista('');
            box.querySelector('#eqOffMovSearch').addEventListener('input', function () { pintarLista(this.value); });
            listEl.addEventListener('click', function (ev) {
                var opt = ev.target.closest('.eq-off-mov-opt'); if (!opt) return;
                sel.id = Number(opt.getAttribute('data-id'));
                sel.nombre = opt.getAttribute('data-nombre');
                selEl.textContent = 'Destino: ' + sel.nombre;
                okBtn.disabled = false; okBtn.style.opacity = '1';
            });

            var cerrar = function () { overlay.remove(); };
            box.querySelector('#eqOffMovClose').onclick = cerrar;
            box.querySelector('#eqOffMovCancel').onclick = cerrar;
            overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });

            okBtn.onclick = function () {
                if (!sel.id) return;
                var ids = selectedList.map(function (s) { return Number(s.id); });

                window.OfflineOutbox.add({
                    client_uuid: window.OfflineOutbox.uuid(),
                    action: 'movilizar',
                    payload: { ids: ids, id_frente_destino: sel.id },
                    status: 'pending', created: Date.now(),
                    label: 'Movilizar ' + ids.length + ' equipo(s) → ' + sel.nombre,
                });
                // Optimista en la copia local + limpiar selección + repintar.
                window.OfflineDB.get('equipos').then(function (arr) {
                    arr.forEach(function (e) { if (ids.indexOf(Number(e.id)) >= 0) { e.id_frente = sel.id; e.frente = sel.nombre; e.confirmado = 0; } });
                    window.OfflineDB.put('equipos', arr).then(function () {
                        window.selectedEquipos = {};
                        var bar = document.getElementById('bulkFloatingBar'); if (bar) bar.classList.remove('active');
                        render();
                    });
                });
                if (window.showToast) window.showToast('Movilización guardada. El acta estará disponible al sincronizar.', 'success');
                cerrar();
            };
        });
    };

    function init() {
        if (!getBody()) return;
        OM.registrar('equipos', function () { OM.conOfflineDB(render); });

        var inp = document.getElementById('searchInput');
        if (inp && !inp.dataset.offWiredEq) {
            inp.dataset.offWiredEq = '1';
            inp.addEventListener('input', function () { if (OM.estaActivo()) aplicarFiltro(); });
        }

        // Patch loadEquipos: intercepta la llamada AJAX y filtra local si offline.
        if (typeof window.loadEquipos === 'function') {
            if (!window._origLoadEquipos) {
                window._origLoadEquipos = window.loadEquipos;
            } else if (window.loadEquipos !== window._eqOffPatchedLoad) {
                window._origLoadEquipos = window.loadEquipos;
            }
            window._eqOffPatchedLoad = function () {
                if (OM.estaActivo()) { aplicarFiltro(); return Promise.resolve(); }
                // Sin conexión pero SIN activar el modo offline: bloqueamos la búsqueda (no
                // pegarle al servidor caído) y avisamos que pulse "Trabajar sin conexión".
                if (OM.pendienteActivar && OM.pendienteActivar()) { OM.avisarActivar(); return Promise.resolve(); }
                return window._origLoadEquipos.apply(null, arguments);
            };
            window.loadEquipos = window._eqOffPatchedLoad;
        }

        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    // Mecanismo DIRECTO: intercepta clics en dropdown-items y el evento custom.
    // Un solo listener global en document (delegación), no depende del patch de
    // loadEquipos ni de que selectOption despache nada. Si el modo offline está
    // activo y estamos en la página de equipos, filtrar tras un microtick (para
    // que selectOption haya puesto el valor en el hidden input).
    if (!window._eqOffClickWired) {
        window._eqOffClickWired = true;

        document.addEventListener('click', function (e) {
            if (!OM.estaActivo() || !getBody()) return;
            if (e.target.closest && e.target.closest('.dropdown-item')) {
                setTimeout(aplicarFiltro, 0);
            }
        }, true);

        window.addEventListener('dropdown-selection', function () {
            if (OM.estaActivo() && getBody()) aplicarFiltro();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
