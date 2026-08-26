/**
 * Chrome del layout, parte 2: menu movil, sistema de modales (showModal / confirm),
 * visor de PDF con sus anexos, panel de metadatos y confirmaciones de borrado.
 *
 * Movido TAL CUAL desde el <script> inline de estructura_base.blade.php (2026-08-24),
 * al mismo punto del layout, sincrono y sin defer. Eran 92 KB de JS estatico viajando
 * dentro del HTML en cada carga completa Y en cada navegacion SPA.
 *
 * LOS FLAGS DE PERMISO NO ESTAN AQUI: window.CAN_UPDATE_INFO / CAN_CREATE_EQUIPOS /
 * CAN_ASSIGN_EQUIPOS / CAN_CHANGE_STATUS / CAN_DELETE_DOCS los renderiza Blade con
 * auth()->user()->can(...), asi que se quedaron en un <script> inline de 5 lineas
 * JUSTO ANTES de este archivo en el layout. Este codigo los LEE, no los define.
 */
// Colapsa todos los grupos expandidos del menu mobile (Flota,
// Configuraciones, etc). Reusable para cuando el menu se cierra:
// antes el grupo abierto quedaba "recordando" su estado y al
// reabrir el hamburguesa seguia desplegado.
function _mobileNavCollapseAll() {
    document.querySelectorAll('.mobile-nav-group.active').forEach(g => {
        g.classList.remove('active');
    });
}

function toggleMobileMenu() {
    const menu = document.getElementById('mobileMenu');
    if (!menu) return;
    const willOpen = !menu.classList.contains('active');
    menu.classList.toggle('active');
    // Si lo estamos cerrando, colapsa todos los grupos para que el
    // proximo open no muestre estado residual.
    if (!willOpen) _mobileNavCollapseAll();
}

// Cerrar el menu movil al hacer click fuera (ni en el menu ni en el hamburger).
// Guard _mobileMenuOutsideReady evita duplicar listener en SPA re-ejecuciones.
if (!window._mobileMenuOutsideReady) {
    window._mobileMenuOutsideReady = true;
    document.addEventListener('click', function (e) {
        const menu = document.getElementById('mobileMenu');
        if (!menu || !menu.classList.contains('active')) return;
        if (e.target.closest('.mobile-menu') || e.target.closest('.menu-toggle')) return;
        menu.classList.remove('active');
        _mobileNavCollapseAll();
    });
}

// Toggle Mobile Groups (Flota, Configuraciones, etc.) — event delegation
// para que funcione con cualquier grupo sin nombrarlos uno por uno y
// para sobrevivir re-renders SPA (delegacion en document, idempotente).
if (!window._mobileNavGroupDelegated) {
    window._mobileNavGroupDelegated = true;
    document.addEventListener('click', (e) => {
        const title = e.target.closest('.mobile-nav-group-title');
        if (!title) return;
        const group = title.closest('.mobile-nav-group');
        if (!group) return;
        e.stopPropagation();
        // Acordeón: solo UN grupo desplegado a la vez. Cerramos todos y, si
        // el tocado no estaba abierto, lo abrimos → al desplegar uno se
        // recoge el otro. Clic en el que ya estaba abierto = se cierra.
        const yaAbierto = group.classList.contains('active');
        document.querySelectorAll('.mobile-nav-group.active').forEach(g => g.classList.remove('active'));
        if (!yaAbierto) group.classList.add('active');
    });
}

// Desplegables de la cabecera (Flota, Almacén, Configuraciones) — delegación en
// document, igual que los grupos del menú móvil de arriba.
// Se engancha EN CUANTO corre este script, NO en DOMContentLoaded: al entrar tras el
// login el preloader se oculta enseguida (el splash del login ya cubrió la
// transición), así que la cabecera ya se ve y se puede pulsar mientras todavía se
// descargan los <script> del final del body. Con el enganche anterior —un listener
// por botón dentro de DOMContentLoaded— esos primeros clics se perdían y parecía que
// "Flota" no desplegaba. Delegando funciona desde el primer pintado y además
// sobrevive a los re-renders de la SPA. El guard evita duplicar el listener.
if (!window._navDropdownDelegated) {
    window._navDropdownDelegated = true;
    document.addEventListener('click', (e) => {
        // Un click sintetico puede llegar con target = document (sin closest).
        if (!e.target || !e.target.closest) return;
        const dropdowns = document.querySelectorAll('.nav-dropdown');
        if (!dropdowns.length) return;
        const trigger = e.target.closest('.nav-dropdown > .nav-link');
        if (trigger) {
            e.preventDefault();
            const dropdown = trigger.closest('.nav-dropdown');
            dropdowns.forEach(d => { if (d !== dropdown) d.classList.remove('active'); });
            dropdown.classList.toggle('active');
            return;
        }
        // Clic en un enlace de dentro, o fuera de cualquier desplegable: se cierran todos.
        if (e.target.closest('.nav-dropdown-link') || !e.target.closest('.nav-dropdown')) {
            dropdowns.forEach(d => d.classList.remove('active'));
        }
    });
}

// Modal Logic
let modalCallback = null;
let modalCancelCallback = null;

/**
 * Generic Modal System
 * @param {Object} options { type, title, message, onConfirm, onCancel, confirmText, cancelText, hideCancel }
 */
// Confirmar-antes-de-actuar. Envuelve showModal con el respaldo al confirm() del
// navegador para cuando el modal aún no está montado (carga directa, error de JS).
// Vive aquí, al lado de showModal, porque cada módulo que lo necesitaba repetía
// el mismo if/else de doce líneas: recepción lo tenía cuatro veces.
//   confirmarAccion({title, message, confirmText, cancelText, type}, alConfirmar)
// `message` admite HTML (se muestra con innerHTML); en el respaldo se le quitan
// las etiquetas, que en un confirm() del sistema saldrían crudas.
window.confirmarAccion = function (opciones, alConfirmar) {
    var o = opciones || {};
    if (typeof window.showModal === 'function') {
        window.showModal({
            type:        o.type || 'warning',
            title:       o.title || 'Confirmar',
            message:     o.message || '',
            confirmText: o.confirmText || 'Continuar',
            cancelText:  o.cancelText || 'Volver',
            onConfirm:   alConfirmar,
        });
        return;
    }
    if (window.confirm(String(o.message || '').replace(/<[^>]+>/g, ''))) alConfirmar();
};

window.showModal = function (options) {
    const config = {
        type: 'info', // success, error, warning, info
        title: 'Aviso',
        message: '',
        confirmText: 'Aceptar',
        cancelText: 'Cancelar',
        hideCancel: false,
        onConfirm: null,
        onCancel: null,
        ...options
    };

    const modalEl = document.getElementById('standardModal');
    const iconEl = document.getElementById('modalIcon');
    const titleEl = document.getElementById('modalTitle');
    const messageEl = document.getElementById('modalMessage');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    const cancelBtn = document.getElementById('modalCancelBtn');

    // Guard: if any modal element is missing, fall back to alert
    if (!modalEl || !titleEl || !messageEl || !confirmBtn || !cancelBtn) {
        console.warn('showModal: modal DOM elements not found, using alert fallback');
        if (config.type === 'error' || config.type === 'warning') {
            alert(`${config.title}\n\n${config.message}`);
        }
        if (config.onConfirm) config.onConfirm();
        return;
    }

    // Set content
    titleEl.innerText = config.title;
    messageEl.innerHTML = config.message;
    confirmBtn.innerText = config.confirmText;
    cancelBtn.innerText = config.cancelText;
    cancelBtn.style.display = config.hideCancel ? 'none' : 'block';

    // Set Icon and colors
    iconEl.className = 'material-icons modal-icon';
    confirmBtn.className = 'modal-btn modal-btn-confirm';

    // Compress modal and force blue buttons
    confirmBtn.style.backgroundColor = 'var(--maquinaria-blue, #1e293b)';
    confirmBtn.style.color = 'white';
    confirmBtn.style.border = 'none';

    switch (config.type) {
        case 'success':
            iconEl.innerText = 'check_circle';
            iconEl.classList.add('modal-icon-success');
            break;
        case 'error':
        case 'danger':
            iconEl.innerText = 'error';
            iconEl.classList.add('modal-icon-error');
            confirmBtn.style.backgroundColor = '#dc2626'; // Keep red for errors
            break;
        case 'warning':
            iconEl.innerText = 'warning';
            iconEl.classList.add('modal-icon-warning');
            break;
        default:
            iconEl.innerText = 'help_outline';
            iconEl.classList.add('modal-icon-info');
    }

    modalCallback = config.onConfirm;

    // Show modal
    modalEl.classList.add('active');

    // Auto-close success modal after 3s (unless disabled)
    if (config.type === 'success' && !config.disableAutoClose) {
        setTimeout(() => {
            const modalEl = document.getElementById('standardModal');
            if (modalEl && modalEl.classList.contains('active')) {
                const confirmBtn = document.getElementById('modalConfirmBtn');
                if (confirmBtn) confirmBtn.click();
            }
        }, 3000);
    }

    // Handle confirm
    confirmBtn.onclick = () => {
        if (modalCallback) modalCallback();
        closeModal();
    };

    // Handle cancel (wired here so onCancel callback fires)
    cancelBtn.onclick = () => {
        cancelModal();
    };

    // Store cancel callback
    modalCancelCallback = config.onCancel || null;
}

window.closeModal = function () {
    const modalEl = document.getElementById('standardModal');
    if (modalEl) modalEl.classList.remove('active');
    modalCallback = null;
    modalCancelCallback = null;
}

window.cancelModal = function () {
    const cb = modalCancelCallback;
    closeModal();
    if (cb) cb();
}


// --- Custom UI Components (SPA Friendly) ---
// Moved to js/maquinaria/uicomponents.js to ensure availability before other scripts


// showDetailsImproved y closeDetailsModal viven en uicomponents.js, que el layout carga
// ANTES que este archivo.

// --- PDF Preview System (Internal View) - OPTIMIZED ---

/* ── El documento y sus correcciones, en un solo archivo ────────────────────────
   Descargar e Imprimir actuan sobre el EXPEDIENTE completo: el original y todas sus
   correcciones unidos en un PDF, en ese orden. Antes cada boton se llevaba solo la
   hoja abierta, asi que con una correccion encima habia que repetir la operacion
   documento por documento (y al imprimir salia siempre el principal).

   Se une en el NAVEGADOR con pdf-lib y no en el servidor: TCPDF no sabe importar
   paginas de un PDF existente y FPDI (que si) no lee los PDF con xref comprimido
   -que son el 60% de los documentos cargados- salvo con licencia de pago. Ademas los
   archivos ya estan en la cache del navegador de haberlos visto en el visor, asi que
   unirlos no vuelve a bajar nada de Drive: es solo CPU local. */

const PDF_UNION_MAX_BYTES = 25 * 1024 * 1024;   // valvula: en un movil, mas es tumbar la pestana

/** Enlaces del expediente abierto, en orden: principal y luego sus correcciones. */
const _pdfEnlacesExpediente = function () {
    const ctx = window._pdfAnexoCtx;
    if (!ctx || !ctx.principal) return [];
    const lista = ((window._anexosPorEquipo || {})[ctx.equipoId] || {})[ctx.tipo] || [];
    return [ctx.principal].concat(lista.map((a) => a.link).filter(Boolean));
};

/**
 * Une el expediente en un Blob. Devuelve null si no hay nada que unir (documento sin
 * correcciones), y lanza si algo falla — quien llama decide el respaldo.
 */
const _pdfUnirExpediente = async function () {
    const urls = _pdfEnlacesExpediente();
    if (urls.length < 2) return null;

    await window.cargarScriptUnaVez(
        (window.lazyBaseUrl ? window.lazyBaseUrl() : '') + '/js/vendor/pdf-lib-1.17.1.min.js',
        () => !!window.PDFLib
    );

    // credentials + force-cache, igual que la descarga suelta: la URL es del proxy
    // propio (/storage/google/...), asi que sale del cache del navegador sin red.
    let total = 0;
    const contenidos = [];
    for (const url of urls) {
        const r = await fetch(url, { credentials: 'include', cache: 'force-cache' });
        if (!r.ok) throw new Error('HTTP ' + r.status + ' en ' + url);
        const buf = await r.arrayBuffer();
        total += buf.byteLength;
        if (total > PDF_UNION_MAX_BYTES) throw new Error('el expediente pesa mas de 25 MB');
        contenidos.push(buf);
    }

    const salida = await window.PDFLib.PDFDocument.create();
    for (const buf of contenidos) {
        // ignoreEncryption: muchos escaneos vienen con permisos puestos aunque no
        // pidan contrasena; sin esto pdf-lib se niega a abrirlos.
        const doc = await window.PDFLib.PDFDocument.load(buf, { ignoreEncryption: true });
        const paginas = await salida.copyPages(doc, doc.getPageIndices());
        paginas.forEach((pag) => salida.addPage(pag));
    }
    return new Blob([await salida.save()], { type: 'application/pdf' });
};

/**
 * El expediente unido, o null para seguir por el camino de siempre (un solo archivo).
 * Nunca rechaza: si la union falla, avisa y devuelve null.
 */
const _pdfPrepararUnion = function () {
    if (_pdfEnlacesExpediente().length < 2) return Promise.resolve(null);
    return _pdfUnirExpediente().catch(function (e) {
        console.warn('No se pudo unir el expediente:', e);
        if (window.toast) {
            window.toast('No se pudieron unir las correcciones; va solo el documento abierto.', 'warning');
        }
        return null;
    });
};

