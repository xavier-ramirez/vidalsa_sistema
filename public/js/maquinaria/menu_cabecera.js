// Desplegables de la cabecera (Flota, Almacén, Configuraciones) — delegación en
// document, igual que los grupos del menú móvil (layout_ui.js).
// Va en el <head> y no con layout_ui.js al final del body: la cabecera se ve y se puede
// pulsar mientras todavía se descargan los <script> del final (~1,2 MB tras un deploy,
// cuando cambian todos los ?v=), y hasta que llegaba layout_ui.js el clic no hacía nada
// y parecía que "Flota" tardaba en desplegar. Solo usa `document`, que en el <head> ya
// existe. Delegando sobrevive a los re-renders de la SPA; el guard evita duplicarlo.
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
