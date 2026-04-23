/**
 * consumibles_graficos.js
 * Registro en ModuleManager para compatibilidad SPA.
 *
 * Flujo:
 *  - Primera navegación SPA a esta página: el script inline de graficos.blade.php
 *    llama cargarDatos() y activa la bandera window._graficosDataLoaded = true.
 *    El ModuleManager respeta esa bandera y NO duplica la llamada.
 *  - Navegación de vuelta (botón atrás / re-visita): la bandera NO existe,
 *    así que el ModuleManager invoca cargarDatos() normalmente.
 */
if (typeof window.ModuleManager !== 'undefined') {
    window.ModuleManager.register('consumibles_graficos',
        () => document.getElementById('resumenGrid') !== null,
        () => {
            // Si el script inline ya cargó los datos en esta navegación, omitir.
            if (window._graficosDataLoaded) {
                window._graficosDataLoaded = false; // reset para la próxima visita
                return;
            }
            if (typeof window.cargarDatos === 'function') {
                window.cargarDatos();
            }
        }
    );
}

