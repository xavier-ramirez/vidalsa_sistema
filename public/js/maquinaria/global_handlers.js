/**
 * Handlers globales del layout: window.togglePw (ver/ocultar contrasena), campos sin
 * autollenado del navegador (data-sin-autollenado) y guard del submit de logout (evita
 * doble click y levanta el spinner). El menu Acciones y el panel de Filtros Avanzados de
 * Equipos los abren sus onclick y los cierra equipos_index.js (clic fuera).
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

// Global handler para Cierre de Sesión (Previene doble click y muestra spinner Inmediato)
document.addEventListener('submit', function (e) {
    if (e.target && e.target.action && e.target.action.includes('logout')) {
        if (typeof window.showPreloader === 'function') window.showPreloader();
        const btn = e.target.querySelector('button[type="submit"]');
        if (btn) {
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.5';
        }
    }
});
