{{-- Estilos de la carga de Excel de gasoil en Cargar consumibles (subida y «Cargas de Excel»). Prefijo imp- para no chocar con nada. --}}
<style>
    .imp-card { padding:25px; margin-bottom:20px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); }
    .imp-titulo { font-size:16px; font-weight:700; color:#1e293b; margin:0 0 6px; }
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
    .imp-tabla-wrap { overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px; }
    .imp-tabla { width:100%; border-collapse:collapse; font-size:13px; }
    .imp-tabla th { background:#1e293b; color:#e2e8f0; font-size:11px; text-transform:uppercase; letter-spacing:.4px; text-align:left; padding:9px 10px; white-space:nowrap; }
    .imp-tabla td { padding:8px 10px; border-top:1px solid #f1f5f9; color:#334155; vertical-align:top; }
    .imp-tabla .num { text-align:right; white-space:nowrap; }
    .imp-archivos { max-width:360px; }
    .imp-acciones-fila { white-space:nowrap; }
    .imp-link { background:none; border:none; padding:0 6px; color:#0067b1; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none; font-family:inherit; }
    .imp-link-rojo { color:#dc2626; }
    .imp-estado { border-radius:20px; padding:2px 10px; font-size:12px; font-weight:700; white-space:nowrap; }
    .imp-estado-aplicada { background:#d1fae5; color:#065f46; }
    .imp-estado-deshecha { background:#f1f5f9; color:#64748b; }
    @media (max-width:900px) {
        .imp-card { padding:16px; }
    }
</style>
