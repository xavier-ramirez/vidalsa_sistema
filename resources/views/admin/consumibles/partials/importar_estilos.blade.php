{{-- Estilos de la subida de Excel de gasoil en Cargar consumibles. Prefijo imp- para no chocar con nada. --}}
<style>
    /* «Carga Masiva desde Excel» de Cargar consumibles: como la de Registrar equipos
       (admin.partials.bulk_upload_card). Se le pueden soltar los archivos encima. */
    .imp-carga { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
        padding:10px 20px; margin-bottom:20px; border:2px dashed transparent; }
    .imp-carga.arrastrando { border-color:#3b82f6; background:#eff6ff; }
    .imp-carga-titulo { margin:0; color:#0067b1; font-size:14.5px; font-weight:700; }
    .imp-carga-titulo i { vertical-align:middle; }
    .imp-carga-sub { margin:4px 0 0; color:#718096; font-size:12px; }
    .imp-carga .imp-carga-btn { padding:7px 13px; font-size:12.5px; margin:0; cursor:pointer; }
    .imp-carga .imp-carga-btn .material-icons { font-size:16px; }
</style>
