{{-- Cargar consumibles → «Carga Masiva desde Excel» (solo super.admin, como sus rutas), arriba de
     la tabla y con el aspecto de la de Registrar equipos (admin.partials.bulk_upload_card): los
     Excel de gasoil elegidos con «Cargar Excel» o soltados en la tarjeta (se leen al elegirlos)
     pasan por el lector (ConsumiblesImportacionController, columnas por encabezado) y sus
     despachos caen en la MISMA tabla de abajo que lo pegado con Ctrl+V (26-11, pedido del
     usuario). Al guardar, los que ya estaban cargados se saltan. --}}
@include('admin.consumibles.partials.importar_estilos')

<div id="impZona" class="admin-card imp-carga">
    <div>
        <h3 class="imp-carga-titulo"><i class="material-icons">upload_file</i> Carga Masiva desde Excel</h3>
        <p class="imp-carga-sub">Sube los Excel de gasoil (uno o varios, en cualquier formato) o suéltalos aquí. Los despachos aparecen en la tabla de abajo; al guardar, los que ya estaban cargados se saltan.</p>
    </div>
    <label for="impArchivos" class="btn-primary-maquinaria imp-carga-btn">
        <i class="material-icons">upload</i> Cargar Excel
    </label>
    <input type="file" id="impArchivos" multiple accept=".xlsx,.xlsm,.xls" hidden>
</div>

{{-- Los Excel ya guardados (para borrar uno entero) están en el modal «Excel cargados» del menú Acciones (partials/excel_cargados). --}}

<script>
    window.CONS_IMP_CFG = { subir: @json(route('consumibles.importar.subir')), columnas: @json(route('consumibles.importar.columnas')), pegar: @json(route('consumibles.importar.pegar')) };
</script>
<script src="{{ asset('js/maquinaria/consumibles_importar.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_importar.js')) }}"></script>
<script>window.consImpArrancar(window.CONS_IMP_CFG);</script>
