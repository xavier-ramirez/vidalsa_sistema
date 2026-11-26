/**
 * Presentación de gasoil en Gráficos de consumibles. Las láminas las dibuja consumibles_laminas.js (el mismo diseño
 * del maestro del usuario); aquí va lo de la pantalla:
 *   - consumiblesGraficosPresentacion: la tarjeta de Gráficos, con SOLO los gráficos de la presentación
 *     (proyectos, cupo, histórico y cronograma) y el mes de los filtros de la pantalla (sin mes: el en curso);
 *   - consumiblesExportar: «Exportar» → Excel o PowerPoint. El PowerPoint pregunta el proyecto de la lámina de
 *     detalle y baja la presentación completa (portada, encabezados, fotos) con los filtros de la pantalla.
 * Los datos: ConsumiblesController::presentacionData (+ CuposCombustibleController::presentacion).
 */
(function () {
    'use strict';

    var mil = function (n) { return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };

    // Filtros de la pantalla (getParams de graficos.blade.php) + lo de aquí; la presentación es siempre de gasoil.
    function pedirDatos(url, filtros, extra) {
        var p = filtros ? filtros() : new URLSearchParams();
        p.delete('tipo');
        Object.keys(extra || {}).forEach(function (k) { if (extra[k]) p.set(k, extra[k]); });
        return (window.apiFetch || fetch)(url + '?' + p.toString(), { headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('El servidor no devolvió los datos (' + r.status + ').'); return r.json(); });
    }

    // ── Tarjeta de Gráficos ──
    var turno = 0;
    window.consumiblesGraficosPresentacion = function (cont, url, filtros) {
        var mio = ++turno;
        cont.style.opacity = '.5';
        return pedirDatos(url, filtros).then(function (d) {
            if (mio !== turno || !cont.isConnected) return null;    // llegó otra búsqueda después
            cont.style.opacity = '';
            if (d.vacio) { cont.innerHTML = '<p class="cons-pres-nota">No hay gasoil cargado.</p>'; return d; }
            window.GasoilLaminas.pintar(cont, d);
            return d;
        }).catch(function (e) {
            if (mio !== turno) return null;
            cont.style.opacity = '';
            cont.innerHTML = '<p class="cons-pres-nota">' + esc(e.message || 'No se pudieron armar los gráficos.') + '</p>';
            return null;
        });
    };

    // ── Diálogo «Exportar» ──
    window.consumiblesExportar = function (opc) {
        var viejo = document.getElementById('consExportarModal');
        if (viejo) viejo.remove();
        var m = document.createElement('div');
        m.id = 'consExportarModal';
        m.className = 'cons-exp-fondo';
        m.innerHTML =
            '<div class="cons-exp-caja" role="dialog" aria-modal="true" aria-labelledby="consExpTitulo">' +
            // Como «Descargar reporte» del menú: encabezado oscuro con el título centrado y la ✕ a la derecha.
            '<div class="cons-exp-cab"><span id="consExpTitulo"><i class="material-icons">download</i> Exportar</span>' +
            '<button type="button" class="cons-exp-x" data-cerrar title="Cerrar"><i class="material-icons">close</i></button></div>' +
            '<div class="cons-exp-cuerpo"><p class="cons-exp-texto">¿En qué formato lo quieres?</p>' +
            '<div class="cons-exp-opciones">' +
            '<button type="button" class="cons-exp-op cons-exp-op--excel" data-excel><i class="material-icons">table_chart</i><b>Excel</b></button>' +
            '<button type="button" class="cons-exp-op cons-exp-op--ppt" data-ppt><i class="material-icons">slideshow</i><b>PowerPoint</b></button>' +
            '</div>' +
            '<div class="cons-exp-ppt" hidden>' +
            '<label for="consExpProyecto">¿De qué proyecto es la lámina de detalle?</label>' +
            '<select id="consExpProyecto" disabled><option>Cargando…</option></select>' +
            '<div class="cons-exp-nota" data-nota></div>' +
            '<button type="button" class="btn-primary-maquinaria cons-exp-bajar" data-bajar disabled><i class="material-icons">download</i> Descargar PowerPoint</button>' +
            '</div></div></div>';
        document.body.appendChild(m);

        var cerrar = function () { m.remove(); document.removeEventListener('keydown', tecla); };
        var tecla = function (e) { if (e.key === 'Escape') cerrar(); };
        document.addEventListener('keydown', tecla);
        m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-cerrar]')) cerrar(); });
        m.querySelector('[data-excel]').addEventListener('click', function () { cerrar(); opc.excel(); });

        var sel = m.querySelector('#consExpProyecto'), nota = m.querySelector('[data-nota]'), bajar = m.querySelector('[data-bajar]');
        m.querySelector('[data-ppt]').addEventListener('click', function () {
            m.querySelector('.cons-exp-ppt').hidden = false;
            m.querySelectorAll('.cons-exp-op').forEach(function (b) { b.classList.toggle('activo', b.hasAttribute('data-ppt')); });
            pedirDatos(opc.urlDatos, opc.filtros).then(function (d) {
                if (d.vacio) { sel.innerHTML = '<option>Sin gasoil cargado</option>'; nota.textContent = ''; return; }
                if (!d.proyectos.length) {
                    // Sin proyectos en el período igual baja: portada, fotos, cupo e histórico con cronograma.
                    sel.innerHTML = '<option value="">Sin gasoil con proyecto en el período</option>';
                    bajar.disabled = false;
                    nota.textContent = 'Presentación de ' + d.cisternas.mes.toLowerCase() + '.';
                    return;
                }
                sel.innerHTML = d.proyectos.map(function (p) {
                    return '<option value="' + p.id + '"' + (d.proyecto && d.proyecto.id === p.id ? ' selected' : '') + '>' +
                        esc(p.nombre) + ' · ' + mil(p.litros) + ' L</option>';
                }).join('');
                sel.disabled = false; bajar.disabled = false;
                nota.textContent = 'Presentación de ' + d.cisternas.mes.toLowerCase() + ' (gasoil del ' + d.desde + ' al ' + d.hasta + ').';
            }).catch(function (e) { nota.textContent = e.message; });
        });

        bajar.addEventListener('click', function () {
            bajar.disabled = true; sel.disabled = true;
            nota.textContent = 'Armando la presentación…';
            pedirDatos(opc.urlDatos, opc.filtros, { id_proyecto: sel.value }).then(function (d) {
                if (d.vacio) throw new Error('No hay gasoil cargado.');   // (el botón no se activa en ese caso)
                return window.GasoilLaminas.bajar(d, 'Gestion_Combustible_' + d.cisternas.mes.replace(/\s+/g, '_') + '.pptx');
            }).then(cerrar).catch(function (e) {
                bajar.disabled = false; sel.disabled = false;
                nota.textContent = e.message || 'No se pudo armar la presentación.';
            });
        });
    };
})();