/** Manda un Blob a la impresora desde un iframe oculto. */
const _pdfImprimirBlob = function (blob, alTerminar) {
    const blobUrl = URL.createObjectURL(blob);
    const printFrame = document.createElement('iframe');
    printFrame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    printFrame.src = blobUrl;
    document.body.appendChild(printFrame);
    printFrame.onload = () => {
        try {
            printFrame.contentWindow.focus();
            printFrame.contentWindow.print();
        } catch (e) {
            console.warn('print iframe error:', e);
        } finally {
            if (alTerminar) alTerminar();
            // El blobUrl + iframe se limpian un rato despues para no cancelar el
            // dialogo de impresion abierto.
            setTimeout(() => {
                URL.revokeObjectURL(blobUrl);
                if (printFrame.parentNode) printFrame.parentNode.removeChild(printFrame);
            }, 60000);
        }
    };
};

// Optimized Direct PDF Download with visual feedback
window.downloadPdfDirect = function (url, documentLabel) {
    if (!url) {
        alert('No hay URL para descargar');
        return;
    }

    const downloadBtn = document.getElementById('pdfDownloadBtn');
    const pintarBoton = function (cargando) {
        if (!downloadBtn) return;
        downloadBtn.disabled = cargando;
        downloadBtn.innerHTML = cargando
            ? '<span class="material-icons" style="font-size: 16px; animation: spin 1s linear infinite;">sync</span><span class="btn-label">Descargando...</span>'
            : '<span class="material-icons" style="font-size: 16px;">download</span><span class="btn-label">Descargar</span>';
    };

    const limpio = documentLabel
        ? documentLabel.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '')
        : '';
    let filename = limpio ? limpio + '.pdf' : 'documento.pdf';

    // Descarga con un <a download> apuntando a una URL (blob o directa).
    const downloadViaAnchor = function (href, revoke) {
        const a = document.createElement('a');
        a.href = href;
        a.download = filename;
        a.setAttribute('data-no-spa', 'true');
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        // Quitar el <a> y restaurar el boton rapido; pero si era un blob, NO lo
        // revocamos aun: si el navegador muestra "Guardar como", la descarga no
        // inicia hasta que el usuario confirme — revocar antes la cancelaria.
        setTimeout(function () {
            if (a.parentNode) a.parentNode.removeChild(a);
            pintarBoton(false);
        }, 800);
        if (revoke) setTimeout(function () { URL.revokeObjectURL(href); }, 60000);
    };

    pintarBoton(true);

    _pdfPrepararUnion().then(function (unido) {
        if (unido) {
            // El expediente entero: se marca en el nombre para no confundirlo con el
            // archivo suelto que pueda tener ya guardado.
            if (limpio) filename = limpio + '_completo.pdf';
            downloadViaAnchor(URL.createObjectURL(unido), true);
            return;
        }

        // Un solo documento. Estrategia robusta: traemos el PDF con fetch y lo
        // descargamos como BLOB. Asi SIEMPRE se DESCARGA (guarda el archivo) y NO se
        // ABRE en el visor, aunque el navegador ignore el atributo `download` (lo
        // ignora cuando la URL no es del mismo origen — p.ej. si redirige a Drive).
        // Si el fetch falla (CORS, red), caemos al enlace directo.
        // NO usa window.apiFetch a proposito: la URL puede redirigir a Drive
        // (cross-origin) y las cabeceras que apiFetch agrega (X-Requested-With)
        // convierten esto en una peticion con preflight CORS que el otro dominio
        // rechaza. Aqui va fetch pelado.
        fetch(url, { credentials: 'include', cache: 'force-cache' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
            .then(function (blob) { downloadViaAnchor(URL.createObjectURL(blob), true); })
            .catch(function () { downloadViaAnchor(url, false); });
    });
};

// Imprime SIN descargar. Con correcciones va el expediente unido; con un solo
// documento, la escalera de siempre:
//   1) iframe.contentWindow.print() directo — el visor ya lo tiene pintado.
//   2) fetch -> Blob -> iframe oculto -> print().
//   3) Fallback: abrir en pestana nueva. El usuario imprime con Ctrl+P.
window.printPdfFromPreview = function () {
    const printBtn = document.getElementById('pdfPrintBtn');
    const dlBtn = document.getElementById('pdfDownloadBtn');
    const url = dlBtn ? dlBtn.dataset.url : '';
    if (!url) {
        alert('No hay documento para imprimir.');
        return;
    }

    const setBtnLoading = (loading) => {
        if (!printBtn) return;
        printBtn.disabled = loading;
        printBtn.innerHTML = loading
            ? '<span class="material-icons" style="font-size: 16px; animation: spin 1s linear infinite;">sync</span><span class="btn-label">Preparando...</span>'
            : '<i class="material-icons" style="font-size: 16px;">print</i><span class="btn-label">Imprimir</span>';
    };

    setBtnLoading(true);

    _pdfPrepararUnion().then(function (unido) {
        if (unido) {
            _pdfImprimirBlob(unido, () => setBtnLoading(false));
            return;
        }

        // 1) El iframe abierto ya tiene el documento pintado: es lo mas rapido.
        try {
            const visibleFrame = document.getElementById('pdfPreviewFrame');
            if (visibleFrame && visibleFrame.contentWindow && visibleFrame.style.display !== 'none') {
                visibleFrame.contentWindow.focus();
                visibleFrame.contentWindow.print();
                setBtnLoading(false);
                return;
            }
        } catch (_) { /* cross-origin: cae al fetch */ }

        // 2) Fetch (usa cache HTTP si existe) -> Blob -> iframe oculto.
        // NO usa window.apiFetch, por el mismo motivo que la descarga.
        fetch(url, { credentials: 'include', cache: 'force-cache' })
            .then((r) => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.blob();
            })
            .then((blob) => _pdfImprimirBlob(blob, () => setBtnLoading(false)))
            .catch((err) => {
                console.warn('No se pudo imprimir via fetch:', err);
                setBtnLoading(false);
                // 3) Fallback: nueva pestana — el usuario imprime con Ctrl+P
                const w = window.open(url, '_blank');
                if (!w) {
                    alert('No se pudo imprimir el PDF. El navegador bloqueo el popup. Permite popups e intenta de nuevo, o usa el boton Descargar.');
                }
            });
    });
};

// Temporizador de respaldo del visor (uno SOLO para toda la pantalla, no uno por
// apertura): ver por qué en el setTimeout de más abajo.
let _pdfLoaderTimeout = null;

/* Parametros del visor nativo en modo LECTURA (un solo documento a pantalla
   completa). Estaban escritos a mano en cada sitio que carga el iframe; con la vista
   comparada pasaron a ser dos juegos distintos y repetirlos era pedir que uno se
   quedara atras. El de comparar es PDF_PARAMS_COMPARA, mas abajo. */
const PDF_PARAMS_LECTURA = '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';

// Radio del desenfoque con el que se revela el PDF mientras llega. En UN solo
// sitio: lo ponen la apertura y la asignacion del src, y si los dos valores se
// separaran el documento daria un salto de nitidez al empezar a cargar.
const PDF_BLUR_CARGA = 'blur(14px)';
// Enfocado. Tiene que ser blur(0px) y NO cadena vacia ni 'none': de un blur a
// `none` no hay interpolacion posible, asi que el filtro se quitaria de golpe y se
// perderia justo la transicion que da la sensacion de "termino de llegar".
const PDF_SIN_BLUR = 'blur(0px)';

/* EL DESENFOQUE SOLO APARECE SI LA CARGA SE HACE ESPERAR.
   Igual que el visor de Google Drive: si el documento sale de una, se ve nitido y ya —
   no hay nada que disimular y meter un efecto solo retrasaria poder leerlo. El revelado
   progresivo es para la espera: cuando el archivo tarda, verlo formarse hace la espera
   mas llevadera que un hueco gris.

   Este es el umbral. Si el onload llega antes, la apertura se considera instantanea
   (documento en cache, o red rapida) y se enfoca SIN transicion: no llega a verse
   borroso. Por encima, hubo descarga de verdad y entra el revelado.

   350 ms y no otro numero: el desenfoque tarda 0.3s en entrar del todo (la transicion
   del <iframe>), asi que por debajo de eso el blur ni siquiera llego a su maximo y
   quitarlo de golpe no se nota. */
const PDF_CARGA_LENTA_MS = 350;

/* Margen que se le da al visor nativo, TRAS el onload, para pintar la primera pagina.
   Solo se aplica en la rama lenta. No hay evento que avise de que PDFium pinto: el
   onload solo dice que el archivo termino de descargar, y hasta entonces el iframe esta
   vacio. Sin este margen el enfoque arranca sobre un hueco vacio y cuando aparece el
   documento ya esta nitido — el efecto no se veia.

   Se paga solo cuando la carga ya fue lenta, o sea cuando el usuario esta esperando de
   todos modos; en la rama rapida no cuesta nada. */
const PDF_PINTADO_MS = 200;

/* Handle del enfoque diferido. Vive FUERA de openPdfPreview y cada apertura cancela el
   anterior, por el mismo motivo que _pdfLoaderTimeout: si el usuario cierra el visor
   —o abre otro documento— antes de que salte, ese temporizador caeria encima de la
   apertura SIGUIENTE y le quitaria el desenfoque cuando acababa de ponerselo. */
let _pdfEnfoqueTimeout = null;

/** Momento (performance.now) en que se le puso el src al iframe: mide si tardo o no. */
let _pdfCargaDesde = 0;

