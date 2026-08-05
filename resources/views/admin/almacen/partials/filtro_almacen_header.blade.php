{{-- ════════════════════════════════════════════════════════════════════════
     Filtro "Almacén" que va JUNTO AL TÍTULO en las bitácoras de Almacén.
     Lo comparten /admin/almacen/movimientos y /admin/almacen/notas: el markup
     era byte a byte el mismo en las dos, solo cambiaba el id del dropdown.

     Se usa como slot `acciones` de admin.partials.page_header:

        @include('admin.partials.page_header', [
            'titulo'    => '…',
            'acciones'  => 'admin.almacen.partials.filtro_almacen_header',
            'filtroId'  => 'almMovFiltroAlmacen',
            'separador' => true,
        ])

     Variables que espera del controller (ya las inyectan ambas vistas):
        $almacenes   colección de almacenes visibles
        $almSel      almacén seleccionado o null
        $reqAlmacen  id crudo del request (para el input oculto)

     NO se aplica .filter-active aunque haya almacén seleccionado: junto al título
     se lee como parte del encabezado, no como un filtro avanzado por accionar. El
     estado "activo" se ve en el nombre del almacén que queda de placeholder.
═══════════════════════════════════════════════════════════════════════════ --}}
<div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
    <div style="width:280px;min-width:200px;max-width:100%;">
        <div class="custom-dropdown" id="{{ $filtroId }}" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
            <input type="hidden" name="id_almacen" data-filter-value value="{{ $reqAlmacen ?: '' }}">
            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                       placeholder="{{ $almSel ? $almSel->NOMBRE : 'Todos los almacenes' }}"
                       style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                       oninput="window.filterDropdownOptions(this)">
            </div>
            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                    <div class="dropdown-item {{ !$almSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('{{ $filtroId }}','all','TODOS LOS ALMACENES');">TODOS LOS ALMACENES</div>
                    @foreach(($almacenes ?? collect()) as $a)
                        <div class="dropdown-item {{ $almSel && $almSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                             onclick="selectOption('{{ $filtroId }}','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                            {{ $a->NOMBRE }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
