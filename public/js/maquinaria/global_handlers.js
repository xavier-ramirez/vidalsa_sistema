/**
 * Handlers globales del layout: window.togglePw (ver/ocultar contrasena), campos sin
 * autollenado del navegador (data-sin-autollenado) y la salida del sistema
 * (window.salirDelSistema: botones «Salir» y cierre por inactividad). El menu Acciones y el
 * panel de Filtros Avanzados de Equipos los abren sus onclick y los cierra equipos_index.js
 * (clic fuera).
 *
 * Extraido del <script> inline de estructura_base.blade.php (2026-08-24), cola del
 * mismo bloque que preloader.js y estado_red.js. Se carga DESPUES de los dos,
 * sincrono y sin defer, conservando el orden original.
 */
// Utilidad Global para Mostrar/Ocultar Contraseñas
window.togglePw = function (inputId, icon) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.textContent = isHidden ? 'visibility' : 'visibility_off';
};

// Campos que el navegador NO debe rellenar con el correo y la clave GUARDADOS de quien está
// conectado (crear/editar usuario, cambiar mi clave): autocomplete="off"/"new-password" no
// basta, Chrome y Edge los rellenan igual — y al editar a otro usuario eso le cambiaba la
// clave por la del admin. Nacen `readonly` con data-sin-autollenado (el navegador no
// rellena un campo de solo lectura) y se habilitan al tocarlos: pointerdown, antes del
// foco, para que el teclado del teléfono abra; focusin para quien llega con Tab. Tocar el
// botón de guardar los habilita todos: un campo readonly se salta el `required` del navegador.
function habilitarSinAutollenado(e) {
    const t = e.target;
    if (!t || !t.closest) return;
    const el = t.closest('[data-sin-autollenado][readonly]');
    if (el) { el.removeAttribute('readonly'); return; }
    const guardar = t.closest('button[type="submit"], input[type="submit"]');
    if (guardar && guardar.form) {
        guardar.form.querySelectorAll('[data-sin-autollenado][readonly]').forEach(function (c) { c.removeAttribute('readonly'); });
    }
}
document.addEventListener('pointerdown', habilitarSinAutollenado, true);
document.addEventListener('focusin', habilitarSinAutollenado, true);

// Salir del sistema: los botones «Salir» de la cabecera y del menú del teléfono, y el cierre
// por inactividad (partials/session_timeout). Por fetch y NO enviando el formulario: sin red, o
// con la red colgada, el POST nativo terminaba en la página de error del navegador en vez del
// inicio de sesión. Se espera al servidor como mucho 2,5 s (plazo); keepalive: el POST sigue
// su camino aunque la página ya se haya ido. `aviso` va en la URL (con lo pendiente en la
// bandeja, avisoConPendientes): si el POST no llegó y la sesión sigue abierta, el inicio de
// sesión la cierra en vez de volver solo al menú (SystemController::loginPage y
// precalentarCsrf en auth/inicio_sesion).
// Sin el interceptor: /logout no lleva CSRF y su respuesta no tiene que llevar a ningún lado.
window.salirDelSistema = function (aviso) {
    // Lo guardado en el equipo deja de poder leerse YA: el inicio de sesión que venga puede ser
    // una copia vieja del service worker que no sepa cerrar la llave. Las páginas guardadas se
    // quedan (son para entrar sin internet; se tiran si entra otra persona: DUENO en sw.js).
    if (window.LlaveLocal) window.LlaveLocal.cerrar();
    const destino = '/?aviso=' + window.avisoConPendientes(aviso);
    let salio = false;
    const salir = function () {
        if (salio) return;
        salio = true;
        window.location.replace(destino);   // replace: sin volver «atrás» a la página protegida
    };
    const plazo = setTimeout(salir, 2500);
    (window.fetchSinInterceptor || window.fetch)('/logout', {
        method: 'POST', credentials: 'same-origin', redirect: 'manual', cache: 'no-store', keepalive: true,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .catch(function () { /* sin red: igual se sale */ })
        .finally(function () { clearTimeout(plazo); salir(); });
};

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || !form.action || !form.action.includes('logout')) return;
    e.preventDefault();
    if (form.dataset.saliendo) return;   // doble clic
    form.dataset.saliendo = '1';
    if (typeof window.showPreloader === 'function') window.showPreloader();
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
    }
    window.salirDelSistema('salida');
});
