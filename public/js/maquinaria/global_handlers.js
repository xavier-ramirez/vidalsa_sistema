/**
 * Handlers globales del layout: window.togglePw (ver/ocultar contrasena), campos sin
 * autollenado del navegador (data-sin-autollenado), guard del submit de logout (evita doble click y levanta el spinner) y los handlers delegados
 * del modulo de Equipos (dropdown "Acciones" y panel de filtro avanzado), que se
 * registran en `document` para sobrevivir a la navegacion SPA y salen temprano si no
 * estan en la pagina de Equipos (guard por #splitDropdownMenu).
 *
 * Extraido del <script> inline de estructura_base.blade.php (2026-08-24), cola del
 * mismo bloque que preloader.js y offline_mode.js. Se carga DESPUES de los dos,
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

document.addEventListener('DOMContentLoaded', () => {


    // GLOBAL EVENT DELEGATION FOR EQUIPOS MODULE (SPA COMPATIBLE)
    // This ensures that "Acciones" and "Filter" buttons work even after AJAX content replacement
    window.equiposGlobalClickHandler = function (event) {
        // GUARD: Este handler solo actúa en la página de Equipos
        // (donde existe #splitDropdownMenu). En otras páginas (movilizaciones, etc.)
        // salimos inmediatamente para no interferir con sus propios handlers.
        const isEquiposPage = !!document.getElementById('splitDropdownMenu');
        if (!isEquiposPage) return;

        // Toggle Acciones Dropdown
        if (event.target.closest('#btnAcciones')) {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('splitDropdownMenu');
            const panel = document.getElementById('advancedFilterPanel');

            if (panel) panel.style.display = 'none';

            if (menu) {
                const isHidden = menu.style.display === 'none' || menu.style.display === '';
                menu.style.display = isHidden ? 'block' : 'none';
            }
            return;
        }

        // Toggle Advanced Filter Panel
        if (event.target.closest('#btnAdvancedFilter')) {
            event.preventDefault();
            event.stopPropagation();
            const panel = document.getElementById('advancedFilterPanel');
            const menu = document.getElementById('splitDropdownMenu');

            if (menu) menu.style.display = 'none';

            if (panel) {
                const isHidden = panel.style.display === 'none' || panel.style.display === '';
                panel.style.display = isHidden ? 'block' : 'none';
            }
            return;
        }

        // Close when clicking outside (solo en página de equipos)
        if (!event.target.closest('#advancedFilterPanel') &&
            !event.target.closest('#splitDropdownMenu') &&
            !event.target.closest('#btnAcciones') &&
            !event.target.closest('#btnAdvancedFilter')) {

            const menu = document.getElementById('splitDropdownMenu');
            const panel = document.getElementById('advancedFilterPanel');
            if (menu) menu.style.display = 'none';
            if (panel) panel.style.display = 'none';
        }
    };

    // Global Keyup for Filters
    window.equiposGlobalKeyupHandler = function (event) {
        if (event.target && event.target.id === 'searchModelInput') {
            const filter = event.target.value.toLowerCase();
            const list = document.getElementById('modelList');
            if (!list) return;
            const items = list.getElementsByClassName('filter-option-item');

            for (let i = 0; i < items.length; i++) {
                const txtValue = items[i].textContent || items[i].innerText;
                items[i].style.display = txtValue.toLowerCase().indexOf(filter) > -1 ? "" : "none";
            }
        }
    };

    // Clean & Attach Global Listeners
    document.removeEventListener('click', window.equiposGlobalClickHandler);
    document.addEventListener('click', window.equiposGlobalClickHandler);

    document.removeEventListener('keyup', window.equiposGlobalKeyupHandler);
    document.addEventListener('keyup', window.equiposGlobalKeyupHandler);

});
