{{--
    Modal "Vincular a una ficha del catálogo" de /admin/equipos. Se abre con doble clic en la
    foto de un equipo (table_rows: data-vincular) y solo para super.admin: se elige la ficha
    (caracteristicas_modelo) y el equipo queda con su ID_ESPEC, así que toma la foto de su
    color o la del modelo (Equipo::fotoParaMostrar). Sirve para las unidades que no
    encontraron solas su ficha (el modelo escrito distinto, otro año, varias fichas).

    Las fichas las busca CaracteristicaModeloController::elegir (mismas tarjetas que el
    catálogo) y el vínculo lo guarda EquipoController::vincularFicha. El comportamiento vive
    en js/maquinaria/vincular_ficha.js, que se descarga la primera vez que se abre.
--}}
@can('super.admin')
<style>
    #vfModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:10000; align-items:center; justify-content:center; padding:16px; }
    #vfModal.open { display:flex; }
    #vfModal .vf-box { background:#fff; border-radius:14px; width:100%; max-width:860px; max-height:90vh; box-shadow:0 20px 50px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; }
    /* Encabezado como el de los demás modales (ver .devm-head en almacen/devolucion_modal). */
    #vfModal .vf-head { padding:14px 48px; background:#1e293b; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
    #vfModal .vf-head h3 { margin:0; font-size:15px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px; text-align:center; }
    #vfModal .vf-head h3 .material-icons { color:#0067b1; }
    #vfModal .vf-x { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:#fff; opacity:.75; background:none; border:none; padding:0; display:flex; }
    #vfModal .vf-x:hover { opacity:1; }
    #vfModal .vf-equipo { padding:10px 18px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; font-size:12.5px; color:#334155; display:flex; flex-wrap:wrap; gap:4px 12px; align-items:center; flex-shrink:0; }
    #vfModal .vf-equipo b { color:#0f172a; }
    #vfModal .vf-filtros { padding:12px 18px 0; display:flex; gap:8px; flex-wrap:wrap; flex-shrink:0; }
    #vfModal .vf-input { border:1px solid #cbd5e0; border-radius:8px; padding:9px 10px; font-size:14px; outline:none; background:#fff; color:#0f172a; box-sizing:border-box; }
    #vfModal .vf-input:focus { border-color:var(--maquinaria-blue,#0067b1); }
    #vfModal .vf-buscar { flex:1 1 220px; min-width:0; text-transform:uppercase; }
    #vfModal .vf-buscar::placeholder { text-transform:none; }
    #vfModal .vf-anio { flex:0 0 130px; }
    #vfModal .vf-body { padding:12px 18px 16px; overflow-y:auto; min-height:0; flex:1; }
    #vfModal .vf-aviso { font-size:12.5px; color:#64748b; text-align:center; padding:18px 8px; }
    #vfModal .vf-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(165px, 1fr)); gap:10px; }
    #vfModal .vf-card { border:2px solid #e2e8f0; border-radius:12px; overflow:hidden; background:#fff; cursor:pointer; text-align:left; padding:0; display:flex; flex-direction:column; font:inherit; color:inherit; transition:border-color .15s, box-shadow .15s; }
    #vfModal .vf-card:hover { border-color:#93c5fd; }
    #vfModal .vf-card:focus-visible { outline:2px solid #0067b1; outline-offset:2px; }
    #vfModal .vf-card.sel { border-color:#0067b1; box-shadow:0 0 0 3px rgba(0,103,177,0.18); }
    #vfModal .vf-foto { position:relative; height:105px; background:#f8fafc; display:flex; align-items:center; justify-content:center; }
    #vfModal .vf-foto img { width:100%; height:100%; object-fit:contain; }
    #vfModal .vf-foto .material-icons { font-size:34px; color:#cbd5e1; }
    #vfModal .vf-badge { position:absolute; top:6px; font-size:10px; font-weight:700; color:#fff; padding:2px 7px; border-radius:999px; display:inline-flex; align-items:center; gap:3px; }
    #vfModal .vf-badge .material-icons { font-size:11px; color:#fff; }
    #vfModal .vf-badge.anio  { right:6px; background:rgba(0,103,177,0.92); }
    #vfModal .vf-badge.total { right:6px; top:30px; background:rgba(15,23,42,0.85); }
    #vfModal .vf-badge.actual { left:6px; background:rgba(22,163,74,0.95); }
    #vfModal .vf-info { padding:8px 10px; display:flex; flex-direction:column; gap:5px; }
    #vfModal .vf-nombre { font-size:12px; font-weight:700; color:#0f172a; text-transform:uppercase; line-height:1.3; word-break:break-word; }
    #vfModal .vf-colores { display:flex; flex-wrap:wrap; gap:4px; }
    #vfModal .vf-color { width:11px; height:11px; border-radius:50%; border:1px solid rgba(15,23,42,0.25); }
    #vfModal .vf-color.suyo { box-shadow:0 0 0 2px #fff, 0 0 0 3px #0067b1; }
    #vfModal .vf-foot { padding:12px 18px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:center; gap:8px; flex-shrink:0; }
    #vfModal .vf-foot .btn-primary-maquinaria:disabled { opacity:.5; cursor:not-allowed; }
    #vfModal .vf-btn-cancelar { background:#e2e8f0; color:#475569; box-shadow:none; }
    @media (max-width: 520px) {
        #vfModal { padding:8px; }
        #vfModal .vf-head { padding:12px 40px; }
        #vfModal .vf-anio { flex:1 1 100%; }
        #vfModal .vf-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
</style>

<div id="vfModal" role="dialog" aria-modal="true" aria-labelledby="vfTitulo"
     data-url-elegir="{{ route('catalogo.elegir') }}"
     data-url-vincular="{{ route('equipos.vincularFicha', '__ID__') }}">
    <div class="vf-box">
        <div class="vf-head">
            <h3 id="vfTitulo"><i class="material-icons">link</i>Vincular a una ficha del catálogo</h3>
            <button type="button" class="vf-x" data-vf-cerrar aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="vf-equipo" id="vfEquipo"></div>
        <div class="vf-filtros">
            <input type="text" id="vfBuscar" class="vf-input vf-buscar" placeholder="Buscar modelo o tipo…" autocomplete="off">
            <select id="vfAnio" class="vf-input vf-anio" aria-label="Año">
                <option value="">Todos los años</option>
            </select>
        </div>
        <div class="vf-body" id="vfResultados"></div>
        <div class="vf-foot">
            <button type="button" class="btn-primary-maquinaria vf-btn-cancelar" data-vf-cerrar>Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="vfVincular" disabled>
                <i class="material-icons">link</i> Vincular
            </button>
        </div>
    </div>
</div>

<script>
    // Abre el modal; el módulo (vincular_ficha.js) se descarga la primera vez. Se redefine
    // en cada montaje SPA a propósito: es un envoltorio sin estado.
    window.eqVincularFicha = function (fotoEl) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/maquinaria/vincular_ficha.js') . '?v=' . @filemtime(public_path('js/maquinaria/vincular_ficha.js')) }}",
            function () { return !!window.VincularFicha; }
        ).then(function () {
            window.VincularFicha.abrir(fotoEl);
        }).catch(function () {
            window.toast('No se pudo abrir el catálogo. Revisa tu conexión.', 'error');
        });
    };
</script>
@endcan
