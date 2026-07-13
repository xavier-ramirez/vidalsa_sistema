@extends('layouts.estructura_base')
@section('title', 'Calculadora de Filtros')

@section('content')
{{-- Arial se usa por defecto, no se necesita importar fuente externa --}}
<script src="{{ asset('js/html2canvas.min.js') }}?v={{ @filemtime(public_path('js/html2canvas.min.js')) }}"></script>

<style>
    * { box-sizing: border-box; }
    body, .main-viewport { background: #f1f5f9 !important; font-family: Arial, sans-serif !important; }
    .cm-page { font-family: Arial, sans-serif; max-width: 1400px; margin: 0 auto; padding: 10px 0 40px; }
    /* Protegemos los iconos para que no se conviertan en texto */
    .material-icons { font-family: 'Material Icons' !important; }
    .cm-title { font-size: 22px; font-weight: 800; color: #1e293b; display:flex; align-items:center; gap:8px; margin-bottom: 20px; }
    
    /* Layout: Solo tabla arriba, gráfico abajo (ancho completo) */
    .cm-grid { display: flex; flex-direction: column; gap: 20px; }

    /* ── Tarjeta Izquierda (Tabla) ── */
    .cm-card { background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(0,0,0,.06); border: 1px solid #e2e8f0; }
    .cm-card-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing:1.2px; color:#64748b; margin-bottom: 14px; display:flex; align-items:center; gap:6px; }
    .cm-hints { font-size: 12px; color: #0f766e; margin-bottom: 12px; padding: 10px 14px; background: #f0fdfa; border-radius: 8px; border: 1px dashed #5eead4; line-height: 1.5; }
    
    /* ── Tabla Data ── */
    #editableTable { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    #editableTable th { background: #1e3a5f; color: #fff; padding: 12px; text-align: left; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 2px solid #0f172a; }
    #editableTable th:first-child { text-align: center; }
    #editableTable th:last-child { text-align: center; }
    #editableTable tbody tr { transition: background 0.15s; border-bottom: 1px solid #f1f5f9; }
    #editableTable tbody tr:hover { background: #f8fafc; }
    #editableTable td { padding: 4px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #f1f5f9; vertical-align: middle; }
    #editableTable td:last-child { border-right: none; }
    
    #editableTable input {
        width: 100%; border: 1px solid transparent; border-radius: 6px;
        padding: 8px 10px; font-size: 13px; font-family: Arial, sans-serif;
        background: transparent; outline: none; transition: all .2s; color: #1e293b;
    }
    #editableTable input:focus { border-color: #0ea5e9; background: #f0f9ff; }
    #editableTable input.eq-qty { text-align: center; font-weight: 800; color: #ea580c; background: #fff7ed; }
    #editableTable input.eq-qty:focus { background: #ffedd5; }
    #editableTable input.fil-qty { text-align: center; font-weight: 800; color: #0369a1; background: #f0f9ff; }
    #editableTable input.fil-qty:focus { background: #e0f2fe; }

    /* Indicador visual de jerarquía */
    .is-eq-row { border-left: 4px solid #ea580c !important; }
    .is-fil-row { border-left: 4px solid #0ea5e9 !important; }

    .btn-add-row { margin-top: 15px; background: #f1f5f9; border: 1.5px dashed #94a3b8; color: #475569; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s; display: flex; align-items:center; gap:5px; width: 100%; justify-content: center; }
    .btn-add-row:hover { background: #e0f2fe; border-color: #0ea5e9; color: #0ea5e9; }
    .btn-del-row { background: none; border: none; cursor: pointer; color: #cbd5e0; font-size: 18px; line-height:1; transition: color .2s; width: 100%; }
    .btn-del-row:hover { color: #ef4444; }

    .cm-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
    .btn-generate { background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; border: none; border-radius: 10px; padding: 12px 24px; font-size: 14px; font-weight: 700; cursor: pointer; display:flex; align-items:center; gap:6px; transition: opacity .2s; box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
    .btn-generate:hover { opacity: .9; }
    .btn-clear { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 20px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .2s; }
    .btn-clear:hover { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }

    /* ── Panel Visual (debajo de la tabla) ── */
    #reportPanel { background: #f1f5f9; border-radius: 14px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.1); border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 16px; }
    
    /* Header azul oscuro (igual al de equipos) */
    .rp-top-card { background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.15); position: relative; overflow: hidden; }
    .rp-top-card .deco-icon { position: absolute; right: -15px; bottom: -15px; font-size: 90px; opacity: 0.1; transform: rotate(-15deg); }
    .rp-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
    .rp-date { font-size: 11px; color: rgba(255,255,255,.6); font-weight: 600; }
    .rp-title-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
    
    /* Totales dentro del header azul */
    .rp-totals-row { display: flex; gap: 8px; }
    .t-box { flex: 1; background: rgba(255,255,255,0.15); border-radius: 10px; padding: 10px 8px; display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .t-box-icon { font-size: 16px; opacity: 0.8; margin-bottom: 2px; }
    .t-value { font-size: 32px; font-weight: 800; line-height: 1; position: relative; z-index: 2; }
    .t-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.85; position: relative; z-index: 2; }

    /* Lista vertical tipo gráfico (un equipo por fila) */
    .rp-list-area { padding: 14px 16px; display: flex; flex-direction: column; gap: 14px; }
    .rp-list-area .empty-state { text-align:center; color:#94a3b8; padding:30px 20px; }

    /* Fila de gráfico por equipo */
    .eq-row { border-bottom: 1px dashed #f1f5f9; padding-bottom: 12px; }
    .eq-row:last-child { border-bottom: none; padding-bottom: 0; }
    .eq-row-name { font-size: 12px; font-weight: 800; color: #1e293b; text-transform: uppercase; margin-bottom: 7px; display: flex; justify-content: space-between; align-items: center; }
    .eq-row-name-text { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .eq-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
    .eq-bar-label { font-size: 10px; font-weight: 700; color: #94a3b8; width: 60px; text-transform: uppercase; flex-shrink: 0; }
    .eq-bar-track { flex: 1; height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
    /* Tonos verdes como en admin/equipos */
    .eq-bar-fill-eq  { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #4ade80, #22c55e); transition: width .5s ease; }
    .eq-bar-fill-fil { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #3b82f6, #1d4ed8); transition: width .5s ease; }
    .eq-bar-val { font-size: 11px; font-weight: 800; color: #1e293b; width: 32px; text-align: right; flex-shrink: 0; }

    /* Empty State */
    .empty-state { text-align: center; color: #94a3b8; padding: 40px 20px; font-weight: 600; font-size: 13px; }
    .empty-state i { font-size: 40px; opacity: 0.5; margin-bottom: 10px; display: block; }

    /* Descargar */
    .btn-download { background: #f1f5f9; border: 1px solid #cbd5e1; color:#0f172a; border-radius: 10px; padding: 12px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px; width:100%; justify-content:center; margin-top: 10px; transition: all .2s; }
    .btn-download:hover { background: #e1effa; border-color: #0ea5e9; color: #0ea5e9; box-shadow: 0 4px 10px rgba(14,165,233,0.1); }

    /* ── Panel Resumen por Tipo de Filtro ── */
    #summaryPanel { background: #fff; border-radius: 14px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,.08); border: 1px solid #e2e8f0; margin-top: 20px; }
    .sp-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
    .sp-title { font-size: 14px; font-weight: 800; text-transform: uppercase; color: #1e293b; display: flex; align-items: center; gap: 8px; }
    .sp-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .sp-badge { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
    .sp-badge-eq { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
    .sp-badge-fil { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

    .sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
    .sp-fil-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; }
    .sp-fil-name { font-size: 12px; font-weight: 700; color: #1e293b; word-break: break-word; line-height: 1.4; }
    .sp-fil-meta { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .sp-fil-total { font-size: 22px; font-weight: 800; color: #0369a1; }
    .sp-fil-total-label { font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; }
    .sp-fil-eq-used { font-size: 11px; font-weight: 700; padding: 3px 8px; background: #fff7ed; color: #ea580c; border-radius: 6px; border: 1px solid #fed7aa; white-space: nowrap; }
    .sp-fil-bar-track { height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
    .sp-fil-bar-fill { height: 100%; background: linear-gradient(90deg, #38bdf8, #0ea5e9); border-radius: 3px; transition: width .5s ease; }

    /* Custom scrollbar */
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>

<div class="cm-page">
    <div class="cm-title">
        <i class="material-icons" style="color:#0ea5e9; font-size:28px;">filter_alt</i>
        Calculadora de Requerimiento de Filtros
    </div>

    <div class="cm-grid">

        {{-- ════ TABLA DE INGRESO ════ --}}
        <div class="cm-card">
            <div class="cm-card-title">
                <i class="material-icons" style="font-size:16px; color:#0ea5e9;">table_chart</i>
                Matriz de Equipos y Filtros
            </div>

            <div class="cm-hints">
                <i class="material-icons" style="font-size:14px; vertical-align:middle; margin-right:4px;">info</i>
                Copia directamente tu tabla de excel que contiene las 3 columnas juntas y pégala en la primera celda. El sistema detectará las celdas combinadas de la izquierda y organizará cada filtro bajo su equipo correspondiente.
            </div>

            <table id="editableTable">
                <thead>
                    <tr>
                        <th style="width:15%">CANT. EQUIPOS</th>
                        <th style="width:65%">EQUIPO / FILTROS (MODELO / N/P)</th>
                        <th style="width:15%">CANT. FILTROS</th>
                        <th style="width:5%"></th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    {{-- JS Rows --}}
                </tbody>
            </table>

            <button class="btn-add-row" onclick="addRow()">
                <i class="material-icons" style="font-size:16px;">add</i> Añadir nueva fila manualmente
            </button>

            <div class="cm-actions">
                <button class="btn-generate" onclick="generateReport()">
                    <i class="material-icons" style="font-size:18px;">draw</i>
                    Generar Visualización
                </button>
                <button class="btn-clear" onclick="clearTable()">
                    <i class="material-icons" style="font-size:16px;">delete_sweep</i>
                    Limpiar Todo
                </button>
            </div>
        </div>

    </div>{{-- fin cm-grid --}}

    {{-- ════ PANEL CONSOLIDADO (ancho completo, debajo de la tabla) ════ --}}
    <div id="reportPanel" style="display:none;">

        {{-- Tarjeta Azul: Título + Totales --}}
        <div class="rp-top-card">
            <i class="material-icons deco-icon">filter_alt</i>
            <div class="rp-title-row">
                <div class="rp-title">
                    <i class="material-icons" style="font-size:15px;">assignment</i>
                    Consolidado por Tipo de Equipo
                </div>
                <div class="rp-date" id="reportDate"></div>
            </div>
            <div class="rp-totals-row">
                <div class="t-box">
                    <i class="material-icons t-box-icon">local_shipping</i>
                    <span class="t-value" id="tot_eq">0</span>
                    <span class="t-label">Equipos</span>
                </div>
                <div class="t-box">
                    <i class="material-icons t-box-icon">filter_alt</i>
                    <span class="t-value" id="tot_fil">0</span>
                    <span class="t-label">Filtros Req.</span>
                </div>
            </div>
        </div>

        {{-- Gráfico vertical por Tipo de Equipo --}}
        <div style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
            <div style="padding: 12px 16px; border-bottom: 2px solid #f1f5f9; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #64748b; display: flex; align-items: center; gap: 6px;">
                <i class="material-icons" style="font-size:16px; color:#3b82f6;">bar_chart</i>
                Distribución por Tipo de Equipo
            </div>
            <div id="renderArea" style="padding: 14px 16px; display: flex; flex-direction: column; gap: 14px;">
                <div class="empty-state">
                    <i class="material-icons">inventory_2</i>
                    La visualización aparece aquí al generar.
                </div>
            </div>
        </div>

        <button class="btn-download" onclick="downloadImage('reportPanel','Requerimiento_Filtros')">
            <span id="downloadSpinner" style="display:none; animation:spin 1s linear infinite;"><i class="material-icons" style="font-size:18px;">refresh</i></span>
            <i class="material-icons" style="font-size:20px;" id="downloadIcon">download_for_offline</i>
            Descargar Vista por Equipo
        </button>
    </div>

    {{-- ════ PANEL RESUMEN POR TIPO DE FILTRO (ancho completo) ════ --}}
    <div id="summaryPanel" style="display:none;">
        <div class="sp-header">
            <div class="sp-title">
                <i class="material-icons" style="color:#0369a1;">summarize</i>
                Resumen Total de Filtros por Tipo (N/P)
            </div>
            <div class="sp-badges">
                <span class="sp-badge sp-badge-eq">
                    <i class="material-icons" style="font-size:12px; vertical-align:middle;">local_shipping</i>
                    <span id="sum_tot_eq">0</span> Equipos Asignados
                </span>
                <span class="sp-badge sp-badge-fil">
                    <i class="material-icons" style="font-size:12px; vertical-align:middle;">filter_alt</i>
                    <span id="sum_tot_fil">0</span> Filtros Totales
                </span>
                <button class="btn-download" style="margin-top:0; width:auto; padding: 6px 14px; font-size:12px;" onclick="downloadImage('summaryPanel','Resumen_Filtros_Tipo')">
                    <span id="downloadSpinner2" style="display:none; animation:spin 1s linear infinite;"><i class="material-icons" style="font-size:16px;">refresh</i></span>
                    <i class="material-icons" style="font-size:16px;" id="downloadIcon2">download_for_offline</i>
                    Descargar Resumen
                </button>
            </div>
        </div>
        <div class="sp-grid" id="summaryGrid">
            {{-- Populated by JS --}}
        </div>
    </div>

</div>

<style>
@keyframes spin { from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
</style>

<script>
// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('reportDate').textContent = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
    initTable();
});
window.addEventListener('spa:contentLoaded', () => {
    if(document.getElementById('editableTable') && document.getElementById('tableBody').children.length === 0) {
        document.getElementById('reportDate').textContent = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
        initTable();
    }
});

function initTable() {
    clearTable(false);
}

function clearTable(reset = true) {
    document.getElementById('tableBody').innerHTML = '';
    for(let i=0; i<8; i++) addRow();
    if(reset) {
        document.getElementById('tot_eq').textContent = '0';
        document.getElementById('tot_fil').textContent = '0';
        document.getElementById('renderArea').innerHTML = '<div class="empty-state"><i class="material-icons">inventory_2</i>La visualización se mostrará aquí al generar.</div>';
        document.getElementById('reportPanel').style.display  = 'none';
        document.getElementById('summaryPanel').style.display = 'none';
    }
}

// ── Lógica de la Tabla ──
function createRow(cEq='', desc='', cFil='') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="number" class="eq-qty" name="eq_qty[]" placeholder="-" min="1" value="${cEq}" oninput="styleRow(this)" aria-label="Cantidad de equipos"></td>
        <td><input type="text" class="desc-col" name="desc[]" placeholder="Ej: CHUTO HOWO o Filtro XY" value="${desc}" aria-label="Descripción"></td>
        <td><input type="number" class="fil-qty" name="fil_qty[]" placeholder="-" min="1" value="${cFil}" aria-label="Cantidad de filtros"></td>
        <td style="text-align:center;"><button class="btn-del-row" onclick="this.closest('tr').remove()"><i class="material-icons">close</i></button></td>
    `;
    
    // Paste en la primera celda
    tr.querySelector('.eq-qty').addEventListener('paste', handleExcelPaste);
    // Y tmb en la de desc x si acaso pegan desde la 2da col
    tr.querySelector('.desc-col').addEventListener('paste', handleExcelPaste);
    
    setTimeout(() => styleRow(tr.querySelector('.eq-qty')), 10);
    return tr;
}

function addRow(cEq='', desc='', cFil='') {
    document.getElementById('tableBody').appendChild(createRow(cEq, desc, cFil));
}

function styleRow(inputEq) {
    const tr = inputEq.closest('tr');
    if (inputEq.value && parseInt(inputEq.value) > 0) {
        tr.classList.add('is-eq-row');
        tr.classList.remove('is-fil-row');
    } else {
        tr.classList.add('is-fil-row');
        tr.classList.remove('is-eq-row');
    }
}

// ── Mega Parser de Excel ──
function handleExcelPaste(e) {
    const pasteData = (e.clipboardData || window.clipboardData).getData('text');
    if (!pasteData) return;
    
    e.preventDefault();
    const rowsRaw = pasteData.trim().split('\n');
    if (rowsRaw.length === 0) return;

    const tbody = document.getElementById('tableBody');
    const currentRow = e.target.closest('tr');
    const allTableRows = Array.from(tbody.querySelectorAll('tr'));
    let startIdx = allTableRows.indexOf(currentRow);
    if (startIdx === -1) startIdx = allTableRows.length;

    rowsRaw.forEach((rowRaw, i) => {
        // En Excel si hay celdas combinadas, se pegan tabulaciones vacías al inicio.
        let cols = rowRaw.split('\t').map(c => c.replace(/\r/g,''));
        
        let valEq = cols[0] ? cols[0].trim() : '';
        let valDesc = cols[1] ? cols[1].trim() : '';
        let valFil = cols[2] ? cols[2].trim() : '';

        // Si el usuario copio con dos columnas porque se salto la combinada
        if(cols.length === 2 && !valFil) {
            valDesc = cols[0].trim();
            valFil = cols[1].trim();
            valEq = ''; 
        }

        // Aplicamos
        let targetRow = allTableRows[startIdx + i];
        if (!targetRow) {
            targetRow = createRow(valEq, valDesc, valFil);
            tbody.appendChild(targetRow);
        } else {
            const inputs = targetRow.querySelectorAll('input');
            inputs[0].value = valEq;
            inputs[1].value = valDesc;
            inputs[2].value = valFil;
            styleRow(inputs[0]);
        }
    });
}

// ── Lógica de Generación y Parseo Estructurado ──
function generateReport() {
    const trs = document.querySelectorAll('#tableBody tr');
    let db = [];
    let curEq = null;

    let globalEqs = 0;
    let globalFils = 0;

    trs.forEach(tr => {
        const ins = tr.querySelectorAll('input');
        const eqQ = parseInt(ins[0].value);
        const desc= ins[1].value.trim();
        const flQ = parseInt(ins[2].value);

        const hasEqQ = !isNaN(eqQ) && eqQ > 0;
        const hasFlQ = !isNaN(flQ) && flQ > 0;

        // Si tiene cantidad en T.Equipos, es la fila CABECERA del Equipo
        if (hasEqQ) {
            curEq = { 
                name: desc || 'Equipo Sin Nombre', 
                qty: eqQ, 
                filters: [] 
            };
            db.push(curEq);
            globalEqs += eqQ;

            // Si en esa misma fila también pusieron una cantidad de filtro,
            // significa que el nombre funciona tanto para el equipo como para su (único) filtro
            if (hasFlQ) {
                curEq.filters.push({ name: desc || 'Filtro General', qty: flQ });
                globalFils += flQ;
            }
        } 
        // Es un filtro dependiente
        else if (desc || hasFlQ) {
            if (!curEq) {
                // Si meten un filtro pero no hay equipo arriba, creamos uno generico
                curEq = { name: 'Varios', qty: 1, filters: [] };
                db.push(curEq);
            }
            const filName = desc || 'Filtro S/N';
            const fQ = hasFlQ ? flQ : 0;
            curEq.filters.push({ name: filName, qty: fQ });
            globalFils += fQ;
        }
    });

    // Validar vacíos
    if (db.length === 0) {
        document.getElementById('renderArea').innerHTML = '<div class="empty-state"><i class="material-icons">warning</i>No hay datos detectados o el formato es incorrecto.</div>';
        return;
    }

    // Mostrar panel y actualizar totales
    const rpanel = document.getElementById('reportPanel');
    rpanel.style.display = 'flex';
    document.getElementById('tot_eq').textContent = globalEqs;
    document.getElementById('tot_fil').textContent = globalFils;

    renderGraphic(db);

    // Scroll suave hacia el consolidado
    setTimeout(() => rpanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);

    // ── Construir Mapa de Filtros Únicos por N/P ──
    // Agrupa todos los filtros con el mismo nombre (sin importar a qué equipo pertenecen)
    const filterMap = {}; // { 'MIS0070': { totalQty: 0, equiposUsados: { 'CHUTO HOWO': 24 }, totalEqAsignados: 0 } }

    db.forEach(eq => {
        eq.filters.forEach(fil => {
            const key = fil.name.toUpperCase().trim();
            if (!filterMap[key]) {
                filterMap[key] = { name: fil.name, totalQty: 0, equipos: [] };
            }
            filterMap[key].totalQty += fil.qty;
            filterMap[key].equipos.push({ nombre: eq.name, cantEquipos: eq.qty, cantFiltros: fil.qty });
        });
    });

    renderSummary(filterMap, globalEqs, globalFils);
}

function renderGraphic(db) {
    const area = document.getElementById('renderArea');

    if (!db.length) {
        area.innerHTML = '<div class="empty-state"><i class="material-icons">inventory_2</i>Sin datos.</div>';
        return;
    }

    const maxEq  = Math.max(...db.map(e => e.qty), 1);
    const maxFil = Math.max(...db.map(e => e.filters.reduce((s,f)=>s+f.qty,0)), 1);

    area.innerHTML = db.map(eq => {
        const totalFil = eq.filters.reduce((s,f)=>s+f.qty,0);
        const pctEq    = Math.round((eq.qty   / maxEq)  * 100);
        const pctFil   = Math.round((totalFil / maxFil) * 100);

        return `
            <div class="eq-row">
                <div class="eq-row-name">
                    <span class="eq-row-name-text" title="${eq.name}">${eq.name}</span>
                </div>
                <div class="eq-bar-row">
                    <div class="eq-bar-track"><div class="eq-bar-fill-eq"  style="width:${pctEq}%;"></div></div>
                    <span class="eq-bar-val" style="width:auto; padding-left: 5px; border-right: 1.5px solid #e2e8f0; padding-right: 12px; margin-right: 6px;">${eq.qty} Equipos</span>
                    <span style="font-size:11px; font-weight:800; color:#0369a1; display:flex; align-items:center; gap:4px; flex-shrink:0;">
                        <i class="material-icons" style="font-size:16px;">filter_alt</i> ${totalFil} Filtros
                    </span>
                </div>
            </div>
        `;
    }).join('');
}

// ── Render Resumen por Tipo de Filtro ──
function renderSummary(filterMap, globalEqs, globalFils) {
    const panel = document.getElementById('summaryPanel');
    const grid = document.getElementById('summaryGrid');
    
    const keys = Object.keys(filterMap);
    if (keys.length === 0) { panel.style.display = 'none'; return; }

    panel.style.display = 'block';
    document.getElementById('sum_tot_eq').textContent = globalEqs;
    document.getElementById('sum_tot_fil').textContent = globalFils;

    // Máximo para las barras proporcionales
    const maxQty = Math.max(...keys.map(k => filterMap[k].totalQty), 1);

    grid.innerHTML = keys
        .sort((a,b) => filterMap[b].totalQty - filterMap[a].totalQty)
        .map(key => {
            const f = filterMap[key];
            const pct = (f.totalQty / maxQty * 100).toFixed(1);
            // Calcular cuántos equipos distintos usan este filtro
            const totalEqUsando = f.equipos.reduce((acc, e) => acc + e.cantEquipos, 0);
            
            // Sub-lista de equipos que lo usan
            const eqSubList = f.equipos.map(e => `
                <div style="display:flex; justify-content:space-between; font-size:10px; color:#64748b; padding: 2px 0; border-bottom: 1px dashed #f1f5f9;">
                    <span style="flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${e.nombre}">${e.nombre}</span>
                    <span style="font-weight:700; color:#0369a1; margin-left:8px;">${e.cantFiltros}</span>
                </div>
            `).join('');

            return `
                <div class="sp-fil-card">
                    <div class="sp-fil-name">${f.name}</div>
                    <div class="sp-fil-meta">
                        <div>
                            <div class="sp-fil-total">${f.totalQty}</div>
                            <div class="sp-fil-total-label">filtros requeridos</div>
                        </div>
                        <div class="sp-fil-eq-used">
                            <i class="material-icons" style="font-size:11px; vertical-align:middle;">local_shipping</i>
                            ${totalEqUsando} equipos
                        </div>
                    </div>
                    <div class="sp-fil-bar-track">
                        <div class="sp-fil-bar-fill" style="width:${pct}%;"></div>
                    </div>
                    <div style="margin-top:6px; display:flex; flex-direction:column; gap:1px;">
                        ${eqSubList}
                    </div>
                </div>
            `;
        }).join('');
}

// ── Descarga (genérica, recibe el ID del panel a capturar) ──
async function downloadImage(panelId = 'reportPanel', fileName = 'Requerimiento_Filtros') {
    const panel = document.getElementById(panelId);
    
    // Toggle de iconos según cuál botón se usó
    const iconId    = panelId === 'reportPanel' ? 'downloadIcon'  : 'downloadIcon2';
    const spinnerId = panelId === 'reportPanel' ? 'downloadSpinner' : 'downloadSpinner2';
    const btnIcon   = document.getElementById(iconId);
    const spinner   = document.getElementById(spinnerId);
    btnIcon.style.display = 'none';
    spinner.style.display = 'inline-block';
    
    // Para el panel de resumen (no tiene scroll interno), para el de equipos sí
    const scrollArea = panelId === 'reportPanel' ? document.getElementById('renderArea') : null;
    let oldScrollStyle = '';
    if (scrollArea) {
        oldScrollStyle = scrollArea.getAttribute('style') || '';
        scrollArea.style.maxHeight = 'none';
        scrollArea.style.overflow = 'visible';
    }
    
    try {
        const canvas = await html2canvas(panel, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
        const link = document.createElement('a');
        link.download = `${fileName}_${new Date().toISOString().slice(0,10)}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    } finally {
        if (scrollArea) scrollArea.setAttribute('style', oldScrollStyle);
        btnIcon.style.display = '';
        spinner.style.display = 'none';
    }
}
</script>
@endsection