window.openPdfPreview = function (url, docType, label, equipoId, uploadUrl, skipMetadata, module) {
    const modal = document.getElementById('pdfPreviewModal');
    const iframe = document.getElementById('pdfPreviewFrame');
    const title = document.getElementById('pdfPreviewTitle');
    const downloadBtn = document.getElementById('pdfDownloadBtn');
    const updateInput = document.getElementById('pdfUpdateInput');
    const loader = document.getElementById('pdfViewerLoader');

    // NADA de preloader global aquí. El #preloader es una capa BLANCA OPACA a
    // z-index 1000000: mientras cargaba el PDF tapaba el modal entero, así que
    // el usuario veía una pantalla en blanco con un spinner y el visor solo
    // "aparecía" al terminar la descarga (+ el buffer de render). De ahí el
    // "el PDF sale rápido, lo que tarda es en mostrarse". Encima duplicaba la
    // espera: este modal ya trae su propio #pdfViewerLoader ("Cargando
    // documento...") justo encima del iframe, que es el que corresponde.
    //
    // El modal se abre sin fundido a propósito: pasar de display:none a flex y
    // de opacity 0 a 1 en el mismo paso no dispara la transición del CSS, y así
    // aparece en el mismo frame del clic. No "arreglar" con requestAnimationFrame
    // sin arreglar antes closePdfPreview, que solo quita .active y no limpiaría
    // un display en línea.
    if (modal) modal.classList.add('active');

    // Show Loader
    if (loader) {
        loader.style.display = 'flex';
        loader.style.opacity = '1';
    }

    if (iframe) {
        iframe.style.opacity = '0';
        // Sin resetear, la apertura SIGUIENTE arrancaria ya enfocada y se perderia
        // el efecto a partir del segundo documento.
        iframe.style.filter = PDF_BLUR_CARGA;
        // 'about:blank' explícito y NO '': la cadena vacía se resuelve contra la
        // URL del documento actual, así que el iframe se ponía a cargar la página
        // entera (/admin/equipos y sus ~1.200 filas) hasta que el src del PDF la
        // reemplazaba. Trabajo tirado justo en el instante que se quiere rápido.
        iframe.src = 'about:blank';
    }

    const fallbackNode = document.getElementById('pdfMobileFallback');
    if (fallbackNode) fallbackNode.style.display = 'none';

    // Set Content
    if (title) title.innerText = label || 'Documento';
    const printBtn = document.getElementById('pdfPrintBtn');
    const showActions = !!url && url.length >= 5;
    if (downloadBtn) {
        downloadBtn.dataset.url = url;
        downloadBtn.dataset.label = label || 'documento';
        downloadBtn.style.display = showActions ? 'flex' : 'none';
    }
    if (printBtn) {
        printBtn.style.display = showActions ? 'flex' : 'none';
    }

    // Botones "Subir/reemplazar" (pdfUpdateLabel) y "Eliminar" (pdfDeleteBtn):
    // pertenecen al flujo de gestion documental de equipos (Drive + BD). NO aplican
    // a PDFs que el backend genera en vivo con TCPDF (Nota de Entrega del almacen,
    // Reporte de Fallas, etc.). Deteccion: si NO viene un uploadUrl NI un equipoId,
    // este preview es "solo lectura" -> ocultamos ambos. El gate por permisos del
    // Blade (la directiva can/super.admin) ya pudo no haberlos renderizado; aqui
    // solo nos aseguramos de no mostrarlos cuando el documento no es gestionable.
    const docGestionable = !!uploadUrl || !!equipoId;
    const updateLabel = document.getElementById('pdfUpdateLabel');
    const deleteBtn   = document.getElementById('pdfDeleteBtn');
    if (updateLabel) updateLabel.style.display = docGestionable ? 'flex' : 'none';
    if (deleteBtn)   deleteBtn.style.display   = docGestionable ? 'flex' : 'none';

    // Respaldo: si el onload del PDF no llega nunca, a los 5 s se destapa igual.
    //
    // El handle vive FUERA de esta función y cada apertura cancela el anterior.
    // Siendo local, una apertura que quedaba a medias —el usuario cierra el modal
    // antes de que cargue, o el documento no tiene URL— dejaba su temporizador
    // armado, y 5 s después caía encima de la apertura SIGUIENTE: le apagaba el
    // "Cargando documento..." y destapaba su iframe a medio cargar.
    clearTimeout(_pdfLoaderTimeout);
    // Y el enfoque diferido de una apertura anterior, que si no le quitaria el
    // desenfoque a ESTA en cuanto saltara.
    clearTimeout(_pdfEnfoqueTimeout);
    _pdfLoaderTimeout = setTimeout(() => {
        if (loader) loader.style.display = 'none';
        // Tambien enfoca: si no, un onload que no llega dejaria el documento
        // borroso para siempre, que es peor que la espera que este respaldo evita.
        if (iframe) { iframe.style.opacity = '1'; iframe.style.filter = PDF_SIN_BLUR; }
    }, 5000);

    // Apaga el loader y destapa el PDF. Se llama una sola vez, desde el onload
    // del iframe.
    //
    // No apaga de golpe: le baja la opacidad y lo quita 200 ms despues, para que no
    // desaparezca de un tiron.
    //
    // OJO con el motivo: aqui decia que durante ese fundido "el loader sigue
    // TAPANDO el iframe". No es cierto, y no lo era antes tampoco —
    // #pdfViewerLoader es un spinner con texto, SIN fondo: nunca tapo nada. Lo
    // que cubria el hueco que el visor nativo necesita para pintar la primera
    // pagina era el propio iframe, subiendo de opacity 0 a 1 con su transicion.
    // Hoy ese colchon es la transicion del DESENFOQUE (0.5s): el documento ya se
    // ve, y termina de enfocarse mientras el visor acaba de pintar.
    //
    // Aquí hubo además un "mínimo que el loader permanece visible" de 250 ms.
    // Se quitó porque NO podía ejecutarse: se medía desde el inicio de la
    // apertura, y con la espera fija de por medio el mínimo ya estaba cumplido
    // siempre. El suelo real contra el parpadeo es el fundido de 200 ms.
    const hideLoaderWhenReady = () => {
        clearTimeout(_pdfLoaderTimeout);

        const apagarLoader = () => {
            if (!loader) return;
            loader.style.opacity = '0';
            setTimeout(() => { if (loader) loader.style.display = 'none'; }, 200);
        };

        // ── ¿Salio de una, o hubo que esperarlo? ────────────────────────────────
        const tardo = performance.now() - _pdfCargaDesde;

        if (tardo < PDF_CARGA_LENTA_MS) {
            // INSTANTANEO. No hay espera que disimular, asi que el documento se enseña
            // nitido cuanto antes: se quita el desenfoque SIN transicion —apagandola un
            // instante— para que no se vea aclararse. Con el blur a medio entrar (0.3s)
            // el corte es imperceptible, y se ahorran el margen y la transicion enteros.
            apagarLoader();
            if (!iframe) return;
            const suave = iframe.style.transition;
            iframe.style.transition = 'none';
            iframe.style.opacity = '1';
            iframe.style.filter = PDF_SIN_BLUR;
            void iframe.offsetHeight;              // aplica el corte antes de devolverle la transicion
            iframe.style.transition = suave;
            return;
        }

        // LENTO: el usuario ya estuvo esperando. Aqui si compensa el revelado — ver el
        // documento formandose hace la espera mas llevadera que un hueco gris.
        //
        // Se le da a PDFium su margen antes de tocar nada: el onload dice que el archivo
        // llego, no que la pagina este pintada, y desenfocar un hueco vacio no se ve. Sin
        // esto el enfoque corria sobre el vacio y el documento aparecia ya nitido.
        //
        // El spinner se apaga tambien ahi, no antes: quitarlo con el iframe todavia vacio
        // deja "modal abierto + sin spinner + gris", un fallo que ya paso.
        clearTimeout(_pdfEnfoqueTimeout);
        _pdfEnfoqueTimeout = setTimeout(() => {
            apagarLoader();
            if (!iframe) return;
            iframe.style.opacity = '1';
            // Enfoca lo que ya se esta viendo borroso. La transicion del CSS es la que da
            // la sensacion de "termino de llegar".
            iframe.style.filter = PDF_SIN_BLUR;
        }, PDF_PINTADO_MS);
    };

    // Set source and setup load listener
    if (iframe) {
        iframe.onload = function () {
            // FILTRO ANTI-SPURIOUS: el iframe.src = 'about:blank' de más
            // arriba dispara un evento load asincrono ANTES de que cargue
            // el PDF real. Sin este filtro, el handler
            // se ejecuta para about:blank y oculta el spinner antes
            // de que el PDF empiece siquiera a cargar — el bug que
            // mostraba "modal abierto + sin spinner + gris + PDF
            // tarde". Ignoramos cualquier load que no sea del PDF.
            const src = this.src || '';
            if (!src || src === 'about:blank' || src.indexOf('about:blank') !== -1) {
                return;
            }

            // El onload llega cuando el RECURSO termino de descargar, no cuando el
            // visor nativo pinto la pagina. Ese hueco lo gestiona
            // hideLoaderWhenReady con PDF_PINTADO_MS: aqui no se espera nada, para
            // que la espera viva en UN solo sitio y no se sumen dos.
            // (_pdfLoaderTimeout, los 5 s de respaldo, sigue cubriendo el caso
            // de que onload no llegue nunca.)
            hideLoaderWhenReady();
        };

        iframe.onerror = function () {
            clearTimeout(_pdfLoaderTimeout);
            clearTimeout(_pdfEnfoqueTimeout);
            if (loader) loader.style.display = 'none';
            // Volver a taparlo. Desde que la carga se revela desenfocada, el iframe
            // esta VISIBLE en cuanto se le pone el src: si falla, sin esto quedaria
            // una mancha borrosa detras del modal de error. Antes no hacia falta
            // porque seguia en opacity:0 hasta el onload.
            iframe.style.opacity = '0';
            iframe.style.filter = '';
            showModal({
                type: 'error',
                title: 'Error',
                message: 'No se pudo cargar la vista previa del documento.',
                confirmText: 'Cerrar',
                hideCancel: true
            });
        };

        if (url && url.length > 5) {
            const fallback = document.getElementById('pdfMobileFallback');
            if (fallback) fallback.style.display = 'none';
            iframe.style.display = 'block';
            // REVELADO PROGRESIVO. Antes el iframe estaba en opacity:0 hasta el
            // onload, asi que el usuario miraba gris + spinner y el documento
            // aparecia de golpe al final. Ahora se destapa DESDE YA, desenfocado:
            // el visor nativo pinta la primera pagina progresivamente y esa mancha
            // que se va formando se ve mucho antes que el documento terminado.
            // No carga mas rapido — se PERCIBE mas rapido, que era lo pedido.
            //
            // El desenfoque no es decorativo: tapa el estado a medio pintar (media
            // pagina, texto sin fuentes) que de otro modo se veria como un fallo.
            // El spinner sigue encima hasta el onload, ahora sobre el documento
            // formandose en vez de sobre un gris vacio.
            iframe.style.filter = PDF_BLUR_CARGA;
            iframe.style.opacity = '1';

            // Desde aqui se mide cuanto tarda la carga, que es lo que decide si el
            // desenfoque llega a verse o el documento sale nitido de una
            // (ver PDF_CARGA_LENTA_MS).
            _pdfCargaDesde = performance.now();
            iframe.src = url + PDF_PARAMS_LECTURA;
        } else {
            const fallback = document.getElementById('pdfMobileFallback');
            if (fallback) fallback.style.display = 'none';

            iframe.style.display = 'block';
            iframe.src = 'about:blank';
            if (loader) loader.style.display = 'none';
        }
    }

    // Setup Update Input
    if (updateInput) {
        updateInput.onchange = function () {
            uploadDocumentFromPreview(this, docType, equipoId, label);
        };
    }

    // Store current context for metadata panel
    // module: 'equipo' (default) | 'auxiliar'. Determina si load/save
    // metadata pegan a /admin/equipos/.. o a /admin/equipos-auxiliares/..
    window.currentPdfContext = { equipoId, docType, label, uploadUrl, module: module || 'equipo' };

    // Pestanas de correcciones anexas (ver bloque _pdfPintarAnexos).
    // Va DESPUES de currentPdfContext y de haber decidido que botones se
    // ven: viendo una correccion esconde Reemplazar (no hay endpoint para
    // sustituirla) y deja Eliminar, que ahi borra ESA correccion.
    if (typeof window._pdfAdmiteAnexos === 'function') {
        window._pdfAnexarInit();
        if (window._pdfAdmiteAnexos(module, equipoId, docType)) {
            // Si lo que se abre NO es el mismo equipo+tipo que hay pintado, la barra
            // de antes deja de valer YA, no cuando vuelva el fetch. Sin esto quedaba
            // una ventana —real, porque cargarAnexosEquipo sale a la red cuando el
            // equipo no esta en cache— en la que la barra seguia mostrando las
            // pestanas del documento ANTERIOR y _pdfAnexoCtx seguia apuntando a su
            // equipo y su tipo, con el boton "Anexar correccion" ya enganchado por
            // _pdfAnexarInit(): pulsarlo ahi subia la correccion al equipo
            // equivocado y al tipo de documento equivocado.
            // _pdfOcultarAnexos() deja _pdfAnexoCtx en null, y el handler del boton
            // sale con `if (!file || !ctx) return;`, asi que durante la espera no se
            // puede anexar a nada.
            //
            // La guarda de "mismo equipo+tipo" NO es opcional: pinchar una pestana de
            // correccion vuelve a entrar por aqui con el mismo equipo y tipo, y limpiar
            // tambien en ese caso romperia la herencia deliberada del principal
            // (ver _pdfPintarAnexos) — el principal pasaria a ser la correccion abierta.
            // Es el mismo test que usa _pdfPintarAnexos para decidir si hereda.
            const _ctxPrevio = window._pdfAnexoCtx;
            const _mismoDoc  = _ctxPrevio
                && String(_ctxPrevio.equipoId) === String(equipoId)
                && _ctxPrevio.tipo === docType;
            if (!_mismoDoc) window._pdfOcultarAnexos();

            // usarCache: el detalle ya las refresco al abrirse; aqui no
            // hace falta otra ida al servidor.
            window.cargarAnexosEquipo(equipoId, true)
                  .then(() => {
                      // La respuesta puede llegar TARDE, cuando el visor ya esta enseñando
                      // otro documento: cargarAnexosEquipo sale a la red siempre que el
                      // equipo no este en cache (en /admin/historial-documentos no lo esta
                      // nunca). Abrir la poliza del equipo A, cerrar y abrir enseguida la
                      // propiedad del B hacia que, si la de A contestaba despues, se
                      // pintaran las pestañas de A encima del PDF de B — y _pdfPintarAnexos
                      // deja _pdfAnexoCtx apuntando a A, asi que "Anexar correccion" subia
                      // el archivo AL EQUIPO EQUIVOCADO. El _pdfOcultarAnexos() de arriba
                      // solo tapa la ventana ANTES de la respuesta, no esta.
                      //
                      // currentPdfContext es el documento que se esta viendo AHORA: lo
                      // reescribe cada openPdfPreview (incluido el de pinchar una pestaña,
                      // que reentra con el mismo equipo y tipo, de modo que ese caso sigue
                      // pasando el guard). Si ya no coincide, esta respuesta es de un visor
                      // que el usuario dejo atras y no debe pintar nada.
                      const ctx = window.currentPdfContext;
                      if (!ctx
                          || String(ctx.equipoId) !== String(equipoId)
                          || ctx.docType !== docType) return;
                      window._pdfPintarAnexos(url, docType, equipoId, label);
                  });
        } else {
            window._pdfOcultarAnexos();
        }
    }

    // Auto-open metadata panel on desktop only (no ocultar el PDF en móviles)
    // Si skipMetadata=true (modulos no equipos como auxiliares), el panel
    // queda colapsado y no se llama loadMetadata para evitar mostrar campos
    // de la tabla equivocada.
    const panel = document.getElementById('pdfMetadataPanel');
    if (panel) {
        panel.style.width = '0';
        if (!skipMetadata) {
            setTimeout(() => {
                const isMobile = window.innerWidth <= 768;
                if (!isMobile) {
                    panel.style.width = '300px';
                    loadMetadata();
                }
            }, 400);
        }
    }
};


/* ── CORRECCIONES ANEXAS ────────────────────────────────────────────
   Un documento y sus correcciones estan los DOS vigentes: no es un
   historial de versiones. Por eso salen en pestanas a la vista dentro
   del propio visor y no en un panel aparte ni en otro modal encima
   (el sistema ya arrastra bastantes modales apilados).

   Cambiar de pestana vuelve a entrar por openPdfPreview con el enlace
   de la correccion: asi se reaprovechan tal cual el loader, el
   desenfoque de carga y el respaldo de los 5 s, y los botones de
   Descargar e Imprimir siguen al documento que se esta viendo sin una
   linea de codigo extra. */

