/**
 * Registro de equipos y auxiliares SIN INTERNET (/admin/equipos/create) — window.EquiposAltaSinRed.
 *
 * El formulario se manda siempre con Bandeja.enviar (public/js/offline/bandeja.js): con red
 * responde el servidor como siempre; sin ella el registro, con sus PDF, espera en la bandeja
 * y sube solo al volver. Lo que va a la bandeja ya no se puede corregir (solo reintentar o
 * descartar), así que ANTES de guardarlo se revisa aquí lo que rechazaría el servidor:
 *   - los obligatorios del modo (los `required` habilitados del formulario) y el rango de
 *     los números;
 *   - cada PDF con su número o fecha y al revés (EquipoController::store), el certificado
 *     del auxiliar con vencimiento de hoy en adelante (EquipoAuxiliarController::validateData)
 *     y el tope de los PDF (data-max-pdf-kb del formulario = GoogleDriveService::MAX_PDF_KB);
 *   - los repetidos, contra la copia local (copia-local.js) y lo que ya espera en la bandeja.
 * El servidor vuelve a validar todo al subir: si aun así lo rechaza (un serial de un frente que
 * este usuario no ve, por ejemplo), queda en rojo en la bandeja con el motivo.
 *
 *   EquiposAltaSinRed.preparar(form) → abre la copia local de equipos mientras esté el formulario.
 *   EquiposAltaSinRed.revisar(form, esAux) → Promise<{ id: mensaje }> ({} = se puede guardar).
 *   EquiposAltaSinRed.repetido(input, esAux) → Promise<bool> (validación en vivo sin red).
 *   EquiposAltaSinRed.meta(esAux) → { titulo, tipo, datos } para Bandeja.enviar.
 *
 * La SPA ejecuta este archivo una sola vez por pestaña: aquí no hay nada atado al DOM.
 */
