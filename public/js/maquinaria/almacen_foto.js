/**
 * Foto de los productos en Recepción (la tabla de Recepción nueva y el modal de la nota de la
 * bandeja): la misma miniatura y el mismo visor que el Inventario (admin_almacen_producto.css).
 *
 *   AlmFoto.mini(url, nombre[, fija]) → html de la miniatura (o el recuadro gris sin foto);
 *                               fija = sin abrir el visor al tocarla (la lista de sugerencias
 *                               del buscador: ahí tocar la fila elige el producto).
 *   AlmFoto.de(lista, id)     → la foto (FOTO) del producto `id` en el catálogo `lista`.
 *   AlmFoto.pintar(raiz)      → cambia los <span data-alm-foto> del HTML del servidor por
 *                               su miniatura.
 *   AlmFoto.ver(src)          → abre la foto en grande. Solo para VER: subir, cambiar y
 *                               eliminar la foto se hace en el Inventario (almacen.foto).
 *
 * El visor del Inventario (almVerFoto, almacen_index.js) es otro: además trae cambiar y
 * eliminar, y adelanta la descarga de la foto entera de las filas que están a la vista.
 */
(function () {
    'use strict';
    if (window.AlmFoto) return;

    function h(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Miniatura de Drive (?sz=w120, la misma medida que la tabla del Inventario) o el
    // recuadro gris con el ícono. Tocarla abre el visor sin tocar la fila (stopPropagation:
    // en el modal de la nota, tocar la fila la marca como recibida).
    function mini(url, nombre, fija) {
        if (!url) return '<span class="alm-foto alm-foto-sin" title="Sin foto"></span>';
        var src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'sz=w120';
        return '<img src="' + h(src) + '" alt="' + h(nombre || '') + '" class="alm-foto' + (fija ? ' alm-foto-fija' : '') + '" loading="lazy"'
            + (fija ? '>' : ' title="Ver la foto" onclick="event.stopPropagation(); window.AlmFoto.ver(this.src)">');
    }

    // id → foto del catálogo. El mapa se rehace solo cuando cambia la lista (llega del
    // servidor o se le suma un producto creado ahí mismo), no en cada repintado.
    var fotos = { lista: null, largo: -1, mapa: null };
    function de(lista, id) {
        lista = lista || [];
        if (fotos.lista !== lista || fotos.largo !== lista.length) {
            fotos = { lista: lista, largo: lista.length, mapa: new Map() };
            lista.forEach(function (p) { if (p.FOTO) fotos.mapa.set(Number(p.ID_PRODUCTO), p.FOTO); });
        }
        return fotos.mapa.get(Number(id)) || null;
    }

    // HTML que pinta el SERVIDOR (el modal de la nota): deja un <span data-alm-foto="url"
    // data-nombre="…"> por fila y aquí se cambia por la miniatura. Así el HTML de la
    // miniatura vive en un solo sitio (mini), no repetido en Blade.
    function pintar(raiz) {
        if (!raiz) return;
        raiz.querySelectorAll('[data-alm-foto]').forEach(function (s) {
            s.outerHTML = mini(s.getAttribute('data-alm-foto'), s.getAttribute('data-nombre'));
        });
    }

    // El visor se crea la primera vez que se usa, colgado del <body>: así no hace falta en
    // el HTML de cada pantalla y sobrevive a la navegación SPA (el contenido se reemplaza;
    // el body no).
    function visor() {
        var v = document.getElementById('almFotoVisor');
        if (v) return v;
        v = document.createElement('div');
        v.id = 'almFotoVisor';
        v.className = 'alm-visor-foto';
        v.innerHTML = '<i class="material-icons alm-visor-x" title="Cerrar">close</i>'
            + '<div class="alm-visor-caja" onclick="event.stopPropagation()"><img alt="Foto del producto"></div>';
        v.addEventListener('click', cerrar);
        document.body.appendChild(v);
        return v;
    }

    // Abre con la miniatura (ya en caché) a tamaño de visor y la cambia por la foto entera
    // en cuanto llega; si la entera falla, se queda la miniatura a su tamaño y se avisa.
    function ver(src) {
        if (!src) return;
        var v = visor(), img = v.querySelector('img');
        var entera = String(src).replace(/[?&]sz=[^&]*$/, '');
        img.dataset.entera = entera;
        img.src = src;
        img.classList.add('alm-visor-previa');
        v.classList.add('abierto');
        var pre = new Image();
        pre.onload = function () {
            if (img.dataset.entera !== entera) return;   // se cerró o se abrió otra
            img.src = entera;
            img.classList.remove('alm-visor-previa');
        };
        pre.onerror = function () {
            if (img.dataset.entera !== entera) return;
            img.classList.remove('alm-visor-previa');
            if (window.toast) window.toast('No se pudo abrir la foto en grande.', 'warning');
        };
        pre.src = entera;
    }

    function cerrar() {
        var v = document.getElementById('almFotoVisor');
        if (!v || !v.classList.contains('abierto')) return false;
        v.classList.remove('abierto');
        var img = v.querySelector('img');
        img.removeAttribute('src');
        img.classList.remove('alm-visor-previa');
        delete img.dataset.entera;
        return true;
    }

    // Escape cierra SOLO el visor: en captura, antes que el Escape del modal de la nota,
    // que si no se cerraría también.
    window.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && cerrar()) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    window.AlmFoto = { mini: mini, de: de, pintar: pintar, ver: ver };
})();
