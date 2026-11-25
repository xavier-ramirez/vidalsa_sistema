/*
 * Formulario de la NOTA DE ENTREGA de una salida: Proyecto, Almacén destino, Fecha, RQ N°,
 * Solicitante, Departamento, Vehículo/Chofer y Observaciones.
 *
 * Lo comparten las dos pantallas que despachan con nota, para que pidan EXACTAMENTE lo mismo:
 *   · /admin/almacen → modal "Registrar salida" (almacen_index.js)
 *   · Recepción de materiales → modal "Registrar y despachar" (recepcion_entrada.js)
 * El HTML es partials/salida_nota_campos.blade.php (mismos ids en las dos pantallas) y los
 * estilos, css/vistas/admin_almacen_salida_nota.css. Este código vivía en almacen_index.js y
 * se trasladó tal cual.
 *
 * Se carga UNA vez por sesión (la SPA no re-ejecuta un <script src> ya cargado); cada pantalla
 * llama a AlmSalidaNota.configurar() con los datos de su apertura y AlmSalidaNota.abrir() al
 * abrir su modal. Los manejadores que el HTML llama por onclick/oninput (almLogSugerir,
 * almSalidaSyncAlmacenDestino, almSalidaOnProyectoChange) siguen siendo globales
 * con su nombre de siempre.
 */
