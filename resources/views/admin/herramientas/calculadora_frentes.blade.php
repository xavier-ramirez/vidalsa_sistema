@extends('layouts.estructura_base')
@section('title', 'Calculadora de Equipos por Frente')

@section('content')
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<style>
    * { box-sizing: border-box; }
    body, .main-viewport { background: #f1f5f9 !important; }
    .cm-page { max-width: 900px; margin: 0 auto; padding: 10px 0 40px; }
    .material-icons { font-family: 'Material Icons' !important; }
    
    .cm-title { font-size: 24px; font-weight: 800; color: #1e293b; display:flex; align-items:center; gap:10px; margin-bottom: 25px; letter-spacing: -0.5px; }
    
    .cm-grid { display: flex; flex-direction: column; gap: 20px; }

    /* ── Tarjeta Izquierda (Tabla de Entradas) ── */
    .cm-card { background: #fff; border-radius: 16px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,.04); border: 1px solid #e2e8f0; }
    .cm-card-title { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing:1.2px; color:#475569; margin-bottom: 16px; display:flex; align-items:center; gap:8px; }
    .cm-hints { font-size: 13px; color: #0f766e; margin-bottom: 20px; padding: 12px 16px; background: #f0fdfa; border-radius: 8px; border: 1px dashed #5eead4; line-height: 1.5; font-weight: 500; }
    
    /* ── Tabla Data ── */
    #editableTable { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
    #editableTable th { background: #0f172a; color: #f8fafc; padding: 14px 16px; text-align: left; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #000; }
    #editableTable th:first-child { text-align: left; }
    #editableTable th:last-child { text-align: center; width: 60px; }
    
    #editableTable tbody tr { transition: background 0.15s; border-bottom: 1px solid #f1f5f9; background: #fff; }
    #editableTable tbody tr:hover { background: #f8fafc; }
    #editableTable td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    
    .frente-select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 14px; background: #f8fafc; outline: none; transition: all .2s; color: #1e293b; cursor: pointer; font-weight: 600; appearance: none; }
    .frente-select:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    
    .qty-input { width: 100px; text-align: center; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 16px; background: #f8fafc; outline: none; transition: all .2s; color: #0ea5e9; font-weight: 800; margin: 0 auto; display: block; }
    .qty-input:focus { border-color: #0ea5e9; background: #f0f9ff; box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }

    .btn-add-row { margin-top: 15px; background: #f1f5f9; border: 1.5px dashed #94a3b8; color: #475569; border-radius: 10px; padding: 12px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .2s; display: flex; align-items:center; gap:6px; width: 100%; justify-content: center; }
    .btn-add-row:hover { background: #e0f2fe; border-color: #0ea5e9; color: #0ea5e9; }
    
    .btn-del-row { background: #fee2e2; border: none; border-radius: 6px; cursor: pointer; color: #ef4444; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; transition: all .2s; margin: 0 auto; }
    .btn-del-row:hover { background: #fecaca; color: #b91c1c; transform: scale(1.05); }

    .cm-actions { display: flex; gap: 12px; margin-top: 25px; flex-wrap: wrap; }
    .btn-generate { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; border: none; border-radius: 10px; padding: 14px 28px; font-size: 15px; font-weight: 800; cursor: pointer; display:flex; align-items:center; justify-content: center; flex: 1; gap:8px; transition: all .2s; box-shadow: 0 4px 15px rgba(37,99,235,0.3); }
    .btn-generate:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37,99,235,0.4); }
    .btn-clear { background: #fff; color: #64748b; border: 1px solid #cbd5e1; border-radius: 10px; padding: 14px 24px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .2s; }
    .btn-clear:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }

    /* ── Panel Visual Resumen ── */
    #reportPanel { margin-top: 30px; animation: fadeIn 0.4s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* Ranking Frow Styles */
    .frow { display: flex; align-items: center; justify-content: space-between; padding: 7px 16px; border-bottom: 1px dashed #e2e8f0; gap: 12px; }
    .frow:last-child { border-bottom: none; }
    .frow-num { font-size: 14px; font-weight: 900; color: #94a3b8; min-width: 28px; }
    .frow-name { font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; white-space: normal; overflow: visible; text-overflow: clip; flex: 1; min-width: 0; }
    .frow-bar-wrap { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; flex: 1; margin: 0 10px; }
    .frow-bar { height: 100%; border-radius: 4px; transition: width 0.8s ease-out; }
    .frow-val { font-size: 16px; font-weight: 900; color: #0f172a; text-align: right; min-width: 100px; display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
    .frow-dep { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; padding: 0; border: none; background: none; }

    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes spin { from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }

    /* Custom select wrapper */
    .select-wrapper { position: relative; display: block; }
</style>

<div class="cm-page">
    <div class="cm-title">
        <div style="background: linear-gradient(135deg, #0ea5e9, #2563eb); width: 48px; height: 48px; border-radius: 12px; display: flex; justify-content: center; align-items: center; box-shadow: 0 4px 10px rgba(14,165,233,0.3);">
            <i class="material-icons" style="color:white; font-size:26px;">calculate</i>
        </div>
        Calculadora Rápida de Equipos por Frente
    </div>

    <div class="cm-grid">

        {{-- Tarjeta de Entradas --}}
        <div class="cm-card">
            <div class="cm-card-title">
                <i class="material-icons" style="font-size:18px; color:#3b82f6;">playlist_add_circle</i>
                Asignación de Cantidades
            </div>

            <div class="cm-hints">
                <i class="material-icons" style="font-size:16px; vertical-align:middle; margin-right:6px;">lightbulb</i>
                Selecciona uno o más frentes de trabajo de tu base de datos y escribe cuántos equipos físicos operan en él. El sistema calculará la flota consolidada en tiempo real.
            </div>

            <table id="editableTable">
                <thead>
                    <tr>
                        <th style="width: 70%">FRENTE DE TRABAJO</th>
                        <th style="width: 20%; text-align: center;">CANT. EQUIPOS</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    {{-- Filas JS --}}
                </tbody>
            </table>

            <button class="btn-add-row" onclick="addRow()">
                <i class="material-icons" style="font-size:18px;">add_circle_outline</i> Agregar otro Frente a la lista
            </button>

            <div class="cm-actions">
                <button class="btn-clear" onclick="initTable()">
                    <i class="material-icons" style="font-size:18px;">refresh</i>
                    Limpiar
                </button>
                <button class="btn-generate" onclick="generateReport()">
                    <i class="material-icons" style="font-size:20px;">task_alt</i>
                    Consolidar Total
                </button>
            </div>
        </div>

        {{-- Panel de Resultados --}}
        <datalist id="listaFrentes"></datalist>
        <div id="reportPanel" style="display:none; background: transparent; border: none; box-shadow: none; padding: 0;">
            <div id="renderArea">
                {{-- Contenedor Resumen General idéntico a Consumibles --}}
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:12px;">
                    <span style="font-size:14px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                        <i class="material-icons" style="color:#0067b1; font-size:18px;">analytics</i>
                        Resumen General
                    </span>
                    <button id="btnDownloadFixed" onclick="downloadReport()" title="Descargar imagen completa" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                        <i id="downloadIcon" class="material-icons" style="font-size:17px;">photo_camera</i>
                        <span id="downloadSpinner" style="display:none; animation:spin 1s linear infinite; font-size:17px; margin-left:5px;"><i class="material-icons">refresh</i></span>
                    </button>
                </div>
                
                <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="flex:1; min-width:150px; background:linear-gradient(135deg,#1e293b,#0f172a); border-radius:14px; padding:12px 16px; color:#fff;">
                        <div style="font-size:11px; font-weight:700; letter-spacing:1px; color:#cbd5e1; text-transform:uppercase; margin-bottom:4px; display:flex; justify-content:space-between;">
                            <span>FLOTA TOTAL ASIGNADA</span>
                            <i class="material-icons" style="font-size:14px; opacity:0.8;">local_shipping</i>
                        </div>
                        <div style="font-size:26px; font-weight:800; line-height:1; display:flex; align-items:baseline; gap:6px;">
                            <span id="grandTotal">0</span>
                            <span style="font-size:12px; font-weight:700; color:#94a3b8; text-transform:uppercase;">EQUIPOS</span>
                        </div>
                    </div>
                </div>

                {{-- Contenedor Gráfico Consumo Total por Frente Idéntico a consumibles --}}
                <div style="background:#fff; border-radius:16px; padding:16px 20px; box-shadow:0 2px 8px rgba(0,0,0,.06), 0 8px 24px rgba(0,0,0,.06);">
                    <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0 0 10px 0; display:flex; align-items:center; justify-content:space-between;">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="material-icons" style="font-size:18px; color:#0067b1;">bar_chart</i>
                            <span>CONSOLIDADO POR FRENTE DE TRABAJO</span>
                        </span>
                    </p>
                    <div id="resultsList" style="padding:0;">
                        {{-- Elementos renderizados --}}
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    // Variables enviadas desde Blade.
    // Asignamos a window (idempotente) en vez de `const` top-level: el router SPA
    // re-ejecuta los <script> al navegar y un `const` redeclarado revienta.
    window.dbFrentes = @json($frentes ?? []);
    
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('reportDate').textContent = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });
        initTable();
    });

    window.addEventListener('spa:contentLoaded', () => {
        if(document.getElementById('editableTable') && document.getElementById('tableBody').children.length === 0) {
            document.getElementById('reportDate').textContent = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });
            initTable();
        }
    });

    function initTable() {
        document.getElementById('tableBody').innerHTML = '';
        document.getElementById('reportPanel').style.display = 'none';
        
        // Agregar 3 filas por defecto
        for(let i = 0; i < 3; i++) {
            addRow();
        }
    }

    function addRow() {
        const tr = document.createElement('tr');
        
        // Generar datalist si está vacío (sólo una vez)
        const datalist = document.getElementById('listaFrentes');
        if (datalist && datalist.options.length === 0) {
            let optionsHtml = '';
            if(dbFrentes && dbFrentes.length > 0) {
                dbFrentes.forEach(f => {
                    optionsHtml += `<option value="${f}">`;
                });
            } else {
                optionsHtml += '<option value="FRENTE EJEMPLO 1">';
                optionsHtml += '<option value="FRENTE EJEMPLO 2">';
            }
            datalist.innerHTML = optionsHtml;
        }

        tr.innerHTML = `
            <td>
                <div class="select-wrapper">
                    <input type="text" list="listaFrentes" class="frente-select" placeholder="-- Buscar o Escribir Frente --" autocomplete="off" onfocus="this.select()">
                </div>
            </td>
            <td>
                <input type="number" class="qty-input" placeholder="0" min="1" oninput="validarNumero(this)">
            </td>
            <td>
                <button class="btn-del-row" onclick="removeRow(this)" title="Quitar frente">
                    <i class="material-icons" style="font-size: 18px;">delete_outline</i>
                </button>
            </td>
        `;
        document.getElementById('tableBody').appendChild(tr);
    }

    function removeRow(btn) {
        const tr = btn.closest('tr');
        // Efecto antes de borrar
        tr.style.opacity = '0';
        setTimeout(() => tr.remove(), 200);
    }

    function validarNumero(input) {
        if(input.value < 0) input.value = 0;
    }

    function generateReport() {
        const rows = document.querySelectorAll('#tableBody tr');
        let totalEquipos = 0;
        let dataMap = {};
        
        // Extraer y agrupar datos válidos
        rows.forEach(row => {
            const select = row.querySelector('.frente-select');
            const qtyInput = row.querySelector('.qty-input');
            
            const frenteName = select.value.trim();
            const qty = parseInt(qtyInput.value) || 0;
            
            if(frenteName && qty > 0) {
                if(!dataMap[frenteName]) dataMap[frenteName] = 0;
                dataMap[frenteName] += qty;
                totalEquipos += qty;
            }
        });

        // Validar que haya datos
        if(totalEquipos === 0) {
            if(window.showToast) window.showToast('Debes seleccionar al menos un frente y asignarle 1 equipo.', 'warning');
            else alert('Debe rellenar al menos una fila correctamente.');
            return;
        }

        // Renderizar lista en el panel rojo
        const resultsList = document.getElementById('resultsList');
        resultsList.innerHTML = '';
        
        // Ordenar frentes por cantidad descendente
        const sortedFrentes = Object.keys(dataMap).sort((a,b) => dataMap[b] - dataMap[a]);
        const maxVal = dataMap[sortedFrentes[0]] || 1;
        const n = sortedFrentes.length;
        
        sortedFrentes.forEach((frente, i) => {
            const qty = dataMap[frente];
            const pct = (qty / maxVal * 100).toFixed(1);
            
            let color;
            if (i === 0) {
                color = 'linear-gradient(90deg,#7f1d1d,#b91c1c)';
            } else if (i === 1) {
                color = 'linear-gradient(90deg,#b91c1c,#ef4444)';
            } else {
                const blueIdx = i - 2;
                const blueN   = Math.max(n - 2, 1);
                const lightA  = Math.round(22 + (28 * blueIdx / blueN));
                const lightB  = Math.round(32 + (22 * blueIdx / blueN));
                const satA    = Math.round(82 - (18 * blueIdx / blueN));
                color = `linear-gradient(90deg,hsl(213,${satA}%,${lightA}%),hsl(213,${satA-5}%,${lightB}%))`;
            }
            
            const rowHtml = `
                <div class="frow">
                    <span class="frow-num">#${i+1}</span>
                    <span class="frow-name" title="${frente}">${frente}</span>
                    <div class="frow-bar-wrap">
                        <div class="frow-bar" style="width:0%; background:${color};" data-target-width="${pct}%"></div>
                    </div>
                    <span class="frow-val">
                        ${qty}
                        <span class="frow-dep">Eq. Asignados</span>
                    </span>
                </div>
            `;
            resultsList.insertAdjacentHTML('beforeend', rowHtml);
        });

        // Add smooth animation delay for bars
        setTimeout(() => {
            document.querySelectorAll('.frow-bar').forEach(bar => {
                bar.style.width = bar.getAttribute('data-target-width');
            });
        }, 50);

        // Actualizar total general
        document.getElementById('grandTotal').textContent = totalEquipos;
        
        // Mostrar panel
        const rpanel = document.getElementById('reportPanel');
        rpanel.style.display = 'block';
        
        if(window.showToast) window.showToast('Reporte generado exitosamente.', 'success');

        // Scroll suave hacia abajo
        setTimeout(() => rpanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    }

    async function downloadReport() {
        const panel = document.getElementById('renderArea');
        const btnIcon = document.getElementById('downloadIcon');
        const spinner = document.getElementById('downloadSpinner');
        
        btnIcon.style.display = 'none';
        spinner.style.display = 'inline-block';
        
        try {
            const canvas = await html2canvas(panel, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
            const link = document.createElement('a');
            link.download = `Total_Equipos_Frente_${new Date().toISOString().slice(0,10)}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            if(window.showToast) window.showToast('Imagen descargada correctamente.', 'success');
        } catch(e) {
            console.error('Error html2canvas', e);
            if(window.showToast) window.showToast('Error al generar la imagen.', 'error');
        } finally {
            btnIcon.style.display = '';
            spinner.style.display = 'none';
        }
    }
</script>
@endsection