// Anexos ya traidos, por equipo. Se refrescan al abrir el detalle.
window._anexosPorEquipo = window._anexosPorEquipo || {};

// Que se esta viendo ahora: de que documento principal cuelgan las
// pestanas y cual esta activa. Sin esto, al pinchar una correccion se
// perderia cual era el principal y la pestana no sabria volver.
window._pdfAnexoCtx = null;

// apiFetch (dom_helpers.js) mete la sesion y el manejo de 419. El
// respaldo va envuelto a proposito: `window.fetch` suelto pierde su
// `this` y revienta con "Illegal invocation".
const _pedirAnexos = (url, opts) =>
    (window.apiFetch ? window.apiFetch(url, opts) : fetch(url, opts));

const _escAnexo = (v) => (window.escapeHtml ? window.escapeHtml(v) : String(v == null ? '' : v));

// Los tipos de documento que ADMITEN correcciones anexas. No son los 6 que acepta
// el backend (DOC_COLUMNAS en EquipoController): solo Propiedad y Poliza se corrigen
// en la practica. ROTC, RACDA, Certificado Asociado y Compraventa se reemplazan
// enteros cuando cambian, asi que ofrecer "Anexar correccion" ahi era ruido.
//
// Otras vistas abren el visor con claves suyas ('nota_entrega', 'falla',
// 'creacion'...): ahi tampoco hay nada que anexar.
const _TIPOS_CON_ANEXOS = ['propiedad', 'poliza'];

/**
 * Si este documento admite correcciones anexas. UN solo sitio lo decide,
 * y lo consultan tanto la apertura del visor como el pintado.
 *
 * El modulo importa: los AUXILIARES abren el visor pasando su propio id
 * en el hueco del equipo, y 156 de los 168 auxiliares tienen un id que
 * tambien existe como ID_EQUIPO. Sin este filtro se pedian —y se
 * ofrecia anexar a— las correcciones de otro equipo distinto.
 */
window._pdfAdmiteAnexos = (module, equipoId, docType) =>
    (module || 'equipo') === 'equipo' &&
    !!equipoId && !!docType && _TIPOS_CON_ANEXOS.indexOf(docType) !== -1;

/**
 * La corrección que se está viendo ahora mismo, o null si es el documento principal.
 *
 * Se deduce de _pdfAnexoCtx —'activo' lo reescribe _pdfPintarAnexos en cada cambio de
 * pestaña— en vez de llevar una variable aparte: dos estados que digan lo mismo acaban
 * descompasados el dia que alguien abra el visor por un camino nuevo.
 */
window._pdfCorreccionAbierta = function () {
    const ctx = window._pdfAnexoCtx;
    if (!ctx || !ctx.activo || ctx.activo === ctx.principal) return null;

    const lista = ((window._anexosPorEquipo[ctx.equipoId] || {})[ctx.tipo]) || [];
    return lista.find(a => a.link === ctx.activo) || null;
};

/* ── VISTA COMPARADA ─────────────────────────────────────────────────────────────
   Original a la izquierda y la corrección elegida a la derecha, para no tener que ir
   y venir entre pestañas comprobando qué cambió.

   Se ofrece SOLO cuando hay al menos una corrección (sin ella no hay nada que
   comparar) y SOLO desde 900 px de ancho: partir una pantalla estrecha en dos deja
   dos documentos ilegibles en vez de uno legible.

   El iframe de la derecha nace sin src y se vacía al apagar: un PDF cargado ocupa
   memoria aunque su panel esté oculto. */
const PDF_COMPARA_ANCHO_MIN = 900;

/* Parametros del visor nativo para la vista comparada.
   'view=Fit' encaja la HOJA ENTERA en el panel; con el zoom=100 del visor normal, en
   media pantalla solo se veria la esquina superior del documento y compararlos exigiria
   hacer scroll en los dos a la vez, que es peor que las pestañas que esto vino a
   quitar. Es el punto de partida de los dos lados; desde ahi la lupa acerca el suyo
   (ver PDF_COMPARA_ZOOMS). */
const PDF_PARAMS_COMPARA = '#toolbar=0&navpanes=0&scrollbar=0&view=Fit';

/** El anexo que se ve a la DERECHA (lo fija _pdfComparaMostrar). Entero y no solo su
    enlace: el boton de borrar de ese panel necesita ademas su id y su etiqueta. */
window._pdfComparaAnexoDer = null;

window._pdfComparando = false;

/**
 * Decide si la barra de pestañas se ve. UN SOLO SITIO: lo llaman el pintado, el
 * encendido y el apagado de la comparacion, y el desmontaje.
 *
 * Hace falta cuando hay algo que ELEGIR:
 *   · sin correcciones no hay nada que elegir;
 *   · comparando con UNA correccion tampoco: los dos documentos ya estan en pantalla
 *     y cada hoja lleva su rotulo, asi que la barra repetiria los mismos dos nombres;
 *   · comparando con DOS O MAS si: es la unica forma de mandar otra correccion al
 *     panel derecho, y de pulsar "Original" para volver a ver un solo documento.
 *     Escondiendola siempre, la segunda correccion no se podia abrir de ninguna
 *     manera y tampoco habia salida de la vista partida.
 *
 * Se mira la LISTA y no las pestañas pintadas: al cambiar de documento las de antes
 * siguen en el DOM hasta que llega la respuesta del servidor, y contarlas hacia
 * asomar por un momento las pestañas del documento anterior.
 */
window._pdfSincronizarBarraPestanas = function () {
    const barra = document.getElementById('pdfAnexosBar');
    if (!barra) return;
    const ctx   = window._pdfAnexoCtx;
    const lista = ctx
        ? ((((window._anexosPorEquipo || {})[ctx.equipoId]) || {})[ctx.tipo] || [])
        : [];
    const haceFalta = lista.length > 0 && (!window._pdfComparando || lista.length > 1);
    barra.style.display = haceFalta ? 'flex' : 'none';
};

/** Resalta en las pestañas cuál se está viendo a cada lado. */
const _pdfComparaMarcarChips = function (linkDerecha) {
    const ctx = window._pdfAnexoCtx;
    if (!ctx) return;
    document.querySelectorAll('#pdfAnexosTabs [data-anexo-link]').forEach(b => {
        const link = b.getAttribute('data-anexo-link');
        const esIzq = link === ctx.principal;
        const esDer = link === linkDerecha;
        b.style.borderColor = (esIzq || esDer) ? '#3b82f6' : '#374151';
        b.style.background  = (esIzq || esDer) ? '#1e3a5f' : 'transparent';
        b.style.color       = (esIzq || esDer) ? '#fff' : '#9ca3af';
    });
};

/** Carga una corrección en el panel derecho. */
window._pdfComparaMostrar = function (anexo) {
    const panel = document.getElementById('pdfComparaPanel');
    const frame = document.getElementById('pdfComparaFrame');
    // El TEXTO, no la barra: la barra lleva ademas la lupa, y escribirle textContent
    // se la llevaria por delante.
    const rotDer = document.getElementById('pdfComparaTituloDer');
    if (!panel || !frame || !anexo) return;

    if (rotDer) rotDer.textContent = anexo.etiqueta || 'Anexo de corrección';
    frame.src = anexo.link + PDF_PARAMS_COMPARA;
    _pdfComparaMarcarChips(anexo.link);

    // El enlace LIMPIO del lado derecho: la lupa rearma la URL entera desde aqui para
    // cambiarle el zoom, y frame.src ya vendria con los parametros pegados.
    window._pdfComparaAnexoDer = anexo;
    // Documento nuevo a la derecha: vuelve al tamaño natural (estado Y css).
    _pdfComparaResetZoom(true);
    window._pdfComparaSincronizarLupas();
};

/**
 * Enciende la vista comparada con la corrección indicada. NO recarga el lado izquierdo:
 * se llama cuando el principal ya está puesto ahí.
 */
window._pdfComparaEncender = function (anexo) {
    const panel  = document.getElementById('pdfComparaPanel');
    const rotIzq = document.getElementById('pdfComparaEtiquetaIzq');
    const ctx    = window._pdfAnexoCtx;
    if (!panel || !anexo || !ctx) return;

    window._pdfComparando = true;
    panel.style.display = 'block';
    if (rotIzq) rotIzq.style.display = 'flex';

    // Con UNA correccion las pestañas sobran —cada hoja ya lleva su rotulo encima— y la
    // barra se esconde entera, porque es su unico contenido y dejarla puesta solo aporta
    // una franja vacia. Con dos o mas se queda: es la unica forma de elegir cual va a la
    // derecha. Lo decide _pdfSincronizarBarraPestanas.
    window._pdfSincronizarBarraPestanas();

    // Y el boton de borrar de la CABECERA se esconde: con los dos documentos en pantalla
    // no hay forma de saber a cual se refiere —de hecho borraba el original aunque se
    // estuviera leyendo la correccion—. Cada panel tiene ya el suyo, que si dice cual es.
    // Se guarda como marca en el propio nodo para poder devolverlo al apagar sin adivinar
    // si estaba visible o si el documento no era gestionable.
    const delCab = document.getElementById('pdfDeleteBtn');
    if (delCab && delCab.dataset.ocultoPorCompara !== '1') {
        delCab.dataset.ocultoPorCompara = delCab.style.display === 'none' ? 'no' : '1';
        if (delCab.dataset.ocultoPorCompara === '1') delCab.style.display = 'none';
    }

    // El izquierdo se abrio al 100% de zoom (el modo lectura de un solo documento).
    // Comparando hace falta la hoja entera, asi que se le cambian los parametros. Es
    // una re-navegacion, pero el archivo acaba de descargarse y sale del cache del
    // navegador: no hay segunda descarga.
    // getAttribute y no .src: la propiedad devuelve la URL ABSOLUTA ya resuelta, asi
    // que compararla contra una ruta relativa da SIEMPRE distinto y re-navegaria de
    // balde cada vez que se repinte la barra.
    const izq = document.getElementById('pdfPreviewFrame');
    const destinoIzq = ctx.principal + PDF_PARAMS_COMPARA;
    if (izq && izq.getAttribute('src') !== destinoIzq) izq.src = destinoIzq;

    window._pdfComparaMostrar(anexo);
    // La comparacion arranca con los DOS lados a tamaño natural.
    _pdfComparaResetZoom();
    window._pdfComparaSincronizarLupas();
};

/* Niveles de la lupa, como FACTOR de escala: 1 es el tamaño con el que se abre la
   comparacion (la hoja entera, view=Fit) y de ahi se acerca.

   La lista NO da la vuelta: cada lado tiene su boton de alejar y el de acercar, asi
   que se topa en el primero y en el ultimo y el boton correspondiente se apaga. Antes
   era un boton unico que ciclaba, y para retroceder un nivel habia que recorrer la
   escala entera.

   Son factores porque el zoom se aplica multiplicando el tamaño del iframe, no
   pidiendoselo al visor por la URL (ver pdfComparaZoom). */
const PDF_COMPARA_ZOOMS = [1, 1.25, 1.5, 2, 2.5, 3];

/** Nivel actual de cada lado (indice dentro de PDF_COMPARA_ZOOMS). */
window._pdfComparaZoom = { izq: 0, der: 0 };

/* Tamaño del hueco de cada lado MEDIDO A TAMAÑO NATURAL, o sea sin barras de
   desplazamiento. Es el tamaño que conserva el iframe en todos los niveles: si se
   remidiera en cada acercamiento, las barras ya puestas descontarian sus 12 px, el
   iframe encogeria un poco y el visor nativo recompondria la hoja — justo el salto
   que se vino a quitar. Se borra al volver a 1 y al cambiar el tamaño de la ventana. */
const _pdfZoomBase = { izq: null, der: null };

/** El iframe y la capa con barras de cada lado. */
const _pdfComparaFrameDe = (lado) => document.getElementById(
    lado === 'izq' ? 'pdfPreviewFrame' : 'pdfComparaFrame'
);
const _pdfComparaScrollDe = (lado) => document.getElementById(
    lado === 'izq' ? 'pdfZoomScrollIzq' : 'pdfZoomScrollDer'
);

/**
 * Acerca o aleja el documento DE SU PROPIO LADO, un nivel por pulsacion.
 * direccion: 1 acerca, -1 aleja. En los topes no hace nada (el boton ya esta apagado).
 *
 * Antes esta lupa escondia el panel de enfrente para dejar una sola hoja a todo el ancho,
 * y desde fuera se leia como "cambiar de un PDF al otro" en vez de como acercar: los dos
 * documentos siguen a la vista y solo cambia el tamaño del suyo.
 *
 * EL ZOOM LO HACE EL VISOR, NO EL CSS: el iframe se AGRANDA y el visor nativo vuelve a
 * encajar la hoja (view=Fit) en el hueco nuevo, o sea que la redibuja a resolucion real.
 *
 * Los dos intentos anteriores fueron por CSS y los dos fallaron. Encoger el iframe a
 * 1/escala y escalarlo con transform desde la esquina recolocaba la hoja hacia el centro
 * (al cambiar el ancho de maquetacion el visor recompone) y el documento pegaba un salto.
 * Escalarlo desde el centro sin tocarle el tamano quitaba el salto pero lo dejaba BORROSO:
 * el visor seguia dibujando al tamano chico y el compositor estiraba ese mapa de bits.
 *
 * Agrandandolo no hay transform de por medio: el plugin dibuja de nuevo, nitido, y la capa
 * .pdf-zoom-scroll pone las barras para recorrer lo que ya no cabe. Que el visor re-encaja
 * al cambiar de tamano se ve cada vez que se enciende la comparacion: el mismo iframe pasa
 * a la mitad de ancho y la hoja se re-ajusta sola.
 */