(function () {
    'use strict';
    if (window.AlmSalidaNota) return;

    function el(id) { return document.getElementById(id); }
    function escHtml(s) { return window.escapeHtml(s); }
    function almNorm(s) { return window.FuzzySearch.norm(s); }

    // Datos de la apertura (los pasa cada pantalla en configurar):
    //   frenteContratos     { ID_FRENTE: ["CTR-…", …] }      → el Contrato N° de la Nota sin internet
    //   almacenesPorFrente  { ID_FRENTE: [{id, nombre}, …] } → "Almacén destino"
    //   frentesPorAlmacen   { ID_ALMACEN: [ID_FRENTE, …] }   → "Proyecto": los del almacén, primero
    //   formatoPorAlmacen   { ID_ALMACEN: "VERTICAL"|… }     → qué campos imprime la hoja
    //   formatoNotaHorizontal  valor de Almacen::FORMATO_NOTA_HORIZONTAL
    //   rutaLogistica       URL con __ID__ → choferes y vehículos del almacén
    //   idUsuario           para recordar sus departamentos
    //   antesDeSugerir      (opcional) se llama antes de abrir una lista de sugerencias:
    //                       /admin/almacen cierra ahí su menú Acciones (no deben coexistir)
    var CFG = { frenteContratos: {}, almacenesPorFrente: {}, frentesPorAlmacen: {}, formatoPorAlmacen: {}, formatoNotaHorizontal: '', rutaLogistica: '', idUsuario: 0, antesDeSugerir: null };
    // Almacén de ORIGEN de la salida (el que despacha). Texto, como llega del DOM.
    var ID_ORIGEN = '';

    // ── Sugerencias que flotan sobre un modal (.alm-suggest-float) ──────────
    // Coloca una lista .alm-suggest-float (position:fixed) justo debajo de su campo: el input que
    // diga data-ancla (cuando dos campos comparten la lista, el que se está escribiendo) o, si no,
    // su contenedor. data-ancho-min: ancho mínimo en px, si el modal tiene sitio. Solo actúa sobre
    // esas: las sugerencias de la barra de filtros son absolute normales y no necesitan anclaje.
    // Si no cabe debajo, se abre hacia arriba.
    function almSuggestAnclar(box) {
        if (!box || !box.classList.contains('alm-suggest-float') || !box.classList.contains('open')) return;
        var campo = (box.dataset.ancla && el(box.dataset.ancla)) || box.parentElement; if (!campo) return;
        almAnclarFlotante(box, campo, parseInt(box.dataset.anchoMin, 10) || 0);
    }
    // La matemática del anclaje, en UN solo sitio: la usan las sugerencias flotantes
    // (.alm-suggest-float). La lista de frentes del modal de almacén tiene la suya
    // (almFrentesAnclar en almacen_index.js): esa abre siempre hacia abajo.
    // anchoMin: la caja puede ser más ancha que su campo (cédula, placa o vehículo en PC) si el
    // modal tiene sitio; si así se sale por la derecha, se alinea con el borde derecho del campo.
    // En el teléfono el campo ya ocupa casi todo el modal: ahí manda su ancho, o quedaría descuadrada.
    function almAnclarFlotante(caja, ancla, anchoMin) {
        var r = ancla.getBoundingClientRect(), ancho = r.width, izq = r.left;
        if (anchoMin > r.width) {
            var cont = (ancla.closest('.alm-modal') || document.documentElement).getBoundingClientRect();
            if (cont.width - 24 - r.width >= 48) {
                ancho = Math.min(anchoMin, cont.width - 24);
                if (izq + ancho > cont.right - 12) izq = Math.max(cont.left + 12, r.right - ancho);
            }
        }
        caja.style.left  = izq + 'px';
        caja.style.width = ancho + 'px';   // el ancho se fija ANTES de medir el alto
        var alto = caja.offsetHeight;
        var cabeAbajo = (window.innerHeight - r.bottom - 8) >= alto;
        caja.style.top = (!cabeAbajo && r.top > alto ? (r.top - alto - 4) : (r.bottom + 4)) + 'px';
    }
    // Pinta la lista (o su aviso de vacía), la abre y la ancla.
    function mostrarSugerencias(box, html, emptyHtml) {
        if (!box) return;
        if (typeof CFG.antesDeSugerir === 'function') CFG.antesDeSugerir();
        box.innerHTML = html || (emptyHtml || '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        box.classList.add('open');
        // Las flotantes (modales) se anclan a su campo; las de la barra de filtros van debajo
        // de su buscador y solo hace falta que quepan (uicomponents.js).
        if (box.classList.contains('alm-suggest-float')) almSuggestAnclar(box);
        else window.ajustarListaAbajo(box);
    }
    // Al ser fixed, la lista no sigue sola a su campo: se reancla si la ventana cambia de
    // tamaño o si algo se desplaza (el cuerpo del modal, con scroll en captura porque el
    // evento scroll de un elemento no burbujea).
    function reanclarSugerencias() {
        document.querySelectorAll('.alm-suggest-float.open').forEach(almSuggestAnclar);
    }
    window.addEventListener('resize', reanclarSugerencias);
    document.addEventListener('scroll', reanclarSugerencias, true);

    // El formulario pide SOLO lo que imprime la hoja del almacén de origen. La nota
    // HORIZONTAL (admin.almacen.nota_entrega_horizontal_pdf) no imprime CONTRATO N° ni
    // RQ N° —son datos de la contratación y del pedido, no del despacho físico que esa
    // hoja controla—, así que esos dos campos se ocultan y las filas se reacomodan para
    // no dejar el hueco. Todo lo demás lo llevan los DOS formatos: Proyecto, Solicitante,
    // Departamento y Observaciones en el cuerpo, y la Fecha —que el horizontal estampa en
    // el sello del cabezote en vez del cuerpo, ver renderNotaEntregaPdfBinary—.
    //
    // El formato llega ya normalizado por Almacen::formatoNota() (nunca null ni basura). Si el
    // almacén no estuviera en el mapa se cae al formulario completo: pedir de más no rompe
    // ninguna nota, ocultar de menos sí.
    // ¿El almacén emite la nota acostada? Único sitio que lo decide (lo usa también el destino
    // por producto de la salida, vía AlmSalidaNota.esHorizontal).
    function almNotaEsHorizontal(idAlmacen) {
        var formato = (CFG.formatoPorAlmacen || {})[String(idAlmacen || '')];
        return !!formato && formato === CFG.formatoNotaHorizontal;
    }
    function almSalidaAplicarFormatoNota(idAlmacen) {
        var horizontal = almNotaEsHorizontal(idAlmacen);
        var wrapR = el('almSalidaRqWrap');       if (wrapR) wrapR.style.display = horizontal ? 'none' : '';
        // Reflow de la fila del grid. En mobile el CSS la fuerza a 1fr con !important, así
        // que estos anchos solo mandan en escritorio.
        var g2 = el('almSalidaGridDatos');    if (g2) g2.style.gridTemplateColumns = horizontal ? '1fr 1.4fr' : '1fr 1fr 1.4fr';
    }

    // ── Proyecto: primero los del almacén que despacha (consumo interno) ──
    // Los demás siguen debajo, en orden alfabético como vienen. Se reordena en cada apertura
    // (el almacén puede cambiar sin recargar); data-orden guarda el orden original.
    function almSalidaOrdenarProyectos(idAlmacen) {
        var cont = document.querySelector('#almSalidaProyectoDropdown .dropdown-item-list');
        if (!cont) return;
        var propios = {};
        ((CFG.frentesPorAlmacen || {})[String(idAlmacen || '')] || []).forEach(function (id) { propios[String(id)] = 1; });
        var items = Array.prototype.slice.call(cont.querySelectorAll('.dropdown-item'));
        items.forEach(function (it, i) { if (!it.hasAttribute('data-orden')) it.setAttribute('data-orden', String(i)); });
        items.sort(function (a, b) {
            return ((propios[b.getAttribute('data-value')] || 0) - (propios[a.getAttribute('data-value')] || 0))
                || (parseInt(a.getAttribute('data-orden'), 10) - parseInt(b.getAttribute('data-orden'), 10));
        }).forEach(function (it) { cont.appendChild(it); });
    }

    // ── Transporte de la salida: vehículo + placa y chofer + cédula ──
    // Campo del modal → campo que manda el payload (MovimientoInventario::CAMPOS_TRANSPORTE) y
    // lista a la que pertenece. ÚNICO sitio que los empareja: lo usan el reset, el payload y
    // las sugerencias.
    var ALM_LOG_CAMPOS = [
        { id: 'almSalidaVehiculo', campo: 'transporte_vehiculo', lista: 'vehiculos', parte: 'nombre' },
        { id: 'almSalidaPlaca',    campo: 'transporte_placa',    lista: 'vehiculos', parte: 'documento' },
        { id: 'almSalidaChofer',   campo: 'transporte_chofer',   lista: 'choferes',  parte: 'nombre' },
        { id: 'almSalidaCedula',   campo: 'transporte_cedula',   lista: 'choferes',  parte: 'documento' }
    ];
    // Sugerencias del almacén que despacha (las pide cada vez que se abre la salida: una nota
    // registrada recién pudo agregar un chofer). Si llegan tarde de otro almacén, se descartan.
    // `otros`: choferes de los DEMÁS almacenes; solo se ofrecen si lo escrito se les parece mucho
    // (almLogParecido).
    var ALM_LOG = { idAlmacen: '', choferes: [], vehiculos: [], otros: [] };
    function almLogCargar(idAlmacen) {
        ALM_LOG = { idAlmacen: String(idAlmacen || ''), choferes: [], vehiculos: [], otros: [] };
        if (!idAlmacen || !CFG.rutaLogistica) return;
        window.apiFetch(CFG.rutaLogistica.replace('__ID__', encodeURIComponent(idAlmacen)), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || ALM_LOG.idAlmacen !== String(idAlmacen)) return;
                ALM_LOG.choferes = d.choferes || []; ALM_LOG.vehiculos = d.vehiculos || []; ALM_LOG.otros = d.choferesOtros || [];
            })
            .catch(function () { /* sin sugerencias el transporte se escribe a mano */ });
    }
    function almLogOcultar() {
        ['almSalidaVehiculosSug', 'almSalidaChoferesSug'].forEach(function (id) { var b = el(id); if (b) b.classList.remove('open'); });
    }
    // Lista de su fila, filtrada por lo escrito. Vehículos: se busca ESCRIBIENDO la placa, el
    // serial de chasis o el nombre, y la lista no se abre entera al entrar (eran decenas para
    // elegir a ojo); cada renglón va con tipo y placa o serial (almLogRenglonVehiculo). Choferes: al entrar
    // a un campo vacío se ve la lista entera (son pocos). Sin nada que sugerir no se abre: el
    // campo sigue siendo libre.
    // Renglón de un vehículo: primero el TIPO y después la placa; el serial de chasis en vez de la
    // placa si se buscó por serial (lo escrito está en él y no en la placa) o si no tiene placa.
    // Sin marca ni modelo (pedido del cliente). El que no tiene placa de verdad trae el serial
    // como documento. Los de la lista del almacén traen serial y tipo si también están en la
    // flota; si no, se ven con el nombre con que se guardaron (no hay tipo del que tirar).
    function almLogRenglonVehiculo(it, term) {
        var serial = it.serial || '', placa = serial && it.documento === serial ? '' : (it.documento || '');
        var porSerial = !!serial && (!placa || (!!term && almNorm(serial).indexOf(term) > -1 && almNorm(placa).indexOf(term) < 0));
        var tipo = it.tipo || (it.origen === 'flota' ? '' : (it.nombre || ''));
        return '<span class="alm-log-tipo">' + escHtml(tipo || 'VEHÍCULO') + '</span>'
            + (porSerial ? '<span class="alm-log-serial">S/C ' + escHtml(serial) + '</span>'
                         : '<span class="alm-log-doc' + (placa ? '' : ' sin-placa') + '">' + escHtml(placa || 'SIN PLACA') + '</span>');
    }
    // ¿Lo escrito es casi seguro ESTE chofer de otro almacén? En la cédula, 5 cifras o más que
    // estén dentro de la suya, o la cédula entera con un dígito errado; en el nombre, todas las
    // palabras escritas (de 3 letras o más, y al menos 4 letras en total) al comienzo de alguna
    // de las suyas, con un dedazo (dos si la palabra tiene 6 letras o más). Más flojo que eso
    // llenaría la lista de choferes ajenos.
    var ALM_LOG_CIFRAS_MIN = 5;
    function almLogParecido(it, valor, parte) {
        var FS = window.FuzzySearch;
        if (parte === 'documento') {
            var d = String(valor).replace(/\D/g, ''), c = String(it.documento || '').replace(/\D/g, '');
            if (d.length < ALM_LOG_CIFRAS_MIN || !c) return false;
            return c.indexOf(d) > -1 || (d.length >= 7 && Math.abs(d.length - c.length) <= 1 && FS.leven(d, c, 1) <= 1);
        }
        var palabras = FS.tokenize(valor).filter(function (t) { return t.length >= 3; });
        if (!palabras.length || palabras.join('').length < 4) return false;
        var suyas = FS.norm(it.nombre).split(/\s+/).filter(Boolean);
        return palabras.every(function (t) {
            var tope = t.length >= 6 ? 2 : 1;
            return suyas.some(function (w) { return FS.leven(t, w.substr(0, t.length), tope) <= tope; });
        });
    }
    var ALM_LOG_OTROS_TOPE = 5;
    window.almLogSugerir = function (inp, alEntrar) {
        var tipo = inp.getAttribute('data-log'), lista = ALM_LOG[tipo] || [];
        var box = el(tipo === 'choferes' ? 'almSalidaChoferesSug' : 'almSalidaVehiculosSug');
        if (!box) return;
        almLogOcultar();
        var veh = tipo === 'vehiculos';
        var term = almNorm(inp.value.trim());
        // Choferes de otros almacenes: solo con algo escrito que se les parezca mucho, debajo de
        // los propios y con el almacén de donde vienen. Al elegirlo entra a la lista de este
        // almacén (recordar, al registrar la nota) y la próxima vez ya sale como propio.
        var parte = (ALM_LOG_CAMPOS.filter(function (c) { return c.id === inp.id; })[0] || {}).parte;
        var otros = [];
        if (!veh && term) {
            ALM_LOG.otros.forEach(function (it, i) {
                if (otros.length < ALM_LOG_OTROS_TOPE && almLogParecido(it, inp.value, parte)) otros.push(i);
            });
        }
        if ((!lista.length && !otros.length) || (veh && !term)) return;
        var html = '', n = 0;
        lista.forEach(function (it, i) {
            var serial = it.serial || '';
            if (n >= 80 || !(alEntrar && !term || almNorm(it.nombre + ' ' + it.documento + ' ' + serial).indexOf(term) > -1)) return;
            html += '<div class="si-item alm-log-item' + (veh ? ' veh' : '') + '" data-log="' + tipo + '" data-i="' + i + '">'
                + (veh ? almLogRenglonVehiculo(it, term)
                       : '<span class="alm-log-nom">' + escHtml(it.nombre) + '</span><span class="alm-log-doc">' + escHtml(it.documento) + '</span>')
                + '</div>';
            n++;
        });
        otros.forEach(function (i) {
            var it = ALM_LOG.otros[i];
            html += '<div class="si-item alm-log-item" data-log="choferes" data-fuente="otros" data-i="' + i + '">'
                + '<span class="alm-log-nom">' + escHtml(it.nombre) + '</span><span class="alm-log-alm">' + escHtml(it.almacen) + '</span>'
                + '<span class="alm-log-doc">' + escHtml(it.documento) + '</span></div>';
        });
        // Debajo del campo que se escribe y con su ancho: colgada de la fila cruzaba el modal
        // entero y en el teléfono la del chofer salía debajo de la cédula. Si el modal tiene sitio,
        // la lista no baja de un ancho legible (chofer y cédula; el vehículo en una sola línea).
        box.dataset.ancla = inp.id;
        box.dataset.anchoMin = veh ? '320' : '260';
        mostrarSugerencias(box, html, '<div class="alm-suggest-empty">Sin coincidencias: se imprime lo que escribas.</div>');
    };
    // Elegir uno llena los dos campos de su fila (nombre y documento).
    document.addEventListener('click', function (e) {
        var item = e.target.closest('.alm-log-item');
        if (item) {
            e.preventDefault();
            var tipo = item.getAttribute('data-log');
            var it = (ALM_LOG[item.getAttribute('data-fuente') || tipo] || [])[parseInt(item.getAttribute('data-i'), 10)];
            if (it) ALM_LOG_CAMPOS.forEach(function (c) { if (c.lista === tipo) el(c.id).value = it[c.parte] || ''; });
            almLogOcultar();
            return;
        }
        if (!e.target.closest('[data-log]') && !e.target.closest('#almSalidaVehiculosSug, #almSalidaChoferesSug')) almLogOcultar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && e.target.closest && e.target.closest('[data-log]')) almLogOcultar();
    });

    // ── Departamento: lo que cada quien usa ──
    // Vive en el NAVEGADOR de cada usuario (localStorage), no en la base: es la costumbre de
    // quien despacha, no un catálogo de la empresa. Por eso la clave lleva su id — si dos
    // personas comparten el equipo, cada una ve lo suyo.
    //
    // Solo se recuerda lo que llegó a una nota REGISTRADA: escribir algo y arrepentirse no
    // deja rastro. Y un valor usado UNA sola vez se descarta a los DEPTO_OLVIDO_DIAS: así un
    // dedazo ("Mantenimeinto") deja de sugerirse solo, mientras que el que se repite se queda.
    var DEPTO_TOPE        = 8;     // cuántos se ofrecen, de más usado a menos
    var DEPTO_OLVIDO_DIAS = 60;
    function deptoClave() { return 'alm_deptos_u' + CFG.idUsuario; }

    function almDeptoLeer() {
        try { return JSON.parse(localStorage.getItem(deptoClave())) || {}; } catch (e) { return {}; }
    }

    /** Ordenados por uso (y, a igualdad, por el más reciente), ya sin los olvidados. */
    function almDeptoLista() {
        var datos = almDeptoLeer();
        var corte = Date.now() - DEPTO_OLVIDO_DIAS * 86400000;
        return Object.keys(datos)
            .filter(function (k) { return datos[k].n > 1 || datos[k].t > corte; })
            .sort(function (a, b) { return (datos[b].n - datos[a].n) || (datos[b].t - datos[a].t); })
            .slice(0, DEPTO_TOPE);
    }

    function almDeptoPintar() {
        var lista = el('almSalidaDeptoLista');
        if (!lista) return;
        lista.innerHTML = almDeptoLista().map(function (d) {
            return '<option value="' + escHtml(d) + '"></option>';
        }).join('');
    }

    /** Lo llama SOLO el registro de la salida, nunca el tecleo. */
    function almDeptoRecordar(valor) {
        var v = String(valor || '').replace(/\s+/g, ' ').trim();
        if (v.length < 3) return;                     // ni vacío ni dos letras sueltas
        try {
            var datos = almDeptoLeer();
            var corte = Date.now() - DEPTO_OLVIDO_DIAS * 86400000;
            // Se limpia al escribir, no en un barrido aparte: así no crece sin fin.
            Object.keys(datos).forEach(function (k) {
                if (datos[k].n <= 1 && datos[k].t <= corte) delete datos[k];
            });
            datos[v] = { n: ((datos[v] && datos[v].n) || 0) + 1, t: Date.now() };
            localStorage.setItem(deptoClave(), JSON.stringify(datos));
        } catch (e) { /* sin localStorage (modo privado): el campo sigue funcionando igual */ }
        almDeptoPintar();
    }

    // ── Aviso por campo ──
    // Lo que falta en Proyecto o Almacén destino se avisa JUSTO debajo de ese campo (y el
    // campo en rojo), no al pie del modal, donde quedaba lejos del campo. msg vacío = lo borra.
    var CAMPO_ERROR = {
        proyecto: { dd: 'almSalidaProyectoDropdown',       msg: 'almSalidaProyectoError' },
        destino:  { dd: 'almSalidaAlmacenDestinoDropdown', msg: 'almSalidaAlmacenDestinoError' },
    };
    function almSalidaCampoError(campo, msg) {
        var c = CAMPO_ERROR[campo]; if (!c) return;
        var dd = el(c.dd), m = el(c.msg);
        if (dd) dd.classList.toggle('alm-campo-invalido', !!msg);
        if (!m) return;
        m.textContent = msg || '';
        m.classList.toggle('visible', !!msg);
        // Sin .focus(): el foco en el buscador abre la lista sola (ver abrir()).
        if (msg && dd) { try { dd.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (x) { dd.scrollIntoView(false); } }
    }

    // ── Almacén destino ──
    // Almacenes a los que PUEDE ir el material del proyecto elegido: los del frente
    // menos el de origen (mandarse material a uno mismo no es un traspaso).
    function almSalidaDestinosDe(idFrente) {
        var lista = (CFG.almacenesPorFrente || {})[idFrente] || [];
        // ID_ORIGEN es TEXTO y a.id viene numérico del JSON: sin igualar el tipo,
        // el almacén de origen nunca se descartaría y saldría ofrecido como destino.
        var origen = parseInt(ID_ORIGEN, 10);
        return lista.filter(function (a) { return a.id !== origen; });
    }

    // Enseña u oculta "Almacén destino" según el proyecto elegido. Con 0 o 1 destino no
    // hay nada que preguntar (0 = consumo en el propio almacén, 1 = lo deduce el backend).
    window.almSalidaSyncAlmacenDestino = function () {
        var wrap = el('almSalidaDestinoWrap');
        var caja = el('almSalidaAlmacenDestinoItems');
        var sel  = el('almSalidaProyecto');
        if (!wrap || !caja || !sel) return;

        var destinos = almSalidaDestinosDe(sel.value);

        // Se limpia SIEMPRE al cambiar de proyecto: si no, una elección del proyecto
        // anterior viajaría en el payload del siguiente y el backend la rechazaría por
        // no pertenecer al frente.
        window.clearDropdownFilter('almSalidaAlmacenDestinoDropdown');
        almSalidaCampoError('destino', '');

        if (destinos.length < 2) {
            wrap.style.display = 'none';
            caja.innerHTML = '';
            return;
        }

        caja.innerHTML = destinos.map(function (a) {
            // Los dos por los helpers centrales: el nombre del almacén es texto libre que se
            // escribe en este mismo módulo. Escapando solo la comilla simple, un almacén con
            // comilla DOBLE cerraba el atributo onclick y rompía el desplegable entero.
            var nombre = escHtml(String(a.nombre));
            var jsArg  = window.escapeAttrJs(String(a.nombre));
            return '<div class="dropdown-item" data-value="' + a.id + '"' +
                   ' onclick="selectOption(\'almSalidaAlmacenDestinoDropdown\',\'' + a.id + '\',\'' +
                   jsArg + '\');">' + nombre + '</div>';
        }).join('');
        wrap.style.display = '';
    };

    window.almSalidaOnProyectoChange = function () {
        var sel  = el('almSalidaProyecto');
        if (!sel) return;
        window.almSalidaSyncAlmacenDestino();
    };
    // Al elegir proyecto (componente custom-dropdown global): almacén destino.
    window.addEventListener('dropdown-selection', function (e) {
        if (e.detail && e.detail.dropdownId === 'almSalidaProyectoDropdown') {
            // El nombre elegido, para la Nota sin internet si la lista se repinta sin él
            // (almNotaLocalDatos): el buscador puede tener otra cosa escrita.
            // Con la ✕ (sin valor) llega el texto de relleno «Selecciona uno»: se borra.
            var hid = el('almSalidaProyecto');
            if (hid && e.detail.value) hid.setAttribute('data-nombre', String(e.detail.label || ''));
            else if (hid) hid.removeAttribute('data-nombre');
            if (e.detail.value) almSalidaCampoError('proyecto', '');
            window.almSalidaOnProyectoChange();
        } else if (e.detail && e.detail.dropdownId === 'almSalidaAlmacenDestinoDropdown' && e.detail.value) {
            almSalidaCampoError('destino', '');
        }
    });

    window.AlmSalidaNota = {
        /** Datos de la apertura de la pantalla (ver CFG arriba). */
        configurar: function (cfg) {
            Object.keys(cfg || {}).forEach(function (k) { CFG[k] = cfg[k]; });
            almDeptoPintar();
        },

        /** true si el almacén idAlmacen emite la Nota de Entrega HORIZONTAL. */
        esHorizontal: almNotaEsHorizontal,

        /**
         * Deja el formulario listo para una salida NUEVA del almacén idAlmacen (el que
         * despacha). opts.motivo: observación con la que arranca (p. ej. el kit cargado).
         * No abre el modal: eso es de cada pantalla.
         */
        abrir: function (idAlmacen, opts) {
            ID_ORIGEN = String(idAlmacen || '');
            // Antes de mostrar nada: el almacén de origen decide qué campos se piden. Va aquí
            // (y no una sola vez al cargar la página) porque el usuario puede cambiar de almacén
            // sin recargar — el modal es el mismo nodo para todos.
            almSalidaAplicarFormatoNota(ID_ORIGEN);
            // Limpiar campos de Nota de Entrega y poner FECHA = hoy por default.
            ['almSalidaRq', 'almSalidaSolicitante', 'almSalidaDepartamento', 'almSalidaMotivo'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
            if (opts && opts.motivo) { var mo = el('almSalidaMotivo'); if (mo) mo.value = opts.motivo; }
            ALM_LOG_CAMPOS.forEach(function (c) { var e = el(c.id); if (e) e.value = ''; });
            almLogCargar(ID_ORIGEN);
            almDeptoPintar();
            // El campo Proyecto es un custom-dropdown: lo reseteamos con su helper para que
            // el placeholder vuelva al default y el hidden #almSalidaProyecto quede vacío.
            if (typeof window.clearDropdownFilter === 'function') {
                window.clearDropdownFilter('almSalidaProyectoDropdown');
            }
            var hidP = el('almSalidaProyecto'); if (hidP) hidP.removeAttribute('data-nombre');
            almSalidaCampoError('proyecto', '');
            almSalidaOrdenarProyectos(ID_ORIGEN);
            var fe = el('almSalidaFecha'); if (fe) fe.value = window.hoyISO();
            // Asegurar que el dropdown de Proyecto NO quede abierto si una sesion previa lo
            // dejo con .active (el helper global focusin auto-abre cuando el input del trigger
            // recibe foco — por eso evitamos hacer .focus() automatico al abrir el modal).
            var ddProy = el('almSalidaProyectoDropdown');
            if (ddProy) ddProy.classList.remove('active');
            // "Almacén destino" arranca oculto y vacío: se decide al elegir proyecto. Sin este
            // reset, reabrir el modal desde OTRO almacén conservaría el destino del anterior.
            window.almSalidaSyncAlmacenDestino();
            var ddDest = el('almSalidaAlmacenDestinoDropdown');
            if (ddDest) ddDest.classList.remove('active');
        },

        /**
         * Lo escrito en el formulario, con los nombres que espera el backend
         * (id_frente_destino, id_almacen_destino, fecha, numero_contrato, numero_rq,
         * solicitante, departamento, motivo, transporte_*). Devuelve { error } si falta el
         * proyecto o, cuando hay que elegirlo, el almacén destino; si no, { datos }. El aviso
         * ya queda pintado debajo de ese campo: quien llama no lo repite al pie del modal.
         */
        datos: function () {
            var v = function (id) { var e = el(id); return e ? e.value.trim() : ''; };
            var falta = function (campo, msg) { almSalidaCampoError(campo, msg); return { error: msg }; };
            almSalidaCampoError('proyecto', '');
            almSalidaCampoError('destino', '');
            var idFrenteDest = v('almSalidaProyecto');
            if (!idFrenteDest) return falta('proyecto', 'Elige el proyecto / frente destino.');
            var d = { id_frente_destino: parseInt(idFrenteDest, 10) };
            // Almacén destino: solo viaja cuando el campo está visible, o sea cuando el
            // proyecto se maneja en varios almacenes y hay que decir a cuál va. Con uno solo
            // lo deduce el backend y mandarlo sería ruido.
            var wrapDest = el('almSalidaDestinoWrap');
            if (wrapDest && wrapDest.style.display !== 'none') {
                var idDest = v('almSalidaAlmacenDestino');
                if (!idDest) return falta('destino', 'Este proyecto se maneja en varios almacenes: elige el almacén destino.');
                d.id_almacen_destino = parseInt(idDest, 10);
            }
            var fecha  = v('almSalidaFecha');         if (fecha)  d.fecha = fecha;
            // El Contrato N° no se escribe: es el del proyecto. El servidor lo pone igual
            // (DatosNotaSalida::contratoDe); aquí viaja para la Nota hecha sin internet.
            var contr  = ((CFG.frenteContratos || {})[idFrenteDest] || [])[0];
            if (contr) d.numero_contrato = contr;
            var rqN    = v('almSalidaRq');            if (rqN)    d.numero_rq = rqN;
            var solic  = v('almSalidaSolicitante');   if (solic)  d.solicitante = solic;
            var depto  = v('almSalidaDepartamento');  if (depto)  d.departamento = depto;
            var motivo = v('almSalidaMotivo');        if (motivo) d.motivo = motivo;
            ALM_LOG_CAMPOS.forEach(function (c) { var t = v(c.id); if (t) d[c.campo] = t; });
            return { datos: d };
        },

        /** El departamento de una nota YA registrada (ver almDeptoRecordar). */
        recordarDepto: almDeptoRecordar,

        // Sugerencias flotantes: las usan también otros campos de /admin/almacen.
        mostrarSugerencias: mostrarSugerencias,
    };
})();
