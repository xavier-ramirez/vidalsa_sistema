/**
 * consumibles_graficos.js
 * Registro en ModuleManager para compatibilidad SPA.
 * La carga inicial de datos la hace el script inline de la vista.
 * Al navegar de vuelta (botón atrás del navegador), el ModuleManager
 * detecta la página por #resumenGrid y llama cargarDatos() de nuevo.
 */
if (typeof window.ModuleManager !== 'undefined') {
    window.ModuleManager.register('consumibles_graficos',
        () => document.getElementById('resumenGrid') !== null,
        () => {
            if (typeof window.cargarDatos === 'function') {
                window.cargarDatos();
            }
        }
    );
}