window.pdfComparaZoom = function (lado, direccion) {
    if (!window._pdfComparando) return;

    const paso = direccion < 0 ? -1 : 1;
    const destino = (window._pdfComparaZoom[lado] || 0) + paso;
    if (destino < 0 || destino >= PDF_COMPARA_ZOOMS.length) return;

    window._pdfComparaZoom[lado] = destino;
    _pdfComparaAplicarZoom(lado, PDF_COMPARA_ZOOMS[destino]);

    window._pdfComparaSincronizarLupas();
};

/**
 * Devuelve los dos lados a tamaño natural: el estado Y el CSS.
 *
 * Las dos cosas juntas a proposito. Poner el contador a 0 sin limpiar el transform dejaba
 * el iframe escalado con la lupa diciendo que estaba al 100%, y el acercamiento se
 * arrastraba a la comparacion siguiente o al documento nuevo de la derecha.
 */
const _pdfComparaResetZoom = function (soloDerecha) {
    const lados = soloDerecha ? ['der'] : ['izq', 'der'];
    lados.forEach((lado) => {
        window._pdfComparaZoom[lado] = 0;
        _pdfComparaAplicarZoom(lado, 1);
    });
};

/**
 * Acerca un lado agrandando su iframe, y deja el punto de mira donde estaba.
 *
 * El tamano base es el hueco del panel medido SIN barras; a partir de ahi cada nivel es
 * base x escala. La capa .pdf-zoom-scroll se encarga del desplazamiento.
 */
const _pdfComparaAplicarZoom = function (lado, escala) {
    const frame = _pdfComparaFrameDe(lado);
    const capa  = _pdfComparaScrollDe(lado);
    if (!frame || !capa) return;

    // Donde estaba mirando, en proporcion del documento (0..1), ANTES de tocar nada:
    // asi el acercamiento sale del centro de lo que se ve y no del principio de la hoja.
    const anchoPrevio = frame.offsetWidth  || capa.clientWidth  || 1;
    const altoPrevio  = frame.offsetHeight || capa.clientHeight || 1;
    const mirandoX = (capa.scrollLeft + capa.clientWidth  / 2) / anchoPrevio;
    const mirandoY = (capa.scrollTop  + capa.clientHeight / 2) / altoPrevio;

    if (escala === 1) {
        _pdfZoomBase[lado] = null;
        // '100%' explicito y NO cadena vacia: el tamano del iframe viene de su style en
        // linea, asi que vaciarlo lo dejaria en el tamano por defecto de un <iframe>.
        frame.style.width = '100%';
        frame.style.height = '100%';
        capa.scrollLeft = 0;
        capa.scrollTop = 0;
        return;
    }

    // Se mide una sola vez por acercamiento, estando aun a tamano natural (sin barras).
    if (!_pdfZoomBase[lado]) {
        _pdfZoomBase[lado] = { w: capa.clientWidth, h: capa.clientHeight };
    }
    const base = _pdfZoomBase[lado];
    if (!base.w || !base.h) return;

    const anchoNuevo = Math.round(base.w * escala);
    const altoNuevo  = Math.round(base.h * escala);
    frame.style.width = anchoNuevo + 'px';
    frame.style.height = altoNuevo + 'px';

    capa.scrollLeft = Math.max(0, mirandoX * anchoNuevo - capa.clientWidth / 2);
    capa.scrollTop  = Math.max(0, mirandoY * altoNuevo  - capa.clientHeight / 2);
};

/** Estado de los cuatro botones segun el nivel al que este cada lado. */
window._pdfComparaSincronizarLupas = function () {
    [['izq', 'pdfZoomMenosIzq', 'pdfZoomMasIzq'],
     ['der', 'pdfZoomMenosDer', 'pdfZoomMasDer']].forEach(([lado, idMenos, idMas]) => {
        const idx = window._pdfComparaZoom[lado] || 0;
        const pct = Math.round(PDF_COMPARA_ZOOMS[idx] * 100) + '%';
        const menos = document.getElementById(idMenos);
        const mas   = document.getElementById(idMas);

        if (menos) {
            menos.disabled = idx === 0;
            menos.title = idx === 0
                ? 'Ya está al tamaño normal'
                : 'Alejar este documento (ahora al ' + pct + ')';
        }
        if (mas) {
            const tope = idx === PDF_COMPARA_ZOOMS.length - 1;
            mas.disabled = tope;
            mas.title = tope
                ? 'Máximo acercamiento (' + pct + ')'
                : 'Acercar este documento' + (idx ? ' (ahora al ' + pct + ')' : '');
        }
    });
};

window.pdfCompararToggle = function () {
    const ctx = window._pdfAnexoCtx;
    const panel = document.getElementById('pdfComparaPanel');
    if (!ctx || !panel) return;

    // ── Apagar ── (el desmontaje vive en _pdfComparaApagar, que usan tambien el
    // cierre del visor y el cambio de documento; aqui solo se repintan las pestañas)
    if (window._pdfComparando) {
        window._pdfComparaApagar();
        _pdfComparaMarcarChips(ctx.activo);

        // Vuelve al modo lectura de un solo documento (zoom 100, como lo abre
        // openPdfPreview). Solo aqui: apagar por cierre del visor o por cambio de
        // documento ya reemplaza el iframe, y re-navegarlo seria trabajo de balde.
        const izqLectura = document.getElementById('pdfPreviewFrame');
        const destinoLectura = ctx.principal + PDF_PARAMS_LECTURA;
        if (izqLectura && izqLectura.getAttribute('src') !== destinoLectura) {
            izqLectura.src = destinoLectura;
        }
        return;
    }

    // ── Encender ──
    const lista = ((window._anexosPorEquipo[ctx.equipoId] || {})[ctx.tipo]) || [];
    if (!lista.length) return;

    // A la izquierda SIEMPRE el original: comparar dos correcciones entre si dejaria
    // el rotulo "Original" mintiendo. Si se estaba viendo una correccion se recarga el
    // principal, y al volver por _pdfPintarAnexos la comparacion se enciende sola con
    // ESTA misma correccion, que la recuerda de ctx.activo.
    const correccion = lista.find(a => a.link === ctx.activo) || lista[0];

    if (ctx.activo !== ctx.principal) {
        window.openPdfPreview(ctx.principal, ctx.tipo, ctx.label, ctx.equipoId, null, true, 'equipo');
        return;
    }

    window._pdfComparaEncender(correccion);
};

/** Al cerrar el visor o abrir otro documento, la comparación no sobrevive. */
window._pdfComparaApagar = function () {
    if (!window._pdfComparando) return;
    window._pdfComparando = false;

    // Los niveles vuelven a cero —estado y css— para que la proxima comparacion no
    // herede el acercamiento de la anterior.
    _pdfComparaResetZoom();
    // Y el anexo del panel derecho: si no, su boton de borrar seguiria apuntando a una
    // correccion que ya no esta en pantalla.
    window._pdfComparaAnexoDer = null;
    // El visor izquierdo se repone SIEMPRE. Ya no hay nada que lo esconda —la lupa dejo
    // de tapar el panel de enfrente— pero dejarlo aqui cuesta una linea y cubre que
    // alguien lo haya ocultado por otra via; sin esto el documento siguiente se abriria
    // sobre una pantalla en blanco.
    const izqPanel = document.getElementById('pdfVisorIzq');
    if (izqPanel) izqPanel.style.display = 'flex';
    const panel = document.getElementById('pdfComparaPanel');
    const frame = document.getElementById('pdfComparaFrame');
    const rotIzq = document.getElementById('pdfComparaEtiquetaIzq');
    if (panel) panel.style.display = 'none';
    if (frame) frame.src = 'about:blank';
    if (rotIzq) rotIzq.style.display = 'none';

    // Vuelve la barra con sus pestañas: fuera de la comparacion son la unica forma de
    // saltar de una correccion a otra.
    window._pdfSincronizarBarraPestanas();

    // Y el boton de borrar de la cabecera vuelve SOLO si lo escondio el encendido: si ya
    // estaba oculto porque el documento no es gestionable, se queda como estaba.
    const delCab = document.getElementById('pdfDeleteBtn');
    if (delCab) {
        if (delCab.dataset.ocultoPorCompara === '1') delCab.style.display = 'flex';
        delete delCab.dataset.ocultoPorCompara;
    }
};

// Encoger la ventana por debajo del minimo con la comparacion puesta dejaria dos
// documentos ilegibles: se apaga sola y el boton desaparece hasta que vuelva a caber.
if (!window._pdfComparaResizeBound) {
    window._pdfComparaResizeBound = true;
    let _pdfComparaResizeTimer = null;
    window.addEventListener('resize', function () {
        if (!window._pdfComparando) return;
        if (window.innerWidth < PDF_COMPARA_ANCHO_MIN) {
            window._pdfComparaApagar();
            return;
        }
        // Los paneles cambiaron de tamaño, asi que la medida base del zoom quedo vieja
        // y el iframe con el tamaño de antes. Se vuelve a tamaño natural —que borra la
        // medida— y se re-aplica el nivel de cada lado, que la toma de nuevo.
        clearTimeout(_pdfComparaResizeTimer);
        _pdfComparaResizeTimer = setTimeout(function () {
            ['izq', 'der'].forEach(function (lado) {
                const idx = window._pdfComparaZoom[lado] || 0;
                if (!idx) return;
                _pdfComparaAplicarZoom(lado, 1);
                _pdfComparaAplicarZoom(lado, PDF_COMPARA_ZOOMS[idx]);
            });
        }, 120);
    });
}

window._pdfOcultarAnexos = function () {
    // El contexto y las pestañas se van PRIMERO. Al reves, el apagado de la comparacion
    // volvia a mostrar la barra con las pestañas del documento ANTERIOR, que seguian en
    // el DOM: al abrir otro documento asomaban por un momento hasta que llegaba la
    // respuesta del servidor.
    window._pdfAnexoCtx = null;
    const tabs = document.getElementById('pdfAnexosTabs');
    if (tabs) tabs.innerHTML = '';

    // El boton de anexar ya no vive en la barra sino en la cabecera, asi que hay que
    // apagarlo aparte: un PDF generado al vuelo (nota de entrega, reporte) no admite
    // correcciones y ofrecerlo seria prometer un 422.
    const zona = document.getElementById('pdfAnexarZona');
    if (zona) zona.style.display = 'none';

    if (window._pdfComparaApagar) window._pdfComparaApagar();
    window._pdfSincronizarBarraPestanas();
};

