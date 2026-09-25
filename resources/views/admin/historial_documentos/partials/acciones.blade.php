{{-- Botón "Acciones" de Control de Auditoría. Va al final de la fila de filtros de cada
     pestaña (junto a Filtros Avanzados en el Historial), así sale en las TRES. Solo
     super.admin, igual que sus entradas; la Papelera exige además user.delete (sin él
     avisa y no abre, ver abrirPapelera en partials/papelera.blade.php). --}}
@can('super.admin')
<div class="hd-acciones-wrap">
    <button type="button" id="hdBtnAcciones" class="btn-primary-maquinaria"
        onclick="window.hdToggleAcciones()"
        style="height: 45px; padding: 0 15px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap;">
        <i class="material-icons" style="font-size: 18px;">settings</i>
        <span>Acciones</span>
        <i class="material-icons" style="font-size: 16px;">expand_more</i>
    </button>
    <div id="hdAccionesMenu" style="display: none; position: absolute; top: 100%; right: 0; width: 290px; max-width: calc(100vw - 24px); background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 20px -5px rgba(15,23,42,0.18); margin-top: 6px; overflow: hidden; z-index: 60;">
        {{-- La carga masiva pide SU permiso (docs.carga.masiva), que ni super.admin hereda:
             sube PDF en lote y los engancha sola a la ficha que reconoce. Esto solo esconde
             el botón; lo que de verdad protege es la ruta y el controlador. --}}
        @can('docs.carga.masiva')
        <button type="button" class="dropdown-item-custom"
            onclick="window.hdCerrarAcciones(); window.abrirCargaMasiva && window.abrirCargaMasiva();"
            style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; background: transparent; border: none; border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left; cursor: pointer;">
            <div style="background: #dbeafe; padding: 6px; border-radius: 6px; display: flex;">
                <i class="material-icons" style="font-size: 18px; color: #0067b1;">cloud_upload</i>
            </div>
            {{-- "Carga masiva" a secas: el menú ya está dentro del módulo de documentos y el
                 nombre largo partía el botón en dos líneas. --}}
            <span style="font-size: 14px; font-weight: 500;">Carga masiva</span>
        </button>
        @endcan
        <button type="button" class="dropdown-item-custom"
            onclick="window.hdCerrarAcciones(); window.abrirPapelera && window.abrirPapelera();"
            style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; background: transparent; border: none; border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left; cursor: pointer;">
            <div style="background: #fef3c7; padding: 6px; border-radius: 6px; display: flex;">
                <i class="material-icons" style="font-size: 18px; color: #d97706;">delete_sweep</i>
            </div>
            <span style="font-size: 14px; font-weight: 500;">Papelera</span>
        </button>
    </div>
</div>
@endcan
