/**
 * Selector de TIPO y de EQUIPO VINCULADO (host) de los formularios de auxiliares.
 *
 * Estas nueve funciones estaban escritas DOS veces —en admin/equipos/create y en
 * admin/equipos_auxiliares/partials/form_fields— y las dos copias se habian
 * separado: la de auxiliares tenia guardas contra elementos ausentes y pintaba la
 * FOTO del equipo en los resultados; la de create no. Un arreglo en una no
 * llegaba a la otra. Aqui viven una sola vez, en la version buena.
 *
 * Las dos pantallas usan ids distintos para el input de tipo (`TIPO` en la ficha
 * del auxiliar, `input_tipo_aux` en el alta unificada), asi que se resuelve el
 * que exista: cada pagina solo tiene uno.
 *
 * La URL de busqueda la deja el Blade en window.AUX_HOST_SEARCH_URL, porque un
 * .js no puede resolver route().
 */
(function () {
    'use strict';

    /** El input de tipo, se llame como se llame en esta pantalla. */
    function inputDeTipo() {
        return document.getElementById('TIPO') || document.getElementById('input_tipo_aux');
    }

    // ── Tipo combobox (app style) ──
    window.auxTipoOpen = function (input) {
        const cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'block';
        window.auxTipoFilter(input);
    };
    window.auxTipoClose = function () {
        const cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'none';
    };
    window.auxTipoFilter = function (input) {
        const q = (input.value || '').toLowerCase().trim();
        document.querySelectorAll('#auxTipoContent .dropdown-item').forEach(it => {
            it.style.display = (!q || it.dataset.label.includes(q)) ? '' : 'none';
        });
    };
    window.auxTipoPick = function (label) {
        const input = inputDeTipo();
        if (input) input.value = label;
        window.auxTipoClose();
    };

    // ── Equipo Vinculado (host) picker ──
    let _hostDebounce = null;
    let _hostLastQuery = '';

    window.auxHostSearch = function (input) {
        const q = (input.value || '').trim();
        if (q.length < 2) {
            document.getElementById('hostResultsBox').style.display = 'none';
            return;
        }
        if (q === _hostLastQuery) return;
        _hostLastQuery = q;
        clearTimeout(_hostDebounce);
        _hostDebounce = setTimeout(() => {
            window.apiFetch(window.AUX_HOST_SEARCH_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => window.auxHostRender(data))
            .catch(err => console.error('searchHosts:', err));
        }, 280);
    };

    window.auxHostRender = function (rows) {
        const box = document.getElementById('hostResultsBox');
        if (!box) return;
        if (!rows || !rows.length) {
            box.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:12px;">Sin resultados.</div>';
            box.style.display = 'block';
            return;
        }
        // La placa, el codigo, el serial, el tipo y la marca son texto LIBRE que se
        // escribe en la ficha del equipo y acaban dentro de innerHTML: van TODOS
        // escapados. Antes solo se escapaban los data-*, asi que una placa como
        // `<img src=x onerror=...>` se ejecutaba al buscar un equipo vinculado.
        const esc = window.escapeHtml;   // helper central (dom_helpers.js)
        box.innerHTML = rows.map(r => {
            const dis = r.disponible ? '' : 'opacity:0.55; pointer-events:none;';
            const badge = r.disponible
                ? `<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Disponible</span>`
                : `<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Lleno (${r.auxiliares_anclados}/2)</span>`;
            // Identificacion principal: placa > codigo de patio > serial de chasis > #id.
            // Misma regla que el render inicial de la tarjeta (ver $hostTitulo arriba).
            const idPrincipal = r.placa || r.codigo || r.serial_chasis || ('#' + r.id);
            const idLabel     = r.placa ? 'Placa' : (r.codigo ? 'Código' : (r.serial_chasis ? 'Chasis' : 'ID'));
            // Para la card de seleccion: primary=ese mismo titulo, secondary=tipo+marca,
            // tertiary=codigo SOLO si no es ya el titulo (si no, se repetiria).
            const primary   = idPrincipal;
            const secondary = [r.tipo, r.marca].filter(x => x).join(' · ');
            const tertiary  = (r.codigo && r.codigo !== idPrincipal) ? ('Código: ' + r.codigo) : '';
            // Thumbnail: imagen si tiene, sino icono
            const thumb = r.foto
                ? `<img src="${esc(r.foto)}" alt="" style="width:48px;height:48px;border-radius:8px;object-fit:cover;background:#f1f5f9;flex-shrink:0;border:1px solid #e2e8f0;" onerror="this.outerHTML='<div style=&quot;width:48px;height:48px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;&quot;><i class=&quot;material-icons&quot; style=&quot;font-size:24px;&quot;>directions_car</i></div>'">`
                : `<div style="width:48px;height:48px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;"><i class="material-icons" style="font-size:24px;">directions_car</i></div>`;
            return `
                <div class="aux-host-card" style="padding:12px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; align-items:center; gap:12px; ${dis}"
                     onmousedown="event.preventDefault(); window.auxHostPick(${r.id}, this)"
                     data-primary="${esc(primary)}"
                     data-secondary="${esc(secondary)}"
                     data-tertiary="${esc(tertiary)}"
                     onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                    ${thumb}
                    <div style="flex:1; min-width:0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:4px;">
                            <strong style="color:#1e293b; font-size:13.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <span style="color:#94a3b8; font-size:10px; font-weight:600; text-transform:uppercase;">${idLabel}:</span> ${esc(idPrincipal)}
                            </strong>
                            ${badge}
                        </div>
                        <div style="font-size:12px; color:#475569; line-height:1.35;">
                            ${r.tipo ? `<span style="font-weight:600; color:#334155;">${esc(r.tipo)}</span>` : ''}
                            ${r.tipo && r.marca ? ' · ' : ''}
                            ${r.marca ? `<span>${esc(r.marca)}</span>` : ''}
                            ${!r.tipo && !r.marca ? '<em style="color:#94a3b8;">Sin información</em>' : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        box.style.display = 'block';
    };

    window.auxHostPick = function (id, el) {
        const hidden = document.getElementById('ID_EQUIPO_HOST');
        const search = document.getElementById('hostSearchInput');
        const wrapper = document.getElementById('hostSearchWrapper');
        const card    = document.getElementById('hostSelectedCard');
        if (hidden) hidden.value = id;
        // Poblar la tarjeta de seleccion con data-* del elemento clickeado
        const primary = document.getElementById('hostSelectedPrimary');
        const secondary = document.getElementById('hostSelectedSecondary');
        const tertiary = document.getElementById('hostSelectedTertiary');
        // auxHostRender ya decide si el codigo aporta algo: aqui solo se pinta.
        const tertiaryTxt = el.dataset.tertiary || '';
        if (primary)   primary.textContent   = el.dataset.primary || ('#' + id);
        if (secondary) secondary.textContent = el.dataset.secondary || '';
        if (tertiary) {
            tertiary.textContent   = tertiaryTxt;
            tertiary.style.display = tertiaryTxt ? 'inline' : 'none';
        }
        if (wrapper) wrapper.style.display = 'none';
        if (card)    card.style.display    = 'flex';
        if (search)  search.value = '';
        window.auxHostClose();
    };

    window.auxHostClose = function () {
        const box = document.getElementById('hostResultsBox');
        if (box) box.style.display = 'none';
    };

    window.auxHostClear = function () {
        const hidden  = document.getElementById('ID_EQUIPO_HOST');
        const search  = document.getElementById('hostSearchInput');
        const wrapper = document.getElementById('hostSearchWrapper');
        const card    = document.getElementById('hostSelectedCard');
        if (hidden) hidden.value = '';
        if (search) search.value = '';
        if (wrapper) wrapper.style.display = 'block';
        if (card)    card.style.display    = 'none';
        _hostLastQuery = '';
        window.auxHostClose();
        if (search) search.focus();
    };
})();
