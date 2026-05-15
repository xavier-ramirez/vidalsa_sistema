{{-- Filas de la tabla de inventario. $productos = paginator|null ; $almacen = Almacen|null ; $inicial = bool (la tabla abre vacía hasta que se filtre) --}}
@php
    $puedeMover  = auth()->user()?->can('almacen.movimiento') ?? false;
    $puedeManage = auth()->user()?->can('almacen.manage') ?? false;
    $rows    = $productos ?? collect();
    $inicial = $inicial ?? false;
    $cols    = $puedeMover ? 7 : 6;
@endphp

@if(!$almacen)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">warehouse</i>
            No tienes ningún almacén disponible. Pídele a un administrador que cree uno (o que asocie tu frente a un almacén).
        </td>
    </tr>
@elseif($inicial && $rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">filter_alt</i>
            Usa los filtros (buscar, categoría o los atajos del panel) para ver el inventario de <strong>{{ $almacen->NOMBRE }}</strong>.
        </td>
    </tr>
@elseif($rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">search_off</i>
            No hay productos que coincidan con los filtros en <strong>{{ $almacen->NOMBRE }}</strong>.
            @if(request()->filled('search'))
                <br><span style="display:inline-block;margin-top:6px;font-size:12.5px;color:#94a3b8;max-width:520px;">
                    El producto puede existir en el catálogo global pero <strong>aún no tiene movimientos en este almacén</strong>. Registra una entrada (Recepción) o un traspaso para que aparezca aquí.
                </span>
            @endif
        </td>
    </tr>
@else
    @foreach($rows as $p)
        @php
            $saldo   = (float) ($p->saldo ?? 0);
            $minimo  = $p->minimo !== null ? (float) $p->minimo : null;
            $bajo    = $minimo !== null && $saldo <= $minimo;
            $codJs   = addslashes($p->CODIGO);
            $nomJs   = addslashes($p->NOMBRE);
            $umJs    = addslashes($p->UM);
            $catJs   = addslashes($p->CATEGORIA ?? '');
            $ubiJs   = addslashes($p->UBICACION ?? '');
            $minArg  = $minimo !== null ? $minimo : 'null';
        @endphp
        <tr class="alm-row {{ $bajo ? 'alm-row-bajo' : '' }} {{ $puedeMover ? 'alm-row-clickable' : '' }}"
            data-id-producto="{{ $p->ID_PRODUCTO }}" data-codigo="{{ $p->CODIGO }}" data-nombre="{{ $p->NOMBRE }}" data-um="{{ $p->UM }}" data-saldo="{{ $saldo }}">
            <td style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">{{ $p->CODIGO }}</td>
            {{-- Descripción + tooltip-bubble con la UBICACION (mismo patrón de /admin/equipos).
                 El tooltip se activa al hover de cualquier parte de la fila por la regla CSS
                 `.alm-row:hover .tooltip-bubble` que agregué en index.blade.php. --}}
            <td style="font-weight:600;color:#1e293b;position:relative;">
                {{ $p->NOMBRE }}
                @if(!empty($p->UBICACION))
                    <div class="tooltip-bubble" style="pointer-events:none;opacity:0;visibility:hidden;position:absolute;bottom:100%;left:0;transform:translateY(5px);background:#1e293b;color:#fff;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:500;white-space:normal;width:max-content;max-width:240px;word-wrap:break-word;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transition:all 0.2s ease-in-out;z-index:50;margin-bottom:5px;">
                        📍 {{ $p->UBICACION }}
                        <div style="position:absolute;top:100%;left:30px;margin-left:-4px;border-width:4px;border-style:solid;border-color:#1e293b transparent transparent transparent;"></div>
                    </div>
                @endif
            </td>
            <td style="text-align:center;color:#475569;">{{ $p->UM }}</td>
            <td style="color:#475569;">{{ $p->CATEGORIA ?: '—' }}</td>
            {{-- El color del texto siempre es negro (#0f172a). El stock bajo se indica con
                 el fondo rojo de la fila (.alm-row-bajo) y el icono ⚠ amarillo. --}}
            <td style="text-align:center;font-weight:800;font-size:15px;color:#0f172a;">
                {{ rtrim(rtrim(number_format($saldo, 3, '.', ','), '0'), '.') ?: '0' }}
                @if($bajo)<i class="material-icons" style="font-size:14px;color:#f59e0b;vertical-align:middle;" title="Stock en o por debajo del mínimo">warning</i>@endif
            </td>
            @if($puedeMover)
            {{-- Cantidad de salida por fila: el input se habilita SOLO cuando la fila está
                 seleccionada (clic en cualquier parte fuera de inputs/botones). El valor se
                 persiste en almSeleccion[id].cantidad y sobrevive a recargas del tbody
                 (paginación / filtros) gracias a almSelApplyToVisible. La validación
                 (cantidad > 0 y ≤ stock) ocurre al confirmar la Nota de Entrega. --}}
            <td style="text-align:center;white-space:nowrap;width:110px;" data-no-toggle>
                <input type="number" class="alm-row-cant" min="0.001" step="any" placeholder="0"
                       disabled
                       style="width:90px;padding:5px 6px;border:1px solid #cbd5e0;border-radius:7px;text-align:right;font-size:13px;font-weight:700;background:#f1f5f9;color:#94a3b8;"
                       onclick="event.stopPropagation();"
                       oninput="window.almRowCantInput && window.almRowCantInput(this)">
            </td>
            @endif
            <td style="text-align:center;white-space:nowrap;width:60px;" data-no-toggle>
                <button type="button" class="btn-details-mini" title="Ver detalles del producto"
                        onclick="window.almAbrirDetalle({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}','{{ $catJs }}',{{ $saldo }},{{ $minArg }},'{{ $ubiJs }}')">
                    <i class="material-icons" style="font-size:18px;">visibility</i>
                </button>
            </td>
        </tr>
    @endforeach
@endif