(function () {
    'use strict';
    if (window.EquiposAltaSinRed) return;

    const REPETIDO = 'Este valor ya está registrado.';
    const OBLIGATORIO = 'Este campo es obligatorio.';
    const TIPO = { equipo: 'crear_equipo', aux: 'crear_auxiliar' };
    const ESPERA_MS = 3000;

    // Campo del formulario (id) → columna de la fila en la copia local, por modo.
    // Las mismas reglas unique de los dos controladores.
    const UNICOS = {
        serial_principal: { equipo: 'chasis', aux: 'serial' },
        codigo_interno:   { equipo: 'codigo', aux: 'codigo' },
        serial_motor:     { equipo: 'motorSerial' },
        placa:            { equipo: 'placaReal' },
    };

    const norm = (v) => String(v == null ? '' : v).trim().toUpperCase();
    const modo = (esAux) => (esAux ? 'aux' : 'equipo');

    let vista = null;
    const abrir = (ancla) => window.CopiaLocal.usar('equipos', ancla).catch(() => null);
    function preparar(form) {
        // Al cargar la página entera, este archivo corre ANTES que copia-local.js (va al final
        // del layout): se abre la copia cuando la página termina de cargar.
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { vista = abrir(form); }, { once: true });
        else vista = abrir(form);
    }

    // Valores de cada campo único: los de la copia local y los de lo que espera en la bandeja.
    // A la copia se la espera como mucho ESPERA_MS: si este dispositivo todavía no la tiene, la
    // primera descarga (o una red que no contesta) no puede dejar el botón colgado; sin ella
    // quedan los de la bandeja y lo demás lo revisa el servidor al subir.
    function ocupados(id, esAux) {
        const col = UNICOS[id] && UNICOS[id][modo(esAux)];
        if (!col) return Promise.resolve(new Set());
        const tope = new Promise((listo) => setTimeout(() => listo(null), ESPERA_MS));
        return Promise.race([vista || (vista = abrir(null)), tope]).then((v) => {
            const usados = new Set();
            if (v) v.filas(esAux ? 'auxiliares' : 'equipos').forEach((f) => { const x = norm(f[col]); if (x) usados.add(x); });
            ((window.Bandeja && window.Bandeja.pendientes()) || []).forEach((it) => {
                if (it.estado !== 'pendiente' || it.tipo !== TIPO[modo(esAux)] || !it.datos) return;
                const x = norm(it.datos[id]);
                if (x) usados.add(x);
            });
            return usados;
        });
    }

    function repetido(input, esAux) {
        const val = norm(input && input.value);
        if (!val) return Promise.resolve(false);
        return ocupados(input.id, esAux).then((usados) => usados.has(val));
    }

    const archivo = (el) => (el && el.files && el.files[0] && el.files[0].size > 0 ? el.files[0] : null);
    const lleno = (el) => !!(el && String(el.value || '').trim());

    function revisar(form, esAux) {
        const err = {};
        const poner = (el, msg) => { if (el && !err[el.id]) err[el.id] = msg; };

        // Obligatorios (también los <input hidden> de los desplegables, que el navegador no
        // valida) y los números con min/max (el consumo: numeric|min:0|max:99999). Nada más:
        // un Link GPS sin https:// lo acepta el servidor, y aquí no se le pone más regla.
        form.querySelectorAll('input, select, textarea').forEach((el) => {
            if (el.disabled || !el.name || !el.id || el.type === 'file') return;
            if (el.required && !lleno(el)) poner(el, OBLIGATORIO);
            else if (el.type === 'number' && (el.validity.rangeUnderflow || el.validity.rangeOverflow)) poner(el, el.validationMessage);
        });

        // Los PDF: que sean PDF, que no pasen del tope y que lleven su dato (y al revés).
        const maxKb = parseInt(form.dataset.maxPdfKb, 10) || 0;
        form.querySelectorAll('input[type="file"]').forEach((el) => {
            const f = archivo(el);
            if (el.disabled || !f) return;
            if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') poner(el, 'El archivo debe ser un PDF.');
            else if (maxKb && f.size > maxKb * 1024) poner(el, 'El PDF no puede pesar más de ' + maxKb.toLocaleString('es') + ' KB.');
        });
        if (esAux) {
            const cert = document.getElementById('aux_certificado');
            const fecha = document.getElementById('fecha_vencimiento_cert');
            if (archivo(cert) && !lleno(fecha)) poner(fecha, 'Indica el vencimiento del certificado.');
            const hoy = new Date();
            const hoyTxt = hoy.getFullYear() + '-' + String(hoy.getMonth() + 1).padStart(2, '0') + '-' + String(hoy.getDate()).padStart(2, '0');
            if (lleno(fecha) && fecha.value < hoyTxt) poner(fecha, 'El vencimiento no puede ser anterior a hoy.');
        } else {
            form.querySelectorAll('.doc-file').forEach((el) => {
                const dato = document.getElementById(el.dataset.metaTarget);
                if (el.disabled || !dato) return;
                if (archivo(el) && !lleno(dato)) poner(dato, 'Completa este dato del PDF.');
                else if (!archivo(el) && lleno(dato)) poner(el, 'Falta el PDF de este documento.');
            });
        }

        // Repetidos (lo último: es lo único que espera a la copia local).
        const ids = Object.keys(UNICOS).filter((id) => UNICOS[id][modo(esAux)]);
        return Promise.all(ids.map((id) => {
            const el = document.getElementById(id);
            return el && !el.disabled ? repetido(el, esAux).then((si) => { if (si) poner(el, REPETIDO); }) : null;
        })).then(() => err);
    }

    function meta(esAux) {
        const valor = (id) => norm((document.getElementById(id) || {}).value);
        const datos = {};
        Object.keys(UNICOS).forEach((id) => { if (UNICOS[id][modo(esAux)]) datos[id] = valor(id); });
        const nombre = [valor(esAux ? 'input_tipo_aux' : 'input_tipo_equipo'), valor('marca'), datos.serial_principal]
            .filter(Boolean).join(' · ');
        return {
            titulo: (esAux ? 'Registrar auxiliar ' : 'Registrar equipo ') + nombre,
            tipo: TIPO[modo(esAux)],
            datos,
        };
    }

    window.EquiposAltaSinRed = { preparar, revisar, repetido, meta };

    // Primera vez: el <script> inline de la vista corrió antes que este archivo y no pudo.
    const form = document.getElementById('createUnifiedForm');
    if (form) preparar(form);
})();
