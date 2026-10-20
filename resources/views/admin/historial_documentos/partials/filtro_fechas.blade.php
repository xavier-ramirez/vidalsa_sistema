{{-- Filtros Avanzados: el rango de fechas, en un popover. Lo usan las TRES pestañas de Control de
     Auditoría (solo se pinta una a la vez, por eso los id son los mismos). $alCambiar es la función
     que recarga la pestaña al cambiar una fecha: window.loadHistorialDocumentos en el Historial
     (por AJAX) y window.cpdfFiltrar en Revisión y Compresión. El botón se pinta en rojo si hay
     alguna fecha puesta — aquí al cargar y, en el Historial tras cada filtrado por AJAX, en
     hdMarcarFiltrosAvanzados (historial_documentos_index.js). --}}
@php $hasAdvHd = request()->filled('fecha_desde') || request()->filled('fecha_hasta'); @endphp
<div class="hd-adv-filter-wrap">
    <button type="button" id="btnHdAdvancedFilter"
        onclick="window.hdToggleFiltrosAvanzados()"
        title="Filtros Avanzados (fechas)"
        style="height: 45px; width: 45px; padding: 0; border-radius: 12px; background: {{ $hasAdvHd ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAdvHd ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAdvHd ? '#ef4444' : '#64748b' }}; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
        <i class="material-icons">filter_list</i>
    </button>
    <div id="hdAdvancedFilterPanel" style="display:none; position:absolute; top:100%; right:0; width:340px; max-width:calc(100vw - 24px); background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); margin-top:8px; padding:14px; z-index:100;">
        <h4 style="margin:0 0 12px 0; font-size:13px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
            Filtros Avanzados
            <span style="font-size:11px; color:#64748b; font-weight:400; text-decoration:underline; cursor:pointer;"
                  onclick="window.hdLimpiarFiltrosAvanzados({{ $alCambiar }})">Limpiar</span>
        </h4>

        {{-- Fecha desde / hasta EN FILA (uno al lado del otro). Cada columna
             flex:1 + min-width:0 para repartir el ancho sin desbordar el panel;
             los iconos de limpiar van compactos para que quepan los dos inputs. --}}
        <div style="display:flex; gap:10px;">
            <div style="flex:1; min-width:0;">
                <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha desde</span>
                <div style="display:flex; align-items:center; gap:2px;">
                    <input type="date" id="hdFechaDesde" name="fecha_desde" value="{{ request('fecha_desde') }}"
                        onchange="window.hdToggleDateClear('hdFechaDesde','hdClrFechaDesde'); {{ $alCambiar }}()"
                        onclick="try { this.showPicker(); } catch(e) {}"
                        style="flex:1; min-width:0; box-sizing:border-box; padding:7px 8px; border:1px solid {{ request('fecha_desde') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_desde') ? '#e1effa' : 'white' }}; cursor:pointer;">
                    <i id="hdClrFechaDesde" class="material-icons"
                       style="display:{{ request('fecha_desde') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:16px; padding:2px;"
                       onclick="event.stopPropagation(); document.getElementById('hdFechaDesde').value=''; this.style.display='none'; {{ $alCambiar }}();"
                       title="Limpiar fecha desde">close</i>
                </div>
            </div>
            <div style="flex:1; min-width:0;">
                <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha hasta</span>
                <div style="display:flex; align-items:center; gap:2px;">
                    <input type="date" id="hdFechaHasta" name="fecha_hasta" value="{{ request('fecha_hasta') }}"
                        onchange="window.hdToggleDateClear('hdFechaHasta','hdClrFechaHasta'); {{ $alCambiar }}()"
                        onclick="try { this.showPicker(); } catch(e) {}"
                        style="flex:1; min-width:0; box-sizing:border-box; padding:7px 8px; border:1px solid {{ request('fecha_hasta') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_hasta') ? '#e1effa' : 'white' }}; cursor:pointer;">
                    <i id="hdClrFechaHasta" class="material-icons"
                       style="display:{{ request('fecha_hasta') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:16px; padding:2px;"
                       onclick="event.stopPropagation(); document.getElementById('hdFechaHasta').value=''; this.style.display='none'; {{ $alCambiar }}();"
                       title="Limpiar fecha hasta">close</i>
                </div>
            </div>
        </div>
    </div>
</div>
