/**
 * Equipos — render OFFLINE + acciones sin internet (Fase 2)
 * --------------------------------------------------------
 * Llena la tabla (#equiposTableBody) desde IndexedDB, reproduciendo partials/table_rows.
 * NO se activa solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión".
 * El botón de detalle abre el HISTORIAL DE MOVILIZACIONES del equipo (también local).
 * La foto no viaja offline (vive en Drive) → ícono placeholder.
 *
 * MISMAS REGLAS QUE ONLINE (EquipoController::index) — la vista sin internet no puede
 * mostrar un listado distinto al de la vista con internet:
 *   · SIN filtros no se pinta NINGUNA fila ("SELECCIONE UN FILTRO..."), igual que el
 *     $hasFilter del backend. Antes se volcaban los ~1.200 equipos de golpe: además de
 *     no parecerse a la web, construía megas de HTML y dejaba el teléfono trabado.
 *   · Se pinta de 150 en 150 con scroll infinito (PAGE_SIZE del backend).
 *   · El filtrado ocurre sobre los DATOS en memoria (no ocultando <tr>), con la misma
 *     semántica del backend: marca/modelo/año/categoría/ubicación son IGUALDAD exacta
 *     (no "contiene"), la búsqueda mira serial/motor/código/etiqueta/placa (con las
 *     variantes O↔0) y '#123' busca por nº de etiqueta.
 *   · Los ejes que NO viajan en el snapshot (GPS, color, documentos) no se pueden
 *     aplicar: en vez de devolver un listado más grande en silencio, se avisa.
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
        st.textContent = '@media(max-width:900px){.eq-hide-mobile{display:none!important;}' +
            '.table-equipos-mobile tbody td:nth-child(3) .eq-modelo{display:inline!important;font-size:11.5px!important;color:#000!important;font-weight:700!important;margin:0 0 0 5px!important;}}';
        document.head.appendChild(st);
    }

    const ESTADOS = {
        'OPERATIVO':        { color: '#16a34a', icon: 'check_circle', label: 'OPERATIVO' },
        'INOPERATIVO':      { color: '#dc2626', icon: 'cancel',       label: 'INOPERATIVO' },
        'EN MANTENIMIENTO': { color: '#d97706', icon: 'engineering',  label: 'MANTENIMIENTO' },
        'DESINCORPORADO':   { color: '#475569', icon: 'archive',      label: 'DESINCORP.' },
    };

    function getBody() { return document.getElementById('equiposTableBody'); }

    // Filas por lote: el MISMO $PAGE_SIZE del backend (EquipoController::index), para que
    // el scroll infinito sin internet se sienta igual que con internet.
    const PAGE_SIZE = 150;

    // Copia EN MEMORIA de kv.equipos + IDs de frentes ESPECIAL. Filtrar 1.200 objetos son
    // décimas de milisegundo, así que cada tecla puede re-filtrar sin tocar IndexedDB.
    // Se refrescan en cada render() (el que corre al activar el modo offline y cada vez
    // que entra una copia nueva), nunca quedan viejos.
    let datos      = null;
    let especiales = new Set();
    let avisoEjes  = '';     // último aviso de ejes no soportados (evita repetirlo)

    const up = (s) => String(s == null ? '' : s).toUpperCase();

    // ── Lectura de filtros: los MISMOS inputs y el MISMO alcance que loadEquipos ──
    // Los avanzados se leen dentro de #advancedFilterPanel igual que online; leerlos de
    // todo el documento podría enganchar otro input homónimo de un modal.
    function leerFiltros() {
        const panel = document.getElementById('advancedFilterPanel') || document;
        const v = function (sel, raiz) {
            const el = (raiz || document).querySelector(sel);
            return el && el.value && el.value.trim() !== '' ? el.value.trim() : '';
        };
        const chk = function (id) { const e = document.getElementById(id); return !!(e && e.checked); };
        return {
            q:          v('#searchInput'),
            frente:     v('input[name="id_frente"]'),
            tipo:       v('input[name="id_tipo"]'),
            modelo:     v('input[name="modelo"]', panel),
            marca:      v('input[name="marca"]', panel),
            ubicacion:  v('input[name="detalle_ubicacion"]', panel),
            anio:       v('input[name="anio"]', panel),
            categoria:  v('input[name="categoria"]', panel),
            estado:     v('input[name="estado"]', panel),
            gps:        v('input[name="gps"]', panel),
            color:      v('input[name="color"]', panel),
            confirmado: v('input[name="confirmado"]', panel),
            docs:       ['chk_propiedad', 'chk_poliza', 'chk_rotc', 'chk_racda', 'chk_adicional', 'chk_adicional_2'].filter(chk),
        };
    }

    // Réplica del $hasFilter del backend: sin ningún eje activo la tabla NO lista nada.
    // Ojo: 'all' (TODOS LOS FRENTES / TODOS LOS TIPOS) SÍ cuenta como filtro, igual que
    // el filled() de Laravel — es la forma de pedir el listado completo a propósito.
    function hayFiltro(f) {
        return !!(f.q || f.frente || f.tipo || f.modelo || f.marca || f.ubicacion || f.anio ||
                  f.categoria || f.estado || f.gps || f.color || f.confirmado || f.docs.length);
    }

    // Réplica de tieneFiltroEspecifico(): decide si los frentes ESPECIAL se ocultan.
    function filtroEspecifico(f) {
        if (f.q || f.modelo || f.marca || f.ubicacion || f.anio || f.categoria || f.estado || f.color) return true;
        if (['SI', 'NO'].indexOf(up(f.gps)) >= 0) return true;
        if (['SI', 'NO'].indexOf(up(f.confirmado)) >= 0) return true;
        return f.docs.length > 0;
    }

    // Réplica de esModoAux(): la tabla pasa a listar AUXILIARES, que no viajan offline.
    function esModoAux(f) {
        return f.tipo.indexOf('tipo_aux:') === 0 || up(f.categoria) === 'AUXILIARES';
    }

    // Ejes que el snapshot no trae (LINK_GPS, COLOR y los LINK_* de documentos). Se avisan
    // en vez de ignorarlos calladamente: si no, el listado offline saldría MÁS grande que
    // el online y parecería que el filtro "no hace nada".
    function ejesNoSoportados(f) {
        const faltan = [];
        if (f.gps) faltan.push('GPS');
        if (f.color) faltan.push('color');
        if (f.docs.length) faltan.push('documentos');
        if (window.__equiposDocPresence && window.__equiposDocPresence !== 'con') faltan.push('documentos');
        return faltan.filter(function (x, i, a) { return a.indexOf(x) === i; });
    }

    // Búsqueda: MISMOS campos y misma semántica que applyBusquedaTexto() del backend
    // (subcadena en mayúsculas). '#123' busca por nº de etiqueta y las variantes O↔0
    // cubren la placa, que se teclea indistintamente con letra o cero.
    function coincideBusqueda(e, texto) {
        const s = up(texto).trim();
        if (!s) return true;
        if (s.indexOf('#') >= 0) return up(e.etiqueta).indexOf(s.replace(/#/g, '')) >= 0;
        if (up(e.serial_chasis).indexOf(s) >= 0) return true;
        if (up(e.serial_motor).indexOf(s) >= 0) return true;
        if (up(e.codigo_patio).indexOf(s) >= 0) return true;
        if (up(e.etiqueta).indexOf(s) >= 0) return true;
        const placa = up(e.placa);
        // El 4º str_replace del backend es secuencial (O→0 y luego 0→O), se replica igual.
        return [s, s.replace(/O/g, '0'), s.replace(/0/g, 'O'), s.replace(/O/g, '0').replace(/0/g, 'O')]
            .some(function (v) { return placa.indexOf(v) >= 0; });
    }

    // Igualdad como la del backend: where('COLUMNA', valor) sobre una colación _ci, o sea
    // exacta pero sin distinguir mayúsculas.
    function igual(valorFila, valorFiltro) {
        return !valorFiltro || up(valorFila) === up(valorFiltro);
    }

    function coincide(e, f, especifico) {
        if (!coincideBusqueda(e, f.q)) return false;

        if (f.frente === 'none') {
            if (e.id_frente != null) return false;
        } else if (f.frente && f.frente !== 'all') {
            if (String(e.id_frente == null ? '' : e.id_frente) !== f.frente) return false;
        } else if (!especifico && e.id_frente != null && especiales.has(Number(e.id_frente))) {
            return false;   // excludeEspecial(): asignaciones especiales fuera del listado general
        }

        if (f.tipo && f.tipo !== 'all') {
            // El dropdown manda el id pelado; 'tipo_eq:N' lo acepta el backend y se replica.
            const idTipo = f.tipo.indexOf('tipo_eq:') === 0 ? f.tipo.slice(8) : f.tipo;
            if (String(e.id_tipo == null ? '' : e.id_tipo) !== String(idTipo)) return false;
        }

        if (!igual(e.modelo, f.modelo)) return false;
        if (!igual(e.marca, f.marca)) return false;
        if (!igual(e.ubicacion, f.ubicacion)) return false;
        if (f.anio && String(e.anio == null ? '' : e.anio) !== f.anio) return false;
        if (!igual(e.categoria, f.categoria)) return false;
        if (!igual(e.estado, f.estado)) return false;

        const conf = up(f.confirmado);
        if (conf === 'SI' && Number(e.confirmado) !== 1) return false;
        if (conf === 'NO' && Number(e.confirmado) !== 0) return false;

        return true;
    }

    // Mensaje a pantalla completa dentro de la tabla, con el mismo formato que los estados
    // vacíos online (partials/table_rows.blade.php).
    function filaMensaje(icono, texto) {
        return '<tr><td colspan="' + COLS + '" class="table-empty-state" style="text-align:center;padding:40px;color:#94a3b8;">' +
            '<i class="material-icons" style="font-size:48px;display:block;margin:0 auto 10px auto;color:#cbd5e0;">' + icono + '</i>' + texto + '</td></tr>';
    }

    // Filtra la copia en memoria y repinta. Es lo que corre en cada tecla del buscador y
    // en cada clic de dropdown mientras el modo offline está activo.
    function pintar() {
        const tbody = getBody(); if (!tbody) return;
        // Primera pintada del módulo (o repintado tras navegar): la copia aún no está en
        // memoria. render() la trae y vuelve aquí; conOfflineDB espera a que IndexedDB
        // esté abierto, porque este camino también lo dispara una tecla del buscador.
        if (!datos) { OM.conOfflineDB(render); return; }
        // Cancela el scroll infinito del pintado anterior: los caminos que terminan en un
        // MENSAJE no pasan por porLotes y dejarían su observador vivo.
        OM.detenerLotes(tbody);
        ensureHideStyle();

        if (!datos.length) {
            tbody.innerHTML = filaMensaje('cloud_off', 'No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.');
            return;
        }

        const f = leerFiltros();
        if (!hayFiltro(f)) {
            tbody.innerHTML = filaMensaje('filter_alt', 'SELECCIONE UN FILTRO PARA VER LOS EQUIPOS.');
            return;
        }
        if (esModoAux(f)) {
            tbody.innerHTML = filaMensaje('cloud_off', 'LOS EQUIPOS AUXILIARES NO ESTÁN EN LA COPIA LOCAL. CONÉCTATE A INTERNET PARA VERLOS.');
            return;
        }

        // El aviso SOLO en modo offline: pintar() también corre con internet lento
        // (adelantarConCopiaLocal), y ahí decir "sin conexión" sería mentira — además esa
        // pintada la reemplaza la respuesta real del servidor, que sí aplica esos filtros.
        const faltan = OM.estaActivo() ? ejesNoSoportados(f) : [];
        const firma  = faltan.join(',');
        if (firma && firma !== avisoEjes && window.toast) {
            window.toast('Sin conexión no se puede filtrar por ' + faltan.join(' / ') + '. Se muestra el resto de los filtros.', 'warning');
        }
        avisoEjes = firma;

        const especifico = filtroEspecifico(f);
        const filas = datos.filter(function (e) { return coincide(e, f, especifico); });

        if (!filas.length) {
            tbody.innerHTML = filaMensaje('search_off', 'NO SE ENCONTRARON EQUIPOS CON LOS FILTROS APLICADOS.');
            return;
        }
        OM.porLotes(tbody, filas, filaEquipo, PAGE_SIZE);
    }

    function filaEquipo(e) {
        const est = ESTADOS[e.estado] || ESTADOS['DESINCORPORADO'];

        // FINALIZADO: mismo badge que online (wrapper flex centrado + icono ⚠).
        const finalizado = e.frente_finalizado
            ? '<div style="display:flex;align-items:center;justify-content:center;gap:3px;margin-top:3px;">' +
                '<span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:2px;border:1px solid #fecaca;">' +
                    '<i class="material-icons" style="font-size:10px;">warning</i>FINALIZADO</span>' +
              '</div>' : '';
        const etiqueta = e.etiqueta
            ? '<span style="font-weight:700;color:var(--maquinaria-blue);margin-left:6px;white-space:nowrap;"><i class="material-icons" style="font-size:13px;vertical-align:-2px;">tag</i>' + esc(e.etiqueta) + '</span>' : '';
        // eq-hide-mobile: CATEGORIA/AÑO se OCULTAN en móvil (≤900px) y el MODELO pasa a la
        // línea de la MARCA en 11.5px negrita (.eq-modelo), igual que la tabla online
        // (partials/table_rows.blade.php) — así la tarjeta offline luce idéntica.
        const categoria = e.categoria
            ? '<div class="eq-hide-mobile" style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;margin-top:5px;letter-spacing:0.3px;">' + esc(e.categoria) + '</div>' : '';
        const modelo = e.modelo ? '<span class="eq-modelo" style="display:block;font-size:13.5px;color:#475569;font-weight:500;text-transform:uppercase;margin-top:4px;line-height:1.3;">' + esc(e.modelo) + '</span>' : '';
        const anio = e.anio ? '<div class="eq-hide-mobile" style="font-size:12.5px;color:#64748b;margin-top:5px;font-weight:500;">Año: ' + esc(e.anio) + '</div>' : '';
        const motor = e.serial_motor ? '<div style="line-height:1.5;margin-top:3px;word-break:break-all;"><strong style="color:#64748b;">M:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_motor) + '</span></div>' : '';
        const placa = e.placa
            ? '<div style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">P:</strong> <span style="color:var(--maquinaria-blue);font-weight:700;text-transform:uppercase;">' + esc(e.placa) + '</span></div>'
            : '<div style="line-height:1.4;margin-top:3px;"><strong style="color:#64748b;">P:</strong> <span style="color:#a0aec0;font-style:italic;">Sin Placa</span></div>';
        // "ID: #<código de patio>" SOLO si lo tiene, igual que online: sin este @if la fila
        // offline mostraba un "ID: #—" que en la web no existe.
        const idPatio = e.codigo_patio
            ? '<div class="eq-id-line" style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">ID:</strong> <span style="color:#1e293b;font-weight:600;">#' + esc(e.codigo_patio) + '</span></div>'
            : '';

        // El filtrado va sobre los datos en memoria, no sobre el DOM: la fila no necesita
        // data-* de búsqueda (online tampoco los tiene) y así pesa lo mismo que la online.
        return '' +
            '<tr data-offline="1">' +
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
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.marca || '—') + modelo + '</div>' + anio +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14px;color:#4a5568;">' +
                '<div style="line-height:1.5;word-break:break-all;"><strong style="color:#64748b;">S:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_chasis || '—') + '</span></div>' +
                motor + placa + idPatio +
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

    // Milisegundos que se le conceden al servidor antes de adelantar con la copia local.
    // Por debajo de esto la respuesta real llega primero y el usuario no ve el adelanto.
    var ADELANTO_MS = 700;

    // Pinta la copia local YA FILTRADA mientras el servidor responde. `sigueEsperando` se
    // consulta DOS veces (antes y después de leer IndexedDB, que es asíncrono) para no pisar
    // una tabla que el servidor ya devolvió — sería mostrar datos peores que los buenos.
    // Si no hay copia local todavía no se pinta nada: mejor la tabla anterior que un aviso
    // de "sin copia" tapando una petición que va a responder.
    function adelantarConCopiaLocal(sigueEsperando) {
        if (!sigueEsperando() || !window.OfflineDB || !getBody()) return;
        cargarCopia().then(function () {
            if (!sigueEsperando() || !getBody() || !datos.length) return;
            pintar();
        }).catch(function () {});
    }

    // Trae la copia local a memoria: los equipos y qué frentes son ESPECIAL.
    function cargarCopia() {
        return Promise.all([
            window.OfflineDB.get('equipos').catch(function () { return []; }),
            window.OfflineDB.get('frentes').catch(function () { return []; }),
        ]).then(function (r) {
            datos = r[0] || [];
            // Copias bajadas antes de que el snapshot mandara el tipo de frente no traen la
            // marca: el conjunto queda vacío y no se excluye nada (el comportamiento de
            // antes) hasta que entre la siguiente copia de catálogos.
            especiales = new Set((r[1] || []).filter(function (f) { return f.especial; })
                                             .map(function (f) { return Number(f.id); }));
        });
    }

    // Punto de entrada del módulo (lo llama OfflineMode al activar el modo sin conexión y
    // cada vez que entra una copia nueva): refresca la copia en memoria y repinta.
    function render() {
        if (!getBody()) return Promise.resolve();
        return cargarCopia().then(pintar).catch(function () {});
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
        // mutar (y no get+put): entre una lectura y su escritura puede colarse una
        // sincronización y pisar este cambio, que aún no ha subido al servidor.
        window.OfflineDB.mutar('equipos', function (arr) {
            var e = arr.find(function (x) { return Number(x.id) === Number(id); });
            if (e) e.estado = status;
            return arr;
        }).then(render);
        window.toast('Cambio guardado. Se subirá al volver internet.', 'success');
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
            window.toast('Selecciona equipos primero.', 'error');
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
                // Optimista en la copia local + limpiar selección + repintar. mutar (y no
                // get+put) para que una sincronización no se cuele entre ambos y revierta
                // una movilización que todavía no ha subido.
                window.OfflineDB.mutar('equipos', function (arr) {
                    arr.forEach(function (e) { if (ids.indexOf(Number(e.id)) >= 0) { e.id_frente = sel.id; e.frente = sel.nombre; e.confirmado = 0; } });
                    return arr;
                }).then(function () {
                    window.selectedEquipos = {};
                    var bar = document.getElementById('bulkFloatingBar'); if (bar) bar.classList.remove('active');
                    render();
                });
                window.toast('Movilización guardada. El acta estará disponible al sincronizar.', 'success');
                cerrar();
            };
        });
    };

    function init() {
        if (!getBody()) return;
        OM.registrar('equipos', function () { return OM.conOfflineDB(render); });

        var inp = document.getElementById('searchInput');
        if (inp && !inp.dataset.offWiredEq) {
            inp.dataset.offWiredEq = '1';
            inp.addEventListener('input', function () { if (OM.estaActivo()) pintar(); });
        }

        // Patch loadEquipos: intercepta la llamada AJAX y filtra local si offline. Se re-parchea
        // en cada init por si el blade redefinió loadEquipos (guarda el orig sin doble-wrap).
        if (typeof window.loadEquipos === 'function' && window.loadEquipos !== window._eqOffPatchedLoad) {
            window._origLoadEquipos = window.loadEquipos;
            window._eqOffPatchedLoad = function () {
                if (OM.estaActivo()) { pintar(); return Promise.resolve(); }
                // Sin conexión pero SIN activar el modo offline: bloqueamos la búsqueda (no
                // pegarle al servidor caído) y avisamos que pulse "Trabajar sin conexión".
                if (OM.pendienteActivar && OM.pendienteActivar()) { OM.avisarActivar(); return Promise.resolve(); }
                // INTERNET LENTO (servidor vivo pero la respuesta no llega): el usuario se
                // quedaba mirando la tabla anterior con cada filtro. Si a los ADELANTO_MS el
                // servidor no ha contestado, pintamos la COPIA LOCAL ya filtrada — resultados
                // al instante — y la respuesta real la reemplaza al llegar (loadEquipos
                // reescribe el tbody entero). Con internet bueno nunca llega a dispararse.
                var pendiente = true;
                var temporizador = setTimeout(function () {
                    adelantarConCopiaLocal(function () { return pendiente; });
                }, ADELANTO_MS);
                var prom = window._origLoadEquipos.apply(null, arguments);
                Promise.resolve(prom).catch(function () {}).then(function () {
                    pendiente = false;
                    clearTimeout(temporizador);
                });
                return prom;
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
                setTimeout(pintar, 0);
            }
        }, true);

        window.addEventListener('dropdown-selection', function () {
            if (OM.estaActivo() && getBody()) pintar();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
