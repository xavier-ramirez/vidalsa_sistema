@extends('layouts.estructura_base')
@section('title', 'Consolidado Manual de Equipos')

@section('content')
{{-- Inter desde el SERVIDOR, no desde Google. Los cuatro pesos que se usan aquí
     (400/600/700/800) ya están en public/fonts, así que se ve exactamente igual pero
     sin una petición a un dominio externo que bloquea el pintado — y funciona sin
     internet, que es lo que se espera de esta app. --}}
<link href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}" rel="stylesheet">
<script src="{{ asset('js/html2canvas.min.js') }}?v={{ @filemtime(public_path('js/html2canvas.min.js')) }}"></script>

<style>
    body, .main-viewport { background: #f1f5f9 !important; }
    .cm-page { font-family: 'Inter', sans-serif; max-width: 1300px; margin: 0 auto; padding: 10px 0 40px; }
    .cm-title { font-size: 22px; font-weight: 800; color: #1e293b; display:flex; align-items:center; gap:8px; margin-bottom: 18px; }
    .cm-grid { display: grid; grid-template-columns: 1fr 360px; gap: 20px; align-items: start; }
    @media(max-width:900px){ .cm-grid { grid-template-columns: 1fr; } }

    /* ── Tabla Editable ───────────────────────────────────────── */
    .cm-card { background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(0,0,0,.06); border: 1px solid #e2e8f0; }
    .cm-card-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing:1.2px; color:#64748b; margin-bottom: 14px; display:flex; align-items:center; gap:6px; }
    .cm-hints { font-size: 12px; color: #94a3b8; margin-bottom: 12px; padding: 8px 12px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e0; }
    .cm-hints b { color: #475569; }

    #editableTable { width: 100%; border-collapse: collapse; font-size: 14px; }
    #editableTable thead th { background: #1e3a5f; color: #fff; padding: 10px 12px; text-align: left; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .8px; }
    #editableTable thead th:first-child { border-radius: 8px 0 0 8px; }
    #editableTable thead th:last-child  { border-radius: 0 8px 8px 0; text-align:center; }
    #editableTable tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    #editableTable tbody tr:hover { background: #f8fafc; }
    #editableTable td { padding: 6px 4px; vertical-align: middle; }
    #editableTable td input {
        width: 100%; border: 1px solid transparent; border-radius: 6px;
        padding: 6px 10px; font-size: 14px; font-family: 'Inter', sans-serif;
        background: transparent; outline: none; transition: all .2s; color: #1e293b;
    }
    #editableTable td input:focus { border-color: #0067b1; background: #eff8ff; }
    #editableTable td input.num-col { text-align: center; font-weight: 700; color: #0067b1; }
    #editableTable td.td-del { text-align: center; }

    .btn-add-row { margin-top: 12px; background: #f1f5f9; border: 1.5px dashed #94a3b8; color: #475569; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s; display: flex; align-items:center; gap:5px; }
    .btn-add-row:hover { background: #e0f2fe; border-color: #0067b1; color: #0067b1; }
    .btn-del-row { background: none; border: none; cursor: pointer; color: #cbd5e0; font-size: 18px; line-height:1; transition: color .2s; }
    .btn-del-row:hover { color: #ef4444; }

    .cm-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    .btn-generate { background: linear-gradient(135deg, #1a365d, #2c5282); color: #fff; border: none; border-radius: 10px; padding: 10px 22px; font-size: 14px; font-weight: 700; cursor: pointer; display:flex; align-items:center; gap:6px; transition: opacity .2s; }
    .btn-generate:hover { opacity: .9; }
    .btn-clear { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .2s; }
    .btn-clear:hover { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }

    /* Barras tipo */
    #tiposListWrap::-webkit-scrollbar { width: 4px; }
    #tiposListWrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius:4px; }
    .tipo-bar-item { margin-bottom: 12px; }
    .tipo-bar-header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px; }
    .tipo-bar-name { font-size: 12px; font-weight: 700; color: #334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 210px; }
    .tipo-bar-count { font-size: 13px; font-weight: 800; color: #0f172a; }
    .tipo-bar-track { background: #f1f5f9; border-radius:4px; height:8px; }
    .tipo-bar-fill { height:8px; border-radius:4px; background: linear-gradient(90deg, #60a5fa, #93c5fd); transition: width .6s ease; }

    /* Botón descarga */
    .btn-download { background: #f8fafc; border: 1px solid #cbd5e1; color:#0f172a; border-radius: 10px; padding: 10px 16px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; width:100%; justify-content:center; margin-top:20px; transition: all .2s; }
    .btn-download:hover { background: #e1effa; border-color: #0067b1; color: #0067b1; }
    #downloadSpinner { display:none; }

    /* Custom Scrollbar */
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>

<div class="cm-page">
    <div class="cm-title">
        <i class="material-icons" style="color:#0067b1; font-size:28px;">pie_chart</i>
        Consolidado Manual de Distribución
    </div>

    <div class="cm-grid">

        {{-- ════ TABLA EDITABLE ════ --}}
        <div class="cm-card">
            <div class="cm-card-title">
                <i class="material-icons" style="font-size:16px; color:#0067b1;">table_chart</i>
                Ingresa tus datos
            </div>

            <div class="cm-hints">
                💡 <b>Pega desde Excel:</b> Selecciona tus celdas en Excel → <kbd>Ctrl+C</kbd> → haz clic en la celda inicial de <b>"Tipo de Equipo"</b> → <kbd>Ctrl+V</kbd>. ¡Tu información se organizará automáticamente!
            </div>

            <table id="editableTable">
                <thead>
                    <tr>
                        <th style="width:75%">Tipo de Equipo</th>
                        <th style="width:20%; text-align:center">Cantidad</th>
                        <th style="width:5%"></th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    {{-- Rows inserted by JS --}}
                </tbody>
            </table>

            <button class="btn-add-row" onclick="addRow()">
                <i class="material-icons" style="font-size:16px;">add</i> Agregar fila
            </button>

            <div class="cm-actions">
                <button class="btn-generate" onclick="generateChart()">
                    <i class="material-icons" style="font-size:18px;">auto_graph</i>
                    Generar Gráfico
                </button>
                <button class="btn-clear" onclick="clearTable()">
                    <i class="material-icons" style="font-size:16px;">clear_all</i>
                    Limpiar
                </button>
            </div>
        </div>

        {{-- ════ PANEL DE DISTRIBUCIÓN EXACTO ════ --}}
        <div style="display: flex; flex-direction: column; gap: 15px;">
            
            <!-- Main Total Card (Idéntico a Equipos) -->
            <div style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
                <!-- Decorative Icon -->
                <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">agriculture</i>
                
                <div style="position: relative; z-index: 2;">
                    <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                        <i class="material-icons" style="font-size: 14px;">pie_chart</i>
                        Consolidado de Equipos
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <!-- Main Total -->
                        <div style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px; flex: 1;">
                            <span id="c_total" style="font-size: 36px; font-weight: 800; line-height: 1;">0</span>
                            <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Breakdown by Type (Contenedor Blanco) -->
            <div id="consolidadoPanel" style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
                
                <h4 style="margin: 0 0 12px 0; font-size: 12px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="font-size: 18px; color: #3b82f6;">pie_chart</i>
                    Distribución
                </h4>
                
                <div id="tiposListWrap">
                    <ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">
                        <p id="tiposEmpty" style="color:#94a3b8; font-size:13px; text-align:center; padding:20px 0; font-weight:600; margin:0;">
                            Sin datos válidos para mostrar.
                        </p>
                    </ul>
                </div>

                <div style="margin-top: 15px; border-top: 1px dashed #e2e8f0; padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total General</span>
                    <span id="c_total_2" style="font-size: 16px; font-weight: 800; color: #1e293b; background: #f1f5f9; padding: 2px 8px; border-radius: 6px;">0</span>
                </div>

                <button class="btn-download" onclick="downloadImage()">
                    <span id="downloadSpinner"><i class="material-icons" style="font-size:16px; animation:spin 1s linear infinite;">refresh</i></span>
                    <i class="material-icons" style="font-size:18px;" id="downloadIcon">download_for_offline</i>
                    Descargar Gráfico
                </button>
            </div>
        </div>

    </div>
</div>

<style>
@keyframes spin { from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
</style>

<script>
// ─── Estado ───────────────────────────────────────────────────

// ─── Tabla Editable ───────────────────────────────────────────
function createRow(tipo='', cant='') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text"   class="tipo-col"  placeholder="Ej: Tractor" value="${tipo}"></td>
        <td><input type="number" class="num-col"   placeholder="0" min="0" value="${cant}"></td>
        <td class="td-del"><button class="btn-del-row" title="Eliminar fila" onclick="this.closest('tr').remove()"><i class="material-icons">close</i></button></td>
    `;
    // Paste handler dentro de la celda Tipo
    tr.querySelector('.tipo-col').addEventListener('paste', handleExcelPaste);
    return tr;
}

function addRow(tipo='', cant='') {
    document.getElementById('tableBody').appendChild(createRow(tipo, cant));
}

function clearTable() {
    document.getElementById('tableBody').innerHTML = '';
    for(let i=0;i<5;i++) addRow();
    resetConsolidado();
}

// ─── PEGAR DESDE EXCEL ────────────────────────────────────────
function handleExcelPaste(e) {
    const pasteData = (e.clipboardData || window.clipboardData).getData('text');
    if (!pasteData) return;

    e.preventDefault();
    const lines = pasteData.trim().split('\n');
    const tbody = document.getElementById('tableBody');

    const currentRow = e.target.closest('tr');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let startIdx = rows.indexOf(currentRow);
    if (startIdx === -1) startIdx = rows.length;

    lines.forEach((line, i) => {
        let cols = line.split('\t').map(c => c.trim().replace(/\r/g,''));
        
        // Fallback: Si el usuario pegó de un chat y todo vino con varios espacios en vez de Tabs reales
        if(cols.length === 1 && line.includes('  ')) {
            cols = line.split(/\s{2,}/).map(c => c.trim().replace(/\r/g,''));
        }

        let tipo = cols[0] || '';
        let cant = parseInt(cols[1]);

        // Último recurso: si pegaron una fila de un chat que no se separó bien "RETROEXCAVADOR 1"
        if(isNaN(cant)) {
            const m = (tipo||'').match(/(.+)\s+(\d+)$/);
            if(m) {
                tipo = m[1].trim();
                cant = parseInt(m[2]);
            } else {
                cant = '';
            }
        }

        let targetRow = rows[startIdx + i];
        if (!targetRow) {
            targetRow = createRow(tipo, cant);
            tbody.appendChild(targetRow);
        } else {
            const inputs = targetRow.querySelectorAll('input');
            inputs[0].value = tipo;
            inputs[1].value = cant;
        }
    });
}

// ─── Generar Gráfico ──────────────────────────────────────────
function generateChart() {
    const rows = document.querySelectorAll('#tableBody tr');
    const tipos = [];
    let grand=0;

    rows.forEach(tr => {
        const inputs = tr.querySelectorAll('input');
        const tipo = inputs[0].value.trim();
        const total   = parseInt(inputs[1].value)||0;
        
        if (tipo && total > 0) {
            // Buscamos si ya existe ese tipo para sumarlo y evitar duplicados graficos
            let obj = tipos.find(x => x.nombre.toUpperCase() === tipo.toUpperCase());
            if(obj) obj.total += total;
            else tipos.push({ nombre: tipo, total });
            grand += total;
        }
    });

    // Validar vacíos
    if(grand === 0 || tipos.length === 0) {
        resetConsolidado();
        return;
    }

    // Actualizar números
    document.getElementById('c_total').textContent = grand;
    document.getElementById('c_total_2').textContent = grand;

    // Barras de distribución
    renderBars(tipos);
}

function renderBars(tipos) {
    const wrap = document.getElementById('tiposListWrap');
    if (!tipos.length) {
        wrap.innerHTML = '<ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar"><p id="tiposEmpty" style="color:#94a3b8; font-size:13px; text-align:center; padding:20px 0;">Sin datos válidos para mostrar.</p></ul>';
        return;
    }
    const totalStats = tipos.reduce((acc, t) => acc + t.total, 0);
    
    let html = '<ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">';
    
    tipos.sort((a,b)=>b.total-a.total).forEach(stat => {
        const percentage = totalStats > 0 ? (stat.total / totalStats) * 100 : 0;
        
        html += `
            <li style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='1'">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">
                    <span style="color: #334155; font-size: 11px; font-weight: 600; word-break: break-word; line-height: 1.2; flex: 1;">
                        ${(stat.nombre || 'Desconocido').toUpperCase()}
                    </span>
                    <span style="font-weight: 700; color: #1e293b; font-size: 11px; background: #f1f5f9; padding: 1px 6px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">
                        ${stat.total}
                    </span>
                </div>
                <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                    <div style="width: ${percentage}%; height: 100%; background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%); border-radius: 2px;"></div>
                </div>
            </li>
        `;
    });
    html += '</ul>';
    wrap.innerHTML = html;
}

function resetConsolidado() {
    document.getElementById('c_total').textContent = '0';
    document.getElementById('c_total_2').textContent = '0';
    document.getElementById('tiposListWrap').innerHTML = '<ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar"><p id="tiposEmpty" style="color:#94a3b8; font-size:13px; text-align:center; padding:20px 0;">Sin datos válidos para mostrar.</p></ul>';
}

// ─── Descarga ─────────────────────────────────────────────────
async function downloadImage() {
    const panel = document.getElementById('consolidadoPanel');
    const btnIcon = document.getElementById('downloadIcon');
    const spinner = document.getElementById('downloadSpinner');
    btnIcon.style.display = 'none';
    spinner.style.display = 'inline-block';
    try {
        const canvas = await html2canvas(panel, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
        const link = document.createElement('a');
        link.download = `Grafico_Equipos_${new Date().toISOString().slice(0,10)}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    } finally {
        btnIcon.style.display = '';
        spinner.style.display = 'none';
    }
}

// ─── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    for(let i=0;i<6;i++) addRow();
});
window.addEventListener('spa:contentLoaded', function() {
    if (!document.getElementById('editableTable')) return;
    if (!document.getElementById('tableBody').children.length) {
        for(let i=0;i<6;i++) addRow();
    }
});
</script>
@endsection
