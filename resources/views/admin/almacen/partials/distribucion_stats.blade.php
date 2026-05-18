{{-- Sidebar "Distribución de Inventario" — DOS MODOS:
      A) Cruzado por almacen: cuando $productoOtros está seteado (el usuario clickeó
         una sugerencia del filtro Buscar → la URL trae id_producto). Muestra el
         producto enfocado en TODOS los demas almacenes visibles para el usuario.
      B) Por categoria: comportamiento default — agrupa los productos del almacen
         actual segun su categoria.

   El controlador decide qué pasar al partial (productoOtros queda null en modo B). --}}
@php
    $modoCruzado = isset($productoOtros) && $productoOtros !== null;
    $dist        = $distribucion ?? collect();
    $totalDist   = $dist->sum('total');
    $nombreProd  = $modoCruzado && isset($productoSel) && $productoSel ? trim($productoSel->NOMBRE) : '';
@endphp

@if($modoCruzado)
    {{-- Modo cruzado: encabezado verde con el nombre del producto + lista minimal
         "almacen — cantidad". Pensado para responder rapido "¿donde mas hay de
         esto?". El wrapper `.alm-otros-almacenes` permite ocultar todo el bloque
         en mobile (regla en index.blade.php @media ≤768px) — el cliente lo pidio
         porque ocupa demasiado espacio vertical en telefono. --}}
    <div class="alm-otros-almacenes">
    <h4 style="margin:0 0 10px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">
        <i class="material-icons" style="font-size:18px;color:#10b981;">place</i>
        En otros almacenes
    </h4>
    @if($nombreProd !== '')
        <div style="margin:0 0 8px 0;padding:7px 9px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:7px;font-size:12px;font-weight:700;color:#065f46;line-height:1.3;text-transform:uppercase;">
            {{ $nombreProd }}
        </div>
    @endif

    @if($productoOtros->count() > 0)
        <ul style="list-style:none;padding:0;margin:0;max-height:64vh;overflow-y:auto;display:flex;flex-direction:column;gap:3px;" class="custom-scrollbar">
            @foreach($productoOtros as $row)
                @php
                    // Saldo limpio: "12" o "12.5" (sin ceros sobrantes ni separador roto).
                    $qty = rtrim(rtrim(number_format((float) $row->CANTIDAD, 3, '.', ','), '0'), '.');
                    if ($qty === '' || $qty === '-') { $qty = '0'; }
                    $bajo = $row->CANTIDAD_MINIMA !== null && (float) $row->CANTIDAD <= (float) $row->CANTIDAD_MINIMA;
                @endphp
                <li onclick="window.location.href='{{ route('almacen.index', ['id_almacen' => $row->ID_ALMACEN, 'id_producto' => request('id_producto')]) }}'"
                    title="Ver este producto en {{ $row->NOMBRE }}"
                    style="padding:7px 9px;border-radius:6px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px;transition:background 0.15s;border:1px solid transparent;"
                    onmouseover="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';"
                    onmouseout="this.style.background='transparent';this.style.borderColor='transparent';">
                    <span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $row->NOMBRE }}
                    </span>
                    <span style="font-weight:700;font-size:12.5px;padding:2px 9px;border-radius:4px;white-space:nowrap;{{ $bajo ? 'background:#fee2e2;color:#b91c1c;' : 'background:#f1f5f9;color:#1e293b;' }}">
                        {{ $qty }}
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;font-style:italic;line-height:1.4;">
            Este producto solo existe en el almacén actual.
        </p>
    @endif
    </div>
@else
    {{-- Modo default: distribucion por categoria. --}}
    <h4 style="margin:0 0 12px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">
        <i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>
        Distribución de Inventario
    </h4>

    @if($dist->count() > 0)
        <ul style="list-style:none;padding:0;margin:0;max-height:64vh;overflow-y:auto;overflow-x:visible;display:flex;flex-direction:column;gap:4px;" class="custom-scrollbar">
            @foreach($dist as $row)
                @php $pct = $totalDist > 0 ? ($row->total / $totalDist) * 100 : 0; @endphp
                <li onclick="window.almFilterByCategoria('{{ $row->categoria === 'SIN CATEGORÍA' ? '' : addslashes($row->categoria) }}')"
                    style="padding:5px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'"
                    title="{{ rtrim(rtrim(number_format((float)$row->unidades,3,'.',','),'0'),'.') }} unidades en total">
                    <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;">
                        <span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;">
                            {{ $row->categoria }}
                        </span>
                        <span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;white-space:nowrap;">
                            {{ $row->total }}
                        </span>
                    </div>
                    <div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                        <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Sin datos para mostrar.</p>
    @endif
@endif