window.cargarAnexosEquipo = function (equipoId, usarCache) {
    if (!equipoId) return Promise.resolve({});
    if (usarCache && window._anexosPorEquipo[equipoId]) {
        return Promise.resolve(window._anexosPorEquipo[equipoId]);
    }
    return _pedirAnexos('/admin/equipos/' + equipoId + '/anexos', {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(d => {
            const mapa = (d && d.success && d.anexos) ? d.anexos : {};
            window._anexosPorEquipo[equipoId] = mapa;
            return mapa;
        })
        .catch(() => ({}));   // sin anexos el visor se ve como siempre
};

window._pdfPintarAnexos = function (url, docType, equipoId, label) {
    const barra = document.getElementById('pdfAnexosBar');
    const tabs  = document.getElementById('pdfAnexosTabs');
    if (!barra || !tabs) return;

    // Documentos no gestionables (PDF generados al vuelo: nota de
    // entrega, reporte de fallas...) no admiten correcciones.
    // Mismo guardia que arriba: aqui el modulo ya se dio por 'equipo'
    // porque solo se llega desde la rama que lo comprobo.
    if (!window._pdfAdmiteAnexos('equipo', equipoId, docType)) {
        window._pdfOcultarAnexos();
        return;
    }

    const lista = ((window._anexosPorEquipo[equipoId] || {})[docType]) || [];

    // Si ya estabamos en este documento, el principal se conserva: la
    // llamada viene de pinchar una pestana, no de abrir otro PDF.
    const previo = window._pdfAnexoCtx;
    const mismo  = previo && String(previo.equipoId) === String(equipoId) && previo.tipo === docType;
    const principal = mismo ? previo.principal : url;

    // Otro documento distinto: la comparacion no se arrastra. Sin esto el panel
    // derecho seguiria enseñando la correccion del documento ANTERIOR al lado del
    // nuevo, que es la peor forma posible de equivocarse en una pantalla de papeles.
    if (!mismo && window._pdfComparaApagar) window._pdfComparaApagar();

    window._pdfAnexoCtx = { equipoId, tipo: docType, label, principal, activo: url };

    // El boton de anexar (cabecera) se enciende en cuanto el documento admite
    // correcciones, haya o no alguna todavia.
    const zonaAnexar = document.getElementById('pdfAnexarZona');
    if (zonaAnexar) zonaAnexar.style.display = 'flex';

    // ── Se abre YA PARTIDO ──────────────────────────────────────────────────────
    // Un documento con correccion se enseña con los dos PDFs a la vista SIN PULSAR NADA:
    // es la unica forma de entrar a la comparacion, porque el boton que la encendia se
    // quito — si hay dos documentos, comparar es lo que se quiere hacer con ellos.
    //
    // Para volver a ver uno solo se pulsa la pestaña "Original", que esta a la vista
    // cuando hay DOS O MAS correcciones (ver _pdfSincronizarBarraPestanas). Con una
    // sola, la barra sobra y no se pinta: de esa vista se sale cerrando el visor.
    //
    // Solo cuando lo que se muestra es el ORIGINAL —que es como se abre siempre—: si
    // alguien entro directo a una correccion, no se le reordena la pantalla sola.
    //
    // La correccion elegida es la que se estaba viendo si venimos de ella, y si no, la
    // primera de la lista.
    if (!window._pdfComparando && lista.length && url === principal
        && window.innerWidth >= PDF_COMPARA_ANCHO_MIN) {
        const previaAbierta = (mismo && previo && previo.activo !== previo.principal)
            ? lista.find(a => a.link === previo.activo)
            : null;
        window._pdfComparaEncender(previaAbierta || lista[0]);
    }


    // Todo lo interpolado va escapado: la etiqueta y el autor los
    // escribe el usuario y acaban dentro de innerHTML y de atributos.
    const chip = (link, texto, titulo, avisar, idAnexo) =>
        '<button type="button" data-anexo-link="' + _escAnexo(link) + '"' +
        (idAnexo ? ' data-anexo-id="' + _escAnexo(idAnexo) + '"' : '') +
        ' title="' + _escAnexo(titulo) + '" ' +
        'style="flex-shrink:0;border:1px solid ' + (link === url ? '#3b82f6' : '#374151') + ';' +
        'background:' + (link === url ? '#1e3a5f' : 'transparent') + ';' +
        'color:' + (link === url ? '#fff' : '#9ca3af') + ';' +
        'padding:4px 11px;border-radius:6px;font-size:12px;font-weight:600;' +
        'display:flex;align-items:center;gap:5px;cursor:pointer;white-space:nowrap;">' +
        (avisar ? '<i class="material-icons" style="font-size:13px;color:#f59e0b;">error_outline</i>' : '') +
        _escAnexo(texto) + '</button>';

    // 'Original' y no la etiqueta del documento: esa ya la rotula el titulo del
    // header (#pdfPreviewTitle), y con la barra a la vista salia dos veces, una
    // encima de otra. La pestana solo tiene que decir cual es el documento sin
    // corregir para poder volver a el desde una correccion.
    let html = chip(principal, 'Original', 'Documento principal', false);

    lista.forEach(a => {
        // Una correccion de un principal que ya se sustituyo sigue
        // guardada, pero se marca: no corrige al documento de ahora.
        const tit = a.vigente
            ? ('Corrección anexa · ' + (a.autor || '') + ' · ' + (a.fecha || ''))
            : 'Corrección del documento anterior (el principal fue reemplazado)';
        html += chip(a.link, a.etiqueta, tit, !a.vigente, a.id);
    });

    tabs.innerHTML = html;
    tabs.querySelectorAll('[data-anexo-link]').forEach(b => {
        b.addEventListener('click', () => {
            const destino = b.getAttribute('data-anexo-link');

            // Comparando, el izquierdo se queda con el original y la pestaña elige
            // que correccion va a la derecha. Pulsar "Original" apaga la comparacion:
            // es pedir ver solo ese.
            if (window._pdfComparando) {
                const ctxC = window._pdfAnexoCtx;
                if (destino === (ctxC && ctxC.principal)) {
                    window.pdfCompararToggle();
                } else {
                    const listaC = ((window._anexosPorEquipo[ctxC.equipoId] || {})[ctxC.tipo]) || [];
                    const anexoC = listaC.find(a => a.link === destino);
                    if (anexoC) window._pdfComparaMostrar(anexoC);
                }
                return;
            }
            // Se compara contra `url` del cierre, no contra window._pdfAnexoCtx.activo:
            // son el mismo valor —_pdfPintarAnexos guarda activo: url— pero `url` es el
            // documento para el que se pintaron ESTAS pestanas (el mismo con el que se
            // decide cual va resaltada, mas arriba), y sobre todo no puede ser null.
            // El contexto global si puede quedarse en null entre repintados, y entonces
            // leerle .activo tiraba un TypeError que se comia el clic en silencio.
            if (destino && destino !== url) {
                window.openPdfPreview(destino, docType, label, equipoId, null, true);
            }
        });
    });

    // Reemplazar sigue actuando sobre el PRINCIPAL, asi que viendo una correccion se
    // esconde: subir ahi pisaria un documento que no es el que se tiene delante, y no
    // existe endpoint para sustituir una correccion (anexarDoc solo AÑADE).
    //
    // Eliminar SI se queda: deletePdfFromPreview mira que pestaña esta abierta y borra
    // la correccion que se ve, sin tocar el principal. Estuvo escondido mientras ese
    // boton solo sabia borrar el principal; ahora esconderlo dejaria las correcciones
    // sin forma de deshacerse.
    // Y con las pestañas ya puestas y la comparacion decidida, la barra: la regla vive
    // en _pdfSincronizarBarraPestanas y aqui solo se le pregunta.
    window._pdfSincronizarBarraPestanas();

    const viendoAnexo = (url !== principal);
    const btnUpd = document.getElementById('pdfUpdateLabel');
    const btnDel = document.getElementById('pdfDeleteBtn');
    if (btnUpd && btnUpd.style.display !== 'none' && viendoAnexo) btnUpd.style.display = 'none';
    if (btnDel) {
        btnDel.title = viendoAnexo
            ? 'Eliminar esta corrección (el documento principal no se toca)'
            : 'Eliminar Documento (Drive + BD)';
    }
};

const _avisoAnexo = (msg, tipo) => {
    if (typeof window.showToast === 'function') window.showToast(msg, tipo);
    else alert(msg);
};

// Subir una correccion. Endpoint aparte del de sustituir: este solo
// ANADE, nunca borra el archivo anterior de Drive.
window._pdfAnexarInit = function () {
    const btn   = document.getElementById('pdfAnexarBtn');
    const input = document.getElementById('pdfAnexarInput');
    // Los controles viven en el layout y sobreviven a la navegacion
    // SPA, que re-ejecuta este <script>: sin el candado se apilaria un
    // listener por visita.
    if (!btn || !input || btn._anexoBound) return;
    btn._anexoBound = true;

    btn.addEventListener('click', () => input.click());

    input.onchange = function () {
        const file = this.files && this.files[0];
        const ctx  = window._pdfAnexoCtx;
        this.value = '';
        if (!file || !ctx) return;
        // Defensa en profundidad, igual que uploadDocumentFromPreview: el Blade ya no
        // pinta la zona sin 'user.edit', pero CAN_UPDATE_INFO es ese mismo permiso y
        // cortar aqui evita subir un PDF entero para que el servidor lo rechace con un
        // 403 (anexarDoc valida igual del otro lado; esto solo ahorra el viaje).
        if (!window.CAN_UPDATE_INFO) {
            _avisoAnexo('No tienes permisos para anexar correcciones.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('file', file);
        fd.append('doc_type', ctx.tipo);

        const ov = document.getElementById('pdfUploadProgressOverlay');
        const tx = document.getElementById('pdfUploadStatusText');
        if (ov) ov.style.display = 'flex';
        if (tx) tx.textContent = 'Anexando corrección';

        _pedirAnexos('/admin/equipos/' + ctx.equipoId + '/anexar-doc', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(d => {
            if (ov) ov.style.display = 'none';
            if (!d || !d.success) {
                _avisoAnexo((d && d.message) || 'No se pudo anexar la corrección.', 'error');
                return;
            }
            // Se relee del servidor: es el unico sitio que sabe si el
            // anexo quedo vigente respecto del principal de ahora.
            window.cargarAnexosEquipo(ctx.equipoId).then(() => {
                // Se vuelve al PRINCIPAL, no a la correccion recien subida. Con una
                // correccion encima el visor se abre PARTIDO —original a la izquierda,
                // correccion a la derecha—, y eso solo pasa si lo que se abre es el
                // principal: abriendo por la correccion se caia en la vista de un solo
                // documento con la barra de pestañas puesta, que es justo lo que la
                // vista partida vino a sustituir.
                // 'activo' se deja apuntando a la recien subida para que la comparacion
                // la elija a ella y no a la primera de la lista (mismo mecanismo que usa
                // volver de una pestaña).
                if (window._pdfAnexoCtx) window._pdfAnexoCtx.activo = d.anexo.link;
                window.openPdfPreview(ctx.principal, ctx.tipo, ctx.label, ctx.equipoId, null, true, 'equipo');
                if (typeof window._pintarBadgesAnexos === 'function') window._pintarBadgesAnexos(ctx.equipoId);
                _avisoAnexo(d.message || 'Corrección anexada correctamente', 'success');
            });
        })
        .catch(err => {
            if (ov) ov.style.display = 'none';
            _avisoAnexo('Error al anexar la corrección: ' + err, 'error');
        });
    };
};

// --- Metadata Side Panel Logic ---
window.loadMetadata = async function () {
    const ctx = window.currentPdfContext;
    if (!ctx) return;

    const container = document.getElementById('metaFieldsContainer');
    const loader = document.getElementById('metaPanelLoader');
    const form = document.getElementById('pdfMetadataForm');

    if (!ctx.equipoId) {
        if (loader) loader.style.display = 'none';
        if (container) {
            container.innerHTML = '<div style="padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px dashed #555;"><p style="color: #cbd5e0; font-size: 13px; text-align: center; margin: 0;">El vehículo asociado a este documento fue eliminado de la base de datos.</p></div>';
        }
        return;
    }

    if (loader) loader.style.display = 'flex';
    if (form) form.style.opacity = '0.5';
    try {
        const baseUrl = ctx.module === 'auxiliar'
            ? `/admin/equipos-auxiliares/${ctx.equipoId}/metadata`
            : `/admin/equipos/${ctx.equipoId}/metadata`;
        const res = await window.apiFetch(`${baseUrl}?type=${ctx.docType}`);
        const data = await res.json();
        if (data.success) {
            const info = data.data;
            let html = '';
            const commonInputStyle = "background: #282828; border: 1px solid #555; color: white; padding: 6px 8px; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 13px; height: 32px;";
            const labelStyle = "display: block; font-size: 12px; color: #cbd5e0; margin-bottom: 4px; font-weight: 600;";
            const containerStyle = "margin-bottom: 12px;";
            const disabledAttr = !window.CAN_UPDATE_INFO ? `disabled style="${commonInputStyle} opacity: 0.7; cursor: not-allowed;"` : `style="${commonInputStyle}"`;
            // Modulo auxiliares: campos propios del aux (no hay tabla
            // documentacion paralela). Propiedad => datos basicos;
            // certificado => fecha de vencimiento + datos basicos.
            if (ctx.module === 'auxiliar') {
                if (ctx.docType === 'propiedad') {
                    html += `
                    <div style="${containerStyle}"><label style="${labelStyle}">Serial</label><input type="text" name="serial" value="${info.serial || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Código Interno</label><input type="text" name="codigo" value="${info.codigo || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Tipo</label><input type="text" name="tipo" value="${info.tipo || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Marca</label><input type="text" name="marca" value="${info.marca || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Modelo</label><input type="text" name="modelo" value="${info.modelo || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Capacidad</label><input type="text" name="capacidad" value="${info.capacidad || ''}" ${disabledAttr} autocomplete="off"></div>
                    <div style="${containerStyle}"><label style="${labelStyle}">Año</label><input type="number" name="anio" value="${info.anio || ''}" ${disabledAttr} autocomplete="off"></div>
                `;
                } else if (ctx.docType === 'certificado') {
                    html += `
                    <div style="${containerStyle}"><label style="${labelStyle}">Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>
                `;
                }
                container.innerHTML = html;
                return;
            }
            if (ctx.docType === 'propiedad') {
                html += `
                <div style="${containerStyle}"><label for="meta_nro_doc_${ctx.equipoId}" style="${labelStyle}">Nro. Documento</label><input type="text" id="meta_nro_doc_${ctx.equipoId}" name="nro_documento" value="${info.nro_documento || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_titular_${ctx.equipoId}" style="${labelStyle}">Titular</label><input type="text" id="meta_titular_${ctx.equipoId}" name="titular" value="${info.titular || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_placa_${ctx.equipoId}" style="${labelStyle}">Placa</label><input type="text" id="meta_placa_${ctx.equipoId}" name="placa" value="${info.placa || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_marca_${ctx.equipoId}" style="${labelStyle}">Marca</label><input type="text" id="meta_marca_${ctx.equipoId}" name="marca" value="${info.marca || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_modelo_${ctx.equipoId}" style="${labelStyle}">Modelo</label><input type="text" id="meta_modelo_${ctx.equipoId}" name="modelo" value="${info.modelo || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_chasis_${ctx.equipoId}" style="${labelStyle}">Serial Chasis</label><input type="text" id="meta_chasis_${ctx.equipoId}" name="serial_chasis" value="${info.serial_chasis || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}"><label for="meta_motor_${ctx.equipoId}" style="${labelStyle}">Serial Motor</label><input type="text" id="meta_motor_${ctx.equipoId}" name="serial_motor" value="${info.serial_motor || ''}" ${disabledAttr} autocomplete="off"></div>
            `;
            } else if (ctx.docType === 'poliza') {
                let datalistOptions = '';
                let currentInsurerName = '';
                if (info.insurers) {
                    info.insurers.forEach(ins => {
                        datalistOptions += `<option value="${ins.NOMBRE_ASEGURADORA}">`;
                        if (ins.ID_SEGURO == info.id_seguro) currentInsurerName = ins.NOMBRE_ASEGURADORA;
                    });
                }
                html += `
                <div style="${containerStyle}"><label for="meta_fec_venc_${ctx.equipoId}" style="${labelStyle}">Fecha Vencimiento</label><input type="date" id="meta_fec_venc_${ctx.equipoId}" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>
                <div style="${containerStyle}">
                    <label for="meta_aseguradora_${ctx.equipoId}" style="${labelStyle}">Aseguradora <small style="color:#94a3b8;font-weight:400;">(Seleccionar o escribir nueva)</small></label>
                    <input type="text" id="meta_aseguradora_${ctx.equipoId}" name="nombre_aseguradora" list="insurersList_${ctx.equipoId}" value="${currentInsurerName || ''}" placeholder="Escriba o seleccione..." ${disabledAttr} autocomplete="off">
                    <datalist id="insurersList_${ctx.equipoId}">${datalistOptions}</datalist>
                </div>
            `;
            } else if (ctx.docType === 'rotc' || ctx.docType === 'racda' || ctx.docType === 'adicional') {
                // Compraventa (adicional_2) NO requiere fecha de vencimiento.
                // Antes el Certificado (adicional) solo mostraba fecha si la categoria era
                // FLOTA LIVIANA — los equipos FLOTA PESADA quedaban con panel vacio.
                // Removida esa restriccion: el campo aparece siempre, el usuario decide
                // si llena la fecha o la deja vacia segun corresponda al equipo.
                html += `<div style="${containerStyle}"><label for="meta_fec_venc_${ctx.equipoId}" style="${labelStyle}">Fecha Vencimiento</label><input type="date" id="meta_fec_venc_${ctx.equipoId}" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>`;
            }
            container.innerHTML = html;
        }
    } catch (e) {
        console.error(e);
        container.innerHTML = '<span style="color:#fc8181;">Error al cargar datos.</span>';
    } finally {
        if (loader) loader.style.display = 'none';
        if (form) form.style.opacity = '1';
    }
};

window.saveMetadata = async function (e) {
    e.preventDefault();
    if (!window.CAN_UPDATE_INFO) {
        window.toast('No tienes permisos para actualizar', 'error');
        return;
    }
    const ctx = window.currentPdfContext;
    const btn = document.getElementById('btnSaveMeta');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="material-icons" style="font-size:16px;">hourglass_empty</i> Guardando...';
    btn.disabled = true;
    try {
        const formData = new FormData(e.target);
        formData.append('doc_type', ctx.docType);
        const saveUrl = ctx.module === 'auxiliar'
            ? `/admin/equipos-auxiliares/${ctx.equipoId}/update-metadata`
            : `/admin/equipos/${ctx.equipoId}/update-metadata`;
        const res = await window.apiFetch(saveUrl, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            window.toast('Datos actualizados correctamente', 'success');
            // Modulo auxiliares: refresca la tabla y termina (no aplica el
            // flujo de showDetailsImproved/activeEquipoButton del modulo equipos).
            if (ctx.module === 'auxiliar') {
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
                return;
            }
            if (window.activeEquipoButton) {
                const d = window.activeEquipoButton.dataset;
                const eqId = d.equipoId;
                const cache = (window.equiposData && eqId) ? window.equiposData[eqId] : null;
                // showDetailsImproved hace d = {...dataset, ...equiposData[id]} y equiposData
                // PISA al dataset. Por eso aplicamos los cambios a AMBOS: si solo tocáramos el
                // dataset, la fecha nueva quedaría tapada por la vieja del cache (síntoma: había
                // que recargar la página — se notaba sobre todo en el Certificado/adicional).
                const aplicar = (obj) => {
                    if (!obj) return;
                    if (ctx.docType === 'propiedad') {
                        obj.nroDoc = formData.get('nro_documento'); obj.titular = formData.get('titular');
                        obj.placa = formData.get('placa'); obj.marca = formData.get('marca');
                        obj.modelo = formData.get('modelo'); obj.chasis = formData.get('serial_chasis');
                        obj.motorSerial = formData.get('serial_motor');
                    } else {
                        // Misma fuente de verdad (DOC_FIELD_MAP.vencKey) que subida/borrado.
                        const vk = (window.DOC_FIELD_MAP && window.DOC_FIELD_MAP[ctx.docType]) ? window.DOC_FIELD_MAP[ctx.docType].vencKey : null;
                        if (vk) obj[vk] = formData.get('fecha_vencimiento');
                        if (ctx.docType === 'poliza') obj.seguro = formData.get('nombre_aseguradora');
                    }
                };
                aplicar(d);
                aplicar(cache);
                showDetailsImproved(window.activeEquipoButton);
            }
            if (typeof window.refreshDashboardAlerts === 'function') window.refreshDashboardAlerts();
        } else { throw new Error(data.message); }
    } catch (error) {
        console.error(error);
        window.toast('Error: No se pudieron guardar los cambios', 'error');
    } finally {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
};

window.closePdfPreview = function () {
    const modal = document.getElementById('pdfPreviewModal');
    const iframe = document.getElementById('pdfPreviewFrame');
    if (modal) modal.classList.remove('active');
    if (iframe) {
        // 'about:blank' y no '': la cadena vacía se resuelve contra la URL de la
        // página actual y el iframe se pondría a cargarla entera al cerrar.
        iframe.src = 'about:blank'; // libera la memoria del PDF
        // El desenfoque se limpia aqui tambien: cerrar a mitad de carga dejaba
        // el filtro puesto sobre un iframe que ya no se ve, y lo heredaba la
        // apertura siguiente antes de que su propio reset entrara.
        iframe.style.filter = '';
        iframe.style.opacity = '0';
    }
    // Y el respaldo de 5 s, que si no seguiría vivo sobre un visor ya cerrado.
    clearTimeout(_pdfLoaderTimeout);
    clearTimeout(_pdfEnfoqueTimeout);
    // La barra de correcciones tambien se cierra: dejaba _pdfAnexoCtx apuntando al
    // ultimo equipo+tipo visto, y ese contexto sobrevivia al cierre. Es la otra mitad
    // del guard de openPdfPreview — con el visor cerrado no hay documento delante, asi
    // que no debe quedar ningun destino al que anexar.
    if (typeof window._pdfOcultarAnexos === 'function') window._pdfOcultarAnexos();
};


// Borrado de PDF desde el modal de preview. Borra del Google Drive Y de la BD.
// Por ahora solo soporta el modulo 'equipo'; auxiliares no implementado todavia.
// Usa confirm() nativo del browser (no window.showModal) porque el standardModal
// queda detras del pdfPreviewModal por el stacking context.
/**
 * Borra el documento del visor.
 *
 * @param {string} [cual] Cual de los dos, cuando hay dos a la vista:
 *   'principal'  — el de la izquierda (el documento sin corregir)
 *   'correccion' — la correccion que se esta viendo a la derecha
 *   sin valor    — se deduce de lo que hay abierto (el boton de la cabecera)
 *
 * El parametro existe porque comparando NO se puede deducir: los dos documentos estan en
 * pantalla al mismo tiempo, y hasta ahora el unico boton miraba cual estaba "activo" —que
 * comparando es siempre el principal—, asi que pulsarlo con una correccion delante borraba
 * el documento bueno. Cada panel tiene ahora su propio boton y dice cual es el suyo.
 */
window.deletePdfFromPreview = async function (cual) {
    if (!window.CAN_DELETE_DOCS) {
        window.toast('No tienes permisos para eliminar documentos.', 'error');
        return;
    }
    const ctx = window.currentPdfContext;
    if (!ctx || !ctx.equipoId || !ctx.docType) {
        window.toast('Contexto de documento no disponible.', 'error');
        return;
    }

    // Estando en una corrección, el botón borra LA CORRECCIÓN, no el documento
    // principal. Antes borraba siempre el principal —solo miraba currentPdfContext,
    // que no cambia al saltar de pestaña—, así que quien quisiera deshacer una
    // corrección se llevaba por delante el documento bueno.
    let correccion = null;
    if (cual === 'correccion') {
        // Panel derecho: la correccion que ese panel esta mostrando.
        correccion = window._pdfComparaAnexoDer || null;
    } else if (cual !== 'principal') {
        correccion = window._pdfCorreccionAbierta ? window._pdfCorreccionAbierta() : null;
    }
    if (correccion) {
        if (!window.confirm('¿Eliminar el anexo "' + (correccion.etiqueta || 'Anexo de corrección') +
            '"?\n\nEl documento principal NO se toca. Esta acción no se puede deshacer.')) return;

        const btnC = document.getElementById('pdfDeleteBtn');
        if (btnC) btnC.disabled = true;
        if (typeof window.showPreloader === 'function') window.showPreloader();
        try {
            const r = await window.apiFetch('/admin/equipos/' + ctx.equipoId + '/anexos/' + correccion.id, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const d = await r.json().catch(() => ({}));
            if (r.ok && d.success) {
                window.toast(d.message || 'Corrección eliminada.', 'success');
                // El mapa en memoria manda sobre lo que pinta la barra: se limpia para
                // que la siguiente carga lo pida de nuevo, y se vuelve al principal.
                delete window._anexosPorEquipo[ctx.equipoId];
                const principal = window._pdfAnexoCtx ? window._pdfAnexoCtx.principal : null;
                if (principal) {
                    window._pdfAnexoCtx = null;   // que _pdfPintarAnexos lo rearme desde cero
                    // Se vacia el visor y se vuelve a entrar EN OTRO TICK. Si no, las dos
                    // asignaciones de src caen en la misma tarea y el navegador se queda
                    // solo con la ultima: cuando el documento que toca abrir es el que ya
                    // estaba puesto, no hay navegacion de verdad y el visor puede quedarse
                    // sin repintar (en negro) hasta que se cierra y se abre a mano.
                    const frameIzq = document.getElementById('pdfPreviewFrame');
                    if (frameIzq) frameIzq.src = 'about:blank';
                    setTimeout(function () {
                        window.openPdfPreview(principal, ctx.docType, ctx.label, ctx.equipoId, null, true, 'equipo');
                    }, 0);
                } else {
                    window.closePdfPreview();
                }
            } else {
                window.toast(d.message || 'No se pudo eliminar la corrección.', 'error');
            }
        } catch (e) {
            window.toast('Error de red al eliminar la corrección.', 'error');
        } finally {
            if (btnC) btnC.disabled = false;
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
        }
        return;
    }
    if (ctx.module === 'auxiliar') {
        if (!window.confirm('¿Eliminar este documento?\n\nEsta acción NO se puede deshacer.')) return;
        const btnAux = document.getElementById('pdfDeleteBtn');
        if (btnAux) btnAux.disabled = true;
        if (typeof window.showPreloader === 'function') window.showPreloader();
        try {
            const r = await window.apiFetch(`/admin/equipos-auxiliares/${ctx.equipoId}/delete-doc?doc_type=${encodeURIComponent(ctx.docType)}`, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const d = await r.json().catch(() => ({}));
            if (r.ok && d.success) {
                window.toast(d.message || 'Documento eliminado.', 'success');
                // Actualizar la cache + icono del modal de detalles (a "Subir"/cloud_upload)
                // EN VIVO: el modal de detalles queda abierto detrás del visor, así que al
                // cerrar el visor ya muestra el estado correcto sin recargar la página.
                if (typeof window.syncAuxDocCache === 'function') window.syncAuxDocCache(ctx.equipoId, ctx.docType, null);
                const modal = document.getElementById('pdfPreviewModal');
                if (modal) modal.classList.remove('active');
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares(); // refresca la lista
            } else {
                window.toast(d.message || 'No se pudo eliminar el documento.', 'error');
            }
        } catch (e) {
            window.toast('Error de red al eliminar el documento.', 'error');
        } finally {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            if (btnAux) btnAux.disabled = false;
        }
        return;
    }

    const confirmed = window.confirm(
        '¿Eliminar este documento del Google Drive y de la base de datos?\n\nEsta acción NO se puede deshacer.'
    );
    if (!confirmed) return;

    const btn = document.getElementById('pdfDeleteBtn');
    if (btn) btn.disabled = true;
    if (typeof window.showPreloader === 'function') window.showPreloader();

    try {
        // getCsrf() ya cubre el <meta> y el input _token de un form Blade;
        // solo si NINGUNO existe no hay con que firmar el DELETE.
        const csrfTok = window.getCsrf();
        if (!csrfTok) {
            window.toast('Token CSRF no disponible. Recarga la página.', 'error');
            return;
        }
        // doc_type como query param: la ruta es DELETE, asi que enviamos
        // los datos en la URL para evitar problemas con bodies en DELETE
        // (algunos middlewares y proxies los strippean).
        const res = await window.apiFetch(`/admin/equipos/${ctx.equipoId}/delete-doc?doc_type=${encodeURIComponent(ctx.docType)}`, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.success) {
            window.toast(data.message || 'Documento eliminado.', 'success');

            // ── Sincronizar la UI in-memory para que NO haya que recargar la pagina ──
            // 1) Limpiar campos en el dataset del boton del listado (icono de PDF cargado).
            // 2) Limpiar campos en window.equiposData[id] (cache JS de la tabla, lo usa el AJAX paginado).
            // 3) Re-renderizar el modal de detalles si esta abierto.
            const equipoIdNum = ctx.equipoId;
            const activeBtn = window.activeEquipoButton;
            if (typeof window.clearDocFields === 'function') {
                if (activeBtn && activeBtn.dataset) {
                    window.clearDocFields(activeBtn.dataset, ctx.docType);
                }
                if (window.equiposData && window.equiposData[equipoIdNum]) {
                    window.clearDocFields(window.equiposData[equipoIdNum], ctx.docType);
                }
            }
            // La purga de la cache de anexos va SUELTA, por el mismo motivo que en
            // uploadDocumentFromPreview: clearDocFields la hace de paso, pero solo corre
            // dentro de los dos if de arriba, y el visor tambien se abre desde
            // /admin/historial-documentos y desde las alertas del tablero, donde no hay
            // boton activo ni entrada en equiposData. Sin esto, _anexosPorEquipo se
            // quedaba con las correcciones marcadas vigente:true colgando de un principal
            // que acaba de dejar de existir.
            if (equipoIdNum && typeof window.olvidarAnexosEquipo === 'function') {
                window.olvidarAnexosEquipo(equipoIdNum);
            }

            window.closePdfPreview();

            // Si el modal de detalles esta abierto, re-renderizarlo con los datos
            // ya limpiados para que el icono "PDF cargado" desaparezca al instante.
            const detailsModal = document.getElementById('detailsModal');
            const detailsOpen = detailsModal && detailsModal.classList.contains('active');
            if (detailsOpen && activeBtn && typeof window.showDetailsImproved === 'function') {
                try { window.showDetailsImproved(activeBtn); } catch (_) { /* noop */ }
            }

            // Refrescar el resto de vistas que pueden estar mostrando el documento.
            if (typeof window.refreshDashboardAlerts === 'function') window.refreshDashboardAlerts();
            if (typeof window.loadHistorialDocumentos === 'function') window.loadHistorialDocumentos();
        } else {
            const msg = (data && data.message) ? data.message : `Error HTTP ${res.status}`;
            window.toast(msg, 'error');
            console.error('deletePdfFromPreview: backend rechazo', res.status, data);
        }
    } catch (e) {
        console.error('deletePdfFromPreview: excepcion de red', e);
        window.toast('Error de red al eliminar el documento.', 'error');
    } finally {
        if (btn) btn.disabled = false;
        if (typeof window.hidePreloader === 'function') window.hidePreloader();
    }
};

// Special Upload Handler for Preview Modal (XMLHttpRequest for Progress)
window.uploadDocumentFromPreview = function (input, type, equipoId, label) {
    // PERMISSION CHECK
    if (!window.CAN_UPDATE_INFO) {
        input.value = ''; // Clear input
        window.toast('No tienes permisos para actualizar documentos', 'error');
        return;
    }

    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    // Show upload progress overlay
    const progressOverlay = document.getElementById('pdfUploadProgressOverlay');
    const progressBar = document.getElementById('pdfUploadProgressBar');
    const progressPercentage = document.getElementById('pdfUploadPercentage');

    if (progressOverlay) progressOverlay.style.display = 'flex';
    if (progressBar) progressBar.style.width = '0%';
    if (progressPercentage) progressPercentage.innerText = '0%';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('doc_type', type);

    const xhr = new XMLHttpRequest();
    // uploadUrl override desde window.currentPdfContext (otros modulos
    // como aux pueden inyectar su propio endpoint). Si no, fallback a
    // /admin/equipos/{id}/upload-doc.
    const targetUrl = (window.currentPdfContext && window.currentPdfContext.uploadUrl)
        ? window.currentPdfContext.uploadUrl
        : `/admin/equipos/${equipoId}/upload-doc`;
    xhr.open('POST', targetUrl, true);
    xhr.setRequestHeader('X-CSRF-TOKEN', window.getCsrf());
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
            const percentComplete = Math.round((e.loaded / e.total) * 100);
            if (progressBar) progressBar.style.width = percentComplete + '%';

            const statusText = document.getElementById('pdfUploadStatusText');
            if (percentComplete === 100) {
                if (statusText) statusText.innerText = 'Guardando...';
                if (progressPercentage) progressPercentage.innerText = 'Procesando...';
            } else {
                if (statusText) statusText.innerText = 'Subiendo documento';
                if (progressPercentage) progressPercentage.innerText = percentComplete + '%';
            }
        }
    };

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);

                if (data.success) {
                    // Update status text while iframe loads
                    const statusText = document.getElementById('pdfUploadStatusText');
                    if (statusText) statusText.innerText = 'Abriendo vista previa...';
                    if (progressPercentage) progressPercentage.innerText = 'Listo';

                    // Get iframe reference
                    const iframe = document.getElementById('pdfPreviewFrame');

                    // Update iframe to show new PDF
                    if (iframe) {
                        iframe.style.opacity = '0';

                        // Setup load handler for new PDF to hide overlay ONLY when ready
                        iframe.onload = function () {
                            if (progressOverlay) {
                                progressOverlay.style.opacity = '0';
                                setTimeout(() => {
                                    progressOverlay.style.display = 'none';
                                    progressOverlay.style.opacity = '1';
                                }, 300);
                            }
                            iframe.style.opacity = '1';

                            // Reset status text for next time
                            if (statusText) statusText.innerText = 'Subiendo documento';
                        };

                        // Load new PDF with force-refresh since file changed.
                        // data.link YA trae ?v=<timestamp> del backend (uploadDoc) — concatenar
                        // "?upd=" producia una URL con DOS '?' (invalida) y el iframe NO cargaba
                        // el PDF nuevo ("no se ve que cargue"). Usamos '&' si ya hay query string.
                        var _sep = data.link.indexOf('?') > -1 ? '&' : '?';
                        iframe.src = data.link + _sep + 'upd=' + new Date().getTime() + PDF_PARAMS_LECTURA;
                    }

                    // Update Download Button
                    const downloadBtn = document.getElementById('pdfDownloadBtn');
                    if (downloadBtn) downloadBtn.dataset.url = data.link;

                    // Sincroniza dataset + equiposData usando el helper unico (DOC_FIELD_MAP).
                    // Solo re-renderiza el modal detalles si sigue abierto debajo del preview;
                    // asi evitamos reabrirlo por encima del preview y manejamos race conditions
                    // (nodo muerto, SPA nav) gracias al guard de activeEquipoButton.
                    // SOLO para EQUIPOS. Sin este guard, al reemplazar un doc de un
                    // AUXILIAR la rama de equipos igual corría (activeEquipoButton no se
                    // resetea al abrir un aux) y escribía el link del doc del AUX en el
                    // dataset/caché del EQUIPO → el equipo mostraba el PDF equivocado.
                    const _esAux = window.currentPdfContext && window.currentPdfContext.module === 'auxiliar';
                    const btnFP = window.activeEquipoButton;
                    const btnFPAlive = btnFP && document.body.contains(btnFP);
                    if (!_esAux && btnFPAlive && typeof window.applyDocUpload === 'function') {
                        window.applyDocUpload(btnFP.dataset, type, data);
                        if (window.equiposData && btnFP.dataset.equipoId && window.equiposData[btnFP.dataset.equipoId]) {
                            window.applyDocUpload(window.equiposData[btnFP.dataset.equipoId], type, data);
                        }
                        const detailsModal = document.getElementById('detailsModal');
                        const detailsOpen  = detailsModal && detailsModal.classList.contains('active');
                        if (detailsOpen && typeof window.showDetailsImproved === 'function') {
                            try { window.showDetailsImproved(btnFP); } catch (_) { /* noop */ }
                        }
                    }

                    // El PRINCIPAL acaba de cambiar, y uploadDoc BORRA el archivo viejo de
                    // Drive (DeleteGoogleDriveFile::dispatch). Aqui solo se cambio el src del
                    // iframe, asi que la barra de correcciones se quedaba con el estado de
                    // antes y eso dejaba DOS cosas rotas:
                    //   · _pdfAnexoCtx.principal es PEGAJOSO a proposito —_pdfPintarAnexos lo
                    //     hereda mientras no cambien equipo+tipo, para que pinchar una
                    //     correccion no mueva cual es el principal—, de modo que seguia
                    //     apuntando al enlace ya borrado: la pestana "Documento principal"
                    //     abria un archivo que ya no existe en Drive.
                    //   · El servidor ya marco las correcciones como NO vigentes (corrigen al
                    //     documento anterior), pero se seguian pintando como vigentes.
                    //
                    // Va FUERA del if de activeEquipoButton de arriba —que es quien llamaba a
                    // olvidarAnexosEquipo via applyDocUpload—: el visor tambien se abre desde
                    // /admin/historial-documentos y desde las alertas del tablero, y ahi no hay
                    // boton activo, con lo que la cache no se invalidaba nunca.
                    //
                    // El orden importa: applyDocUpload (arriba) es SINCRONO, asi que su purga
                    // ocurre antes de que resuelva el fetch de aqui y no pisa lo recien traido.
                    if (!_esAux && equipoId && typeof window.olvidarAnexosEquipo === 'function') {
                        window.olvidarAnexosEquipo(equipoId);
                        if (typeof window._pdfAdmiteAnexos === 'function'
                            && typeof window.cargarAnexosEquipo === 'function'
                            && window._pdfAdmiteAnexos('equipo', equipoId, type)) {
                            // _pdfOcultarAnexos() y NO un `_pdfAnexoCtx = null` suelto.
                            // Anular el contexto es lo que corta la herencia del principal
                            // viejo (con ctx nulo, _pdfPintarAnexos toma como principal la
                            // url que se le pasa, que es el documento nuevo), pero dejando
                            // la barra a la vista quedaban sus pestañas —con sus listeners
                            // ya enganchados— clicables durante la espera, y ese handler lee
                            // window._pdfAnexoCtx.activo: pinchar una en ese hueco reventaba
                            // con "Cannot read properties of null". Y el hueco es SEGURO
                            // aqui, no hipotetico: olvidarAnexosEquipo() acaba de vaciar la
                            // cache, asi que el cargarAnexosEquipo de abajo SIEMPRE sale a la
                            // red. Escondiendo la barra no hay nada que pinchar; el propio
                            // _pdfPintarAnexos la vuelve a mostrar al repintar.
                            window._pdfOcultarAnexos();
                            window.cargarAnexosEquipo(equipoId)
                                .then(function () { window._pdfPintarAnexos(data.link, type, equipoId, label); })
                                .catch(function () { /* sin barra el visor se ve como siempre */ });
                        }
                    }

                    // AUXILIARES: el bloque de arriba solo refresca el modal de EQUIPOS.
                    // Para aux, traemos el detalle FRESCO de /details, actualizamos su
                    // cache (auxDetailsMap) y re-renderizamos el modal si sigue abierto,
                    // para que el documento recien subido se refleje al instante (antes
                    // quedaba con el estado viejo). NOTA: openAuxDetailsModal espera un
                    // BOTON (btn.dataset.auxId), no un id, por eso el refetch va directo.
                    if (window.currentPdfContext && window.currentPdfContext.module === 'auxiliar') {
                        var _auxId = window.currentPdfContext.equipoId;
                        var _auxModal = document.getElementById('auxDetailsModal');
                        if (_auxId && _auxModal && _auxModal.classList.contains('active')
                            && typeof window.renderAuxDetailsModal === 'function') {
                            window.apiFetch('/admin/equipos-auxiliares/' + _auxId + '/details', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (d) {
                                if (!d) return;
                                (window.auxDetailsMap = window.auxDetailsMap || {})[_auxId] = d;
                                window.renderAuxDetailsModal(d);
                            })
                            .catch(function () { /* silencioso: el toast de exito ya salio */ });
                        }
                    }

                    window.toast('Documento actualizado exitosamente', 'success');

                    // Refresh Dashboard Alerts if function exists
                    if (typeof window.refreshDashboardAlerts === 'function') {
                        window.refreshDashboardAlerts();
                    }
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                console.error(error);
                if (progressOverlay) progressOverlay.style.display = 'none';
                window.toast('Error: Respuesta inválida del servidor', 'error');
            }
        } else {
            if (progressOverlay) progressOverlay.style.display = 'none';
            window.toast('Error al cargar documento', 'error');
        }
    };

    xhr.onerror = function () {
        const progressOverlay = document.getElementById('pdfUploadProgressOverlay');
        if (progressOverlay) progressOverlay.style.display = 'none';
        window.toast('Error de red', 'error');
    };

    xhr.send(formData);
};

// filterDropdownOptions se define UNA sola vez en uicomponents.js (versión que
// normaliza acentos y respeta el filtrado por frente 'eq-tipo-oculto' de Equipos).
// Antes se redefinía aquí una versión más pobre (solo toUpperCase, sin acentos ni
// eq-tipo-oculto) que, al cargar DESPUÉS del <script src>, PISABA a la buena — el
// filtro de Tipo de equipos re-mostraba tipos que el frente había ocultado.


// showPreloader / hidePreloader viven en preloader.js (contador de referencias); navegacion.js
// les monta encima el watchdog anti-spinner-congelado.

// Re-initialize dynamic elements after SPA load
window.addEventListener('spa:contentLoaded', () => {
    window.updateSelectedCount();
});

