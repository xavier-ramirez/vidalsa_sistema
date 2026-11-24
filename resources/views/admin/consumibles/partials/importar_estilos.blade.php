{{-- Estilos de la carga de Excel de gasoil (subida en Cargar consumibles y revisión). Prefijo imp- para no chocar con nada. --}}
<style>
    .imp-cabecera { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
    .imp-card { padding:25px; margin-bottom:20px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); }
    .imp-acciones { display:flex; gap:10px; justify-content:flex-end; align-items:center; flex-wrap:wrap; margin-top:18px; }
    .imp-acciones .btn-primary-maquinaria { margin-left:0; }
    .imp-titulo { font-size:16px; font-weight:700; color:#1e293b; margin:0 0 6px; }
    .imp-sub { font-size:13px; color:#64748b; margin:0 0 14px; }

    .imp-aviso { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
    .imp-aviso-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

    .imp-zona { display:flex; align-items:center; gap:12px; padding:12px 16px; border:2px dashed #bfdbfe;
        border-radius:10px; background:#f0f9ff; color:#1d4ed8; text-align:center; cursor:pointer; }
    .imp-zona.arrastrando { background:#dbeafe; border-color:#3b82f6; }
    .imp-zona i { font-size:30px; flex-shrink:0; }
    .imp-zona-titulo { display:block; font-size:14px; font-weight:700; }
    .imp-zona small { display:block; font-size:12px; color:#475569; }

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
    .imp-estado-borrador { background:#fef3c7; color:#92400e; }
    .imp-estado-aplicada { background:#d1fae5; color:#065f46; }
    .imp-estado-deshecha { background:#f1f5f9; color:#64748b; }

    .imp-cifras { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; }
    .imp-cifra { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#f8fafc; }
    .imp-cifra strong { display:block; font-size:22px; font-weight:800; color:#1e293b; line-height:1.1; }
    .imp-cifra span { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#64748b; }
    .imp-cifra small { display:block; font-size:12px; color:#64748b; margin-top:2px; }
    .imp-cifra-ok strong { color:#059669; }
    .imp-cifra-alerta strong { color:#d97706; }

    .imp-ok { color:#059669; font-weight:700; }
    .imp-mal { color:#d97706; font-weight:700; }
    .imp-nota { display:block; font-size:12px; color:#64748b; margin-top:2px; }

    .imp-meses { display:flex; flex-wrap:wrap; gap:10px; }
    .imp-mes { display:flex; align-items:center; gap:8px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 12px; font-size:13px; cursor:pointer; background:#fff; }
    .imp-mes input { width:16px; height:16px; }
    .imp-mes small { color:#64748b; }

    .imp-select, .imp-input { box-sizing:border-box; width:100%; min-width:220px; height:36px; border:1px solid #cbd5e0; border-radius:8px;
        background:#fbfcfd; padding:0 10px; font-size:13px; font-family:inherit; }
    .imp-buscador { position:relative; }
    .imp-sugerencias { position:absolute; top:100%; left:0; right:0; z-index:300; background:#fff; border:1px solid #cbd5e0; border-radius:8px;
        box-shadow:0 8px 20px rgba(0,0,0,.12); max-height:240px; overflow-y:auto; margin-top:4px; display:none; }
    .imp-sugerencias div { padding:8px 10px; font-size:12px; cursor:pointer; border-bottom:1px solid #f1f5f9; }
    .imp-sugerencias div:hover { background:#f1f5f9; }
    .imp-elegido { display:block; font-size:12px; color:#059669; font-weight:600; margin-top:4px; }
    .imp-no-equipo { display:flex; align-items:center; gap:6px; font-size:12px; color:#475569; white-space:nowrap; margin-top:6px; }
    .imp-mas { margin-top:10px; }
    .imp-texto-excel { font-family:monospace; font-size:12px; color:#1e293b; word-break:break-word; }

    @media (max-width:900px) {
        .imp-card { padding:16px; }
        .imp-acciones { justify-content:center; }
        .imp-select, .imp-input { min-width:160px; }
    }
</style>
