{{-- Buscador de la fila de filtros: en las dos pestañas va a la derecha, después de los desplegables. --}}
<div class="filter-item aligned-filter responsive-filter-item">
    <form style="width: 100%;" onsubmit="event.preventDefault(); window.cpdfFiltrar();">
        <div class="search-wrapper" style="width: 100%; border-color: {{ $buscar !== '' ? '#0067b1' : '#cbd5e0' }}; background: {{ $buscar !== '' ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
            <i class="material-icons search-icon">search</i>
            <input type="text" id="cpdfBuscar" value="{{ $buscar }}"
                placeholder="{{ $pestana === 'documentos' ? 'Buscar placa, serial o nombre...' : 'Buscar serial...' }}"
                class="search-input-field" style="height: 100%;" autocomplete="off"
                oninput="document.getElementById('cpdfBuscarX').style.display = this.value ? 'block' : 'none';">
            <i id="cpdfBuscarX" class="material-icons clear-icon" style="display: {{ $buscar !== '' ? 'block' : 'none' }};"
               onclick="document.getElementById('cpdfBuscar').value=''; window.cpdfFiltrar();">close</i>
        </div>
    </form>
</div>
