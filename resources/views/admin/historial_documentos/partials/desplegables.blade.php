{{-- Abrir y cerrar los desplegables de la pantalla (Acciones y Filtros avanzados).
     Fuera del bloque del Historial: el botón Acciones sale en las tres pestañas. --}}
<script>
(function () {
    function ocultar(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    // Abre/cierra. Al abrir se ancla al borde derecho de su botón; si así se sale por
    // la izquierda (la fila se partió y el botón quedó al inicio de la línea, p. ej.
    // entre 900 y 1023 px de ancho), se ancla al izquierdo. En el teléfono manda el
    // CSS de la vista (con !important).
    function alternar(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var abrir = el.style.display === 'none' || !el.style.display;
        el.style.display = abrir ? 'block' : 'none';
        if (!abrir) return;
        el.style.left = 'auto';
        el.style.right = '0px';
        if (el.getBoundingClientRect().left < 8) {
            el.style.left = '0px';
            el.style.right = 'auto';
        }
    }
    window.hdCerrarAcciones = function () { ocultar('hdAccionesMenu'); };
    window.hdToggleAcciones = function () { alternar('hdAccionesMenu'); };
    window.hdToggleFiltrosAvanzados = function () { alternar('hdAdvancedFilterPanel'); };

    if (window.__hdDesplegablesBound) return;
    window.__hdDesplegablesBound = true;

    // Cierra lo que NO contiene al elemento que recibió el clic o el foco.
    function cerrarFuera(el) {
        // .hd-pest-fila = la barra de pestañas: existe en las TRES (antes se miraba la tabla
        // del Historial y, con el botón Acciones ya en todas, el menú no se cerraba en las otras).
        if (!el.closest || !document.querySelector('.hd-pest-fila')) return false;
        if (!el.closest('.hd-adv-filter-wrap')) ocultar('hdAdvancedFilterPanel');
        if (!el.closest('.hd-acciones-wrap')) ocultar('hdAccionesMenu');
        return true;
    }
    document.addEventListener('click', function (e) { cerrarFuera(e.target); });
    document.addEventListener('focusin', function (e) {
        if (!cerrarFuera(e.target)) return;
        var box = document.getElementById('hdCorreosSuggest');
        if (e.target.id === 'searchCorreo') {
            window.closeAllDropdowns(null);         // correo enfocado → cerrar el de acción
        } else if (box && !box.contains(e.target)) {
            box.style.display = 'none';             // foco en otro sitio → cerrar sugerencias
        }
    });
})();
</script>
