/* fallas_index.js — Módulo Control de Fallas (/admin/fallas)
 * Filtros, búsqueda, paginación, Consolidado y tarjetas.
 *
 * Todo sale de la COPIA LOCAL (window.CopiaLocal, dominio 'fallas'; App\Support\Sincronizacion\
 * DominioFallas): la misma lógica con y sin internet, y sin esperar al servidor en cada filtro.
 * Crear, cerrar, borrar y ver el acta sí necesitan conexión. Lo que se guarda llega a la copia
 * sola (fetch_interceptor → CopiaLocal.alGuardar) y la pantalla se repinta con el evento.
 * Las rutas se leen de window.FALLAS_CFG, definido en el Blade del módulo.
 */
(function () {
    if (window._fallasReady) return;
    window._fallasReady = true;

    const cfg  = () => window.FALLAS_CFG || {};
    const POR_PAGINA = 50;
    let _vista = null;
    let _pagina = 1;

    // Aviso moderno (modal de la app) cuando el usuario no tiene permiso para
    // registrar fallas — en vez del alert() del navegador. Mismo patrón que equipos.
    window.flAccesoDenegado = function () {
        const msg = 'No tienes los permisos requeridos para registrar fallas.';
        if (window.showModal) {
            window.showModal({ type: 'error', title: 'Acceso Denegado', message: msg, confirmText: 'Entendido', hideCancel: true });
        } else if (window.showToast) {
            window.showToast(msg, 'error');
        } else {
            alert(msg);
        }
    };

    // Sin conexión no se guarda nada aquí (no pasa por la bandeja): se avisa en vez de
    // intentarlo y fallar con "Error de red".
    function sinConexion(accion) {
        if (navigator.onLine !== false && !(_vista && !_vista.estado.conectado)) return false;
        window.toast('Para ' + accion + ' hace falta conexión a internet.', 'error');
        return true;
    }

    // ─── Filtros (leídos de sus controles) ───
    function filtros() {
        const v = (id) => (document.getElementById(id)?.value || '').trim();
        return {
            search: v('fallasSearch'), estatus: v('fallasEstatus'), tipoActivo: v('fallasTipoActivo'),
            frente: v('fallasFrente'), responsable: v('fallasResponsable'), marca: v('fallasMarca'),
            modelo: v('fallasModelo'), desde: v('fallasFechaDesde'), hasta: v('fallasFechaHasta'),
        };
    }

    const contiene = (valor, texto) => valor != null && String(valor).toUpperCase().indexOf(texto) !== -1;

    // Las reglas de siempre del módulo: el texto busca el serial (chasis o motor), la placa o
    // el código del equipo, el serial o el código del auxiliar, o el código del reporte.
    function coincideBusqueda(f, search) {
        const S = search.replace(/^#+/, '').toUpperCase();
        const a = f.activo;
        if (contiene(f.codigo, S)) return true;
        if (!a) return false;
        return f.activoTipo === 'equipo_auxiliar'
            ? contiene(a.serial, S) || contiene(a.codigo, S)
            : contiene(a.serial, S) || contiene(a.serialMotor, S) || contiene(a.codigo, S) || contiene(a.placa, S);
    }

    // conEstatus = false para el Consolidado: cuenta abiertos y cerrados de lo filtrado.
    function filtrar(filas, p, conEstatus) {
        const ta = p.tipoActivo;
        return filas.filter(function (f) {
            const a = f.activo;
            if (conEstatus && p.estatus && f.estado !== p.estatus) return false;
            if (p.responsable && f.idReporta !== Number(p.responsable)) return false;
            const dia = (f.fecha || '').slice(0, 10);
            if (p.desde && dia < p.desde) return false;
            if (p.hasta && dia > p.hasta) return false;
            if (ta === 'equipo' || ta === 'equipo_auxiliar') {
                if (f.activoTipo !== ta) return false;
            } else if (ta.indexOf('tipo_eq:') === 0) {
                if (f.activoTipo !== 'equipo' || !a || a.idTipo !== Number(ta.slice(8))) return false;
            } else if (ta.indexOf('tipo_aux:') === 0) {
                if (f.activoTipo !== 'equipo_auxiliar' || !a || a.tipoAux !== ta.slice(9)) return false;
            }
            if (p.frente && (!a || a.idFrente !== Number(p.frente))) return false;
            if (p.marca && (!a || !contiene(a.marca, p.marca.toUpperCase()))) return false;
            if (p.modelo && (!a || !contiene(a.modelo, p.modelo.toUpperCase()))) return false;
            if (p.search && !coincideBusqueda(f, p.search)) return false;
            return true;
        });
    }

    function consolidado(filas) {
        const s = { total_reportes: filas.length, reportes_abiertos: 0, reportes_cerrados: 0, preventivo: 0, correctivo: 0 };
        filas.forEach(function (f) {
            if (f.estado === 'abierto') s.reportes_abiertos++;
            else if (f.estado === 'cerrado') s.reportes_cerrados++;
            if (f.mant === 'PREVENTIVO') s.preventivo++;
            else if (f.mant === 'CORRECTIVO') s.correctivo++;
        });
        // "Rápidos" = el resto (reportes cortos / sin tipo de mantenimiento).
        s.rapidos = Math.max(0, s.total_reportes - s.preventivo - s.correctivo);
        return s;
    }

    // ─── Tarjetas ───
    const MANTENIMIENTO = { PREVENTIVO: 'Preventivo', CORRECTIVO: 'Correctivo' };

    function fechaCorta(fecha) {
        const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(fecha || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
    }

    function tarjeta(f, puedeCerrar, superAdmin) {
        const h = window.escapeHtml;
        const a = f.activo || {};
        const esAux = f.activoTipo === 'equipo_auxiliar';
        const icono = esAux ? 'construction' : 'agriculture';
        const extenso = f.tipoReporte === 'extenso';
        const abierto = f.estado === 'abierto';

        const foto = a.fotoId
            ? '<img src="/storage/google/' + encodeURIComponent(a.fotoId) + '?sz=w120" alt=""'
              + ' onerror="this.outerHTML=\'<i class=\\\'material-icons\\\'>' + icono + '</i>\';">'
            : '<i class="material-icons">' + icono + '</i>';

        const linea1 = '<span style="font-weight: 700; color: ' + (abierto ? '#ef4444' : '#10b981') + '; text-transform: capitalize;">Reporte ' + h(f.estado) + '</span>'
            + (extenso ? ' · ' + h(f.codigo) : '')
            + (f.mant ? ' · ' + h(MANTENIMIENTO[f.mant] || f.mant) : '')
            + ' · ' + fechaCorta(f.fecha);

        const linea2 = (a.tipo ? '<span class="falla-eq-tipo" style="font-size:12px; font-weight:700; color:#000; text-transform:uppercase; letter-spacing:0.4px; margin-right:5px;">' + h(String(a.tipo).toUpperCase()) + '</span>·' : '')
            + ' ' + h(a.marca || '(sin marca)')
            + (a.codigo ? '<span style="color:#64748b; font-weight:400; font-size:12px;"> · ' + h(a.codigo) + '</span>' : '');

        const linea3 = (a.serial ? '<span><strong style="color:#64748b;">S:</strong> <strong style="color:#1e293b; text-transform:uppercase;">' + h(a.serial) + '</strong></span>' : '')
            + (a.placa ? '<span><strong style="color:#64748b;">P:</strong> <strong style="color:var(--maquinaria-blue, #0067b1); text-transform:uppercase;">' + h(a.placa) + '</strong></span>' : '')
            + (f.mecanico ? '<span style="display:inline-flex; align-items:center; gap:3px;"><i class="material-icons" style="font-size:12px;">build</i>' + h(f.mecanico) + '</span>' : '');

        const reporto = f.reporta
            ? '<div class="falla-tap-hint"><i class="material-icons" style="font-size:14px;">expand_more</i> Ver quién reportó</div>'
              + '<div class="falla-reporto-mobile"><i class="material-icons" style="font-size:12px;">person</i>'
              + ' Reportó: <span style="color:#1e293b; font-weight:700; margin-left:3px;">' + h(f.reporta) + '</span></div>'
            : '';

        const botones = (extenso ? '<button type="button" class="falla-btn falla-btn-icon" title="Ver PDF" onclick="window.flVerActa(' + f.id + ')"><i class="material-icons">picture_as_pdf</i></button>' : '')
            + (abierto && puedeCerrar ? '<button type="button" class="falla-btn falla-btn-icon" title="Cerrar reporte" onclick="window.cerrarFalla(' + f.id + ')"><i class="material-icons" style="color:#10b981;">check_circle</i></button>' : '')
            + (superAdmin ? '<button type="button" class="falla-btn falla-btn-icon falla-btn-danger" title="Eliminar reporte (definitivo)" onclick="window.eliminarFalla(' + f.id + ')"><i class="material-icons">delete_forever</i></button>' : '');

        // Tap en la tarjeta (móvil): expande el detalle truncado. No dispara si el tap
        // fue en un botón/enlace (PDF/Cerrar).
        return '<div class="falla-row-card" onclick="if(!event.target.closest(\'button,a\')) this.classList.toggle(\'falla-expanded\');">'
            + '<div class="falla-foto-col">'
            +   (a.frente ? '<div class="falla-foto-frente" title="' + h(a.frente) + '">' + h(a.frente) + '</div>' : '')
            +   '<div class="falla-foto">' + foto + '</div>'
            + '</div>'
            + '<div class="falla-meta">'
            +   '<div class="falla-codigo">' + linea1 + '</div>'
            +   '<div class="falla-equipo">' + linea2 + '</div>'
            +   '<div class="falla-info" style="display:flex; flex-wrap:wrap; align-items:center; gap:3px 12px;">' + linea3 + '</div>'
            +   reporto
            + '</div>'
            + '<div class="falla-descripcion">'
            +   (f.descripcion ? '"' + h(f.descripcion) + '"' : '<span style="color:#cbd5e1; font-style:normal;">Sin descripción</span>')
            + '</div>'
            + '<div class="falla-actions" style="flex-direction: column; align-items: flex-end; gap:6px;">'
            +   '<div class="falla-reporto-desktop" style="font-size:11.5px; color:#64748b; text-align:right; line-height:1.2;">'
            +     'Reportó: <span style="color:#1e293b; font-weight:700;">' + h(f.reporta || '—') + '</span></div>'
            +   '<div class="falla-btn-stack" style="display:flex; gap:4px; align-items:center;">' + botones + '</div>'
            + '</div>'
            + '</div>';
    }

    function mensaje(texto) {
        return '<div style="background:white; border:1px dashed #cbd5e1; border-radius:12px; padding:40px 20px; text-align:center; color:#94a3b8;">'
            + '<i class="material-icons" style="font-size:42px; display:block; margin:0 auto 8px; color:#cbd5e1;">inbox</i>'
            + '<span style="font-size:13px;">' + texto + '</span></div>';
    }

    function pintarConsolidado(s) {
        // Total / Abiertos / Cerrados, en la tarjeta de la PC y en la del teléfono.
        document.querySelectorAll('[data-fallas-stat]').forEach(function (el) {
            const v = s ? s[el.dataset.fallasStat] : undefined;
            el.textContent = v !== undefined ? v : '—';
        });
        if (s) window.flRenderMantChart(s.preventivo, s.correctivo, s.rapidos);
    }

    // ─── Pintar ───
    window.cargarFallas = function (pagina) {
        const cuerpo = document.getElementById('fallasTableBody');
        if (!cuerpo) return;
        const pag = document.getElementById('fallasPagination');
        const p = filtros();

        // Indicador visual del botón avanzado
        const hasAdv = p.estatus || p.tipoActivo || p.responsable || p.marca || p.modelo || p.desde || p.hasta;
        const advBtn = document.getElementById('fallasAdvBtn');
        if (advBtn) {
            advBtn.style.background = hasAdv ? '#fee2e2' : 'white';
            advBtn.style.border     = '1px solid ' + (hasAdv ? '#ef4444' : '#cbd5e0');
            advBtn.style.color      = hasAdv ? '#ef4444' : '#64748b';
        }

        if (!_vista || !_vista.estado.listo) {
            cuerpo.innerHTML = mensaje(_vista && !_vista.estado.conectado
                ? 'Sin conexión: este equipo todavía no tiene los reportes guardados.'
                : 'Cargando reportes…');
            if (pag) pag.innerHTML = '';
            pintarConsolidado(null);
            if (_vista) window.pintarEstadoCopia('fallasCopiaEstado', _vista.estado);
            return;
        }

        const todas = _vista.filas('fallas');
        const filas = filtrar(todas, p, true).sort(function (x, y) {
            return x.fecha === y.fecha ? y.id - x.id : (x.fecha < y.fecha ? 1 : -1);
        });
        const ultima = Math.max(1, Math.ceil(filas.length / POR_PAGINA));
        _pagina = Math.min(Math.max(1, Number(pagina) || 1), ultima);
        const desde = (_pagina - 1) * POR_PAGINA;
        const puedeCerrar = cuerpo.dataset.puedeCerrar === '1';
        const superAdmin = cuerpo.dataset.superAdmin === '1';

        cuerpo.innerHTML = filas.length
            ? filas.slice(desde, desde + POR_PAGINA).map(function (f) { return tarjeta(f, puedeCerrar, superAdmin); }).join('')
            : mensaje('No hay reportes que coincidan con los filtros.');
        if (pag) pag.innerHTML = window.paginadorHtml(_pagina, ultima);
        pintarConsolidado(consolidado(filtrar(todas, p, false)));
        window.pintarEstadoCopia('fallasCopiaEstado', _vista.estado);
    };

    // ─── Dona de tipo de mantenimiento (Consolidado) ───
    // Recalcula conic-gradient + leyenda para TODAS las instancias (.fmc-*),
    // de modo que sirve igual para el panel móvil y el de escritorio.
    // Colores: deben coincidir con partials/mant_chart.blade.php.
    window.flRenderMantChart = function (prev, corr, rap) {
        prev = +prev || 0; corr = +corr || 0; rap = +rap || 0;
        const tot = prev + corr + rap;
        const base = Math.max(1, tot);
        const pPct = (prev / base * 100).toFixed(2);
        const cPct = ((prev + corr) / base * 100).toFixed(2);
        const grad = tot > 0
            ? `conic-gradient(#34d399 0 ${pPct}%, #fbbf24 ${pPct}% ${cPct}%, #60a5fa ${cPct}% 100%)`
            : 'conic-gradient(rgba(255,255,255,0.15) 0 100%)';
        document.querySelectorAll('.fmc-donut').forEach(d => { d.style.background = grad; });
        document.querySelectorAll('.fmc-total').forEach(e => { e.textContent = tot; });
        document.querySelectorAll('.fmc-prev').forEach(e => { e.textContent = prev; });
        document.querySelectorAll('.fmc-corr').forEach(e => { e.textContent = corr; });
        document.querySelectorAll('.fmc-rap').forEach(e => { e.textContent = rap; });
    };

    // ─── Limpiar filtros avanzados ───
    // Los dropdowns son custom-dropdown (estilo /admin/equipos): para resetearlos
    // se debe usar window.clearDropdownFilter para que tambien se limpie el
    // placeholder, el clear-btn y el highlight visual del trigger.
    window.flClearAdv = function () {
        const advDDs = ['fallasEstatusDD','fallasTipoActivoDD','fallasResponsableDD','fallasMarcaDD','fallasModeloDD'];
        advDDs.forEach(ddId => {
            if (document.getElementById(ddId) && typeof window.clearDropdownFilter === 'function') {
                window.clearDropdownFilter(ddId);
            }
        });
        // Inputs nativos (date)
        ['fallasFechaDesde','fallasFechaHasta'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        const panel = document.getElementById('fallasAdvPanel');
        if (panel) panel.style.display = 'none';
        window.cargarFallas();
    };

    // Paginación: cada enlace lleva su página en data-page (paginadorHtml). En fase de
    // CAPTURA: este archivo se carga con su vista, y al entrar por la SPA se registra DESPUÉS
    // del clic de navegacion.js (también en document); en burbuja su stopImmediatePropagation
    // llegaba tarde y el SPA ganaba.
    document.addEventListener('click', function (e) {
        const link = e.target.closest('#fallasPagination a.page-link');
        if (link) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.cargarFallas(link.dataset.page);
        }
    }, true);

    // La copia se puso al día (o cambió la conexión): se repinta la página en la que se está.
    window.addEventListener('copia-local', function (e) {
        if (e.detail.dominio !== 'fallas' || !document.getElementById('fallasTableBody')) return;
        if (e.detail.cambio) window.cargarFallas(_pagina);
        else window.pintarEstadoCopia('fallasCopiaEstado', e.detail.estado);
    });

    // Cerrar panel avanzado al hacer clic fuera
    document.addEventListener('click', function (e) {
        const panel = document.getElementById('fallasAdvPanel');
        const btn   = document.getElementById('fallasAdvBtn');
        if (panel && panel.style.display !== 'none') {
            if (!panel.contains(e.target) && btn && !btn.contains(e.target)) {
                panel.style.display = 'none';
            }
        }
    });

    // Mostrar/ocultar el 'x' del buscador
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'fallasSearch') {
            const clr = document.getElementById('fallasSearchClear');
            if (clr) clr.style.display = e.target.value ? 'block' : 'none';
        }
    });

    const fila = (id) => (_vista ? _vista.filas('fallas').find(f => f.id === Number(id)) : null);

    // ─── Modal "Nuevo Reporte de Falla" ───
    // Su lógica (open/close/setTipo/buscador/select/submit) vive en el módulo
    // COMPARTIDO public/js/maquinaria/falla_create_modal.js (lo usan también
    // /admin/equipos, con equipos y auxiliares). Aquí solo se configura vía
    // window.FALLA_MODAL_CFG (en el Blade).
    window.flNuevoReporte = function () {
        if (sinConexion('registrar un reporte')) return;
        window.openNuevoReporteModal();
    };

    // Acta PDF (solo reportes extensos): la arma el servidor.
    window.flVerActa = function (id) {
        const f = fila(id);
        if (!f || sinConexion('ver el acta')) return;
        window.openPdfPreview(cfg().urlBase + '/' + f.id + '/pdf', 'falla', 'Reporte ' + (f.codigo || ''), 0, '', true, 'falla');
    };

    // ─── Cerrar reporte (modal con observaciones; el activo siempre vuelve a OPERATIVO) ───
    let _cierreId = null;

    // El modal viene PRE-LLENADO con lo ya cargado en la sección Taller del reporte.
    window.cerrarFalla = function (id) {
        const f = fila(id);
        if (!f || sinConexion('cerrar el reporte')) return;
        _cierreId = f.id;
        const a = f.activo || {};

        // Qué equipo, en el encabezado: el mismo que el modal compartido de equipos y
        // auxiliares (falla_create_modal.js).
        window.flEncabezadoCierre(document.getElementById('cierreEquipo'), {
            id: f.id, tipo: f.tipoReporte, codigo: f.codigo || '', equipo: a.equipo || '', detalle: a.detalle || '',
        });

        document.getElementById('cierreObservaciones').value = '';

        // Sección Taller: solo en actas (extenso). Se PRE-LLENA con lo ya cargado
        // al crear el reporte — el cierre es para revisar/confirmar y completar lo
        // que falte (el reporte se pudo crear sin estos datos).
        const taller = document.getElementById('cierreTallerFields');
        if (taller) taller.style.display = f.tipoReporte === 'extenso' ? 'flex' : 'none';
        const setVal = (fid, val) => { const el = document.getElementById(fid); if (el) el.value = val || ''; };
        setVal('cierreMecanico',       f.mecanico);
        setVal('cierreFechaRecepcion', f.fechaRecepcion);
        setVal('cierreDiagnostico',    f.diagnostico);
        setVal('cierreAcciones',       f.acciones);

        document.getElementById('cierreReporteOverlay').classList.add('active');
    };

    window.closeCierreModal = function () {
        document.getElementById('cierreReporteOverlay').classList.remove('active');
        _cierreId = null;
    };

    // ─── Borrado DURO de un reporte (solo super.admin) ───
    // Irreversible y sin rastro; pide confirmación con la referencia del reporte.
    window.eliminarFalla = function (id) {
        const f = fila(id);
        if (!f || sinConexion('eliminar el reporte')) return;
        const ref = (f.tipoReporte === 'extenso' && f.codigo) ? f.codigo : ((f.activo && f.activo.equipo) || ('#' + f.id));
        if (!confirm('Eliminar definitivamente el reporte ' + ref + '?\n\nEsta acción es irreversible y no deja rastro.')) return;

        const fd = new FormData();
        fd.append('_method', 'DELETE');
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(cfg().urlBase + '/' + f.id, { headers: { 'Accept': 'application/json' },
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(body => {
            window.toast(body.message || (body.success ? 'Reporte eliminado.' : 'Error al eliminar'), body.success ? 'success' : 'error');
        })
        .catch(e => { console.error(e); window.toast('Error de red', 'error'); })
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    window.submitCierreReporte = function () {
        if (!_cierreId || sinConexion('cerrar el reporte')) return;
        const btn = document.getElementById('btnConfirmarCierre');
        const fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('observaciones_cierre', document.getElementById('cierreObservaciones').value);
        // Campos del taller (acta extensa): solo se envian si el bloque esta visible.
        const taller = document.getElementById('cierreTallerFields');
        if (taller && taller.style.display !== 'none') {
            fd.append('mecanico_asignado',   document.getElementById('cierreMecanico').value);
            fd.append('fecha_recepcion',     document.getElementById('cierreFechaRecepcion').value);
            fd.append('diagnostico',         document.getElementById('cierreDiagnostico').value);
            fd.append('acciones_realizadas', document.getElementById('cierreAcciones').value);
        }
        btn.disabled = true;
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(cfg().urlBase + '/' + _cierreId + '/close', { headers: { 'Accept': 'application/json' },
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(body => {
            if (body.success) {
                window.toast(body.message, 'success');
                window.closeCierreModal();
            } else {
                window.toast(body.message || 'Error al cerrar', 'error');
            }
        })
        .catch(e => { console.error(e); window.toast('Error de red', 'error'); })
        .finally(() => { btn.disabled = false; if (window.hidePreloader) window.hidePreloader(); });
    };

    // ─── Inicialización (carga directa o vía SPA) ───
    // Los scripts de la vista corren antes que copia-local.js (va al final del layout): la
    // copia se abre en DOMContentLoaded en la carga directa, nunca al evaluar el archivo.
    function init() {
        const cuerpo = document.getElementById('fallasTableBody');
        if (!cuerpo) return;
        window.cargarFallas();   // "Cargando…" hasta que abra la copia
        window.CopiaLocal.usar('fallas', cuerpo)
            // Recién guardado algo (y vuelto aquí), se espera a que la copia lo traiga.
            .then(function (v) { return window.CopiaLocal.alDia('fallas').then(function () { return v; }); })
            .then(function (v) {
                _vista = v;
                if (document.getElementById('fallasTableBody') === cuerpo) window.cargarFallas();
            });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
