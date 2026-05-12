{{-- Filas de la tabla de inventario. $productos = paginator|null ; $almacen = Almacen|null --}}
@php
    $puedeMover  = auth()->user()?->can('almacen.movimiento') ?? false;
    $puedeManage = auth()->user()?->can('almacen.manage') ?? false;
    $rows = $productos ?? collect();
    $cols = 7;
@endphp

@if(!$almacen)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">warehouse</i>
            No tienes ningún almacén disponible. Pídele a un administrador que cree uno (o que asocie tu frente a un almacén).
        </td>
    </tr>
@elseif($rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">inventory_2</i>
            No hay productos que coincidan con los filtros en <strong>{{ $almacen->NOMBRE }}</strong>.
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
            $minArg  = $minimo !== null ? $minimo : 'null';
        @endphp
        <tr class="alm-row {{ $bajo ? 'alm-row-bajo' : '' }} {{ $puedeMover ? 'alm-row-clickable' : '' }}"
            data-id-producto="{{ $p->ID_PRODUCTO }}" data-codigo="{{ $p->CODIGO }}" data-nombre="{{ $p->NOMBRE }}" data-um="{{ $p->UM }}" data-saldo="{{ $saldo }}"
            @if($puedeMover)title="Clic para seleccionar este producto"@endif>
            <td style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">{{ $p->CODIGO }}</td>
            <td style="font-weight:600;color:#1e293b;">{{ $p->NOMBRE }}</td>
            <td style="text-align:center;color:#475569;">{{ $p->UM }}</td>
            <td style="color:#475569;">{{ $p->CATEGORIA ?: '—' }}</td>
            <td style="text-align:right;font-weight:800;font-size:15px;{{ $bajo ? 'color:#dc2626;' : 'color:#0f172a;' }}">
                {{ rtrim(rtrim(number_format($saldo, 3, '.', ','), '0'), '.') ?: '0' }}
                @if($bajo)<i class="material-icons" style="font-size:14px;color:#f59e0b;vertical-align:middle;" title="Stock en o por debajo del mínimo">warning</i>@endif
            </td>
            <td style="text-align:right;color:#94a3b8;font-size:13px;">{{ $minimo !== null ? rtrim(rtrim(number_format($minimo, 3, '.', ','), '0'), '.') : '—' }}</td>
            <td style="text-align:center;white-space:nowrap;">
                @if($puedeMover)
                    <button type="button" class="alm-btn alm-btn-in"  title="Registrar entrada"
                            onclick="window.almAbrirMovimiento('ENTRADA',{{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}',{{ $saldo }})"><i class="material-icons" style="font-size:16px;">add</i></button>
                    <button type="button" class="alm-btn alm-btn-out" title="Registrar salida"
                            onclick="window.almAbrirMovimiento('SALIDA',{{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}',{{ $saldo }})"><i class="material-icons" style="font-size:16px;">remove</i></button>
                    <button type="button" class="alm-btn alm-btn-adj" title="Ajustar saldo / fijar mínimo"
                            onclick="window.almAbrirAjuste({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}',{{ $saldo }},{{ $minArg }})"><i class="material-icons" style="font-size:16px;">tune</i></button>
                @endif
                <button type="button" class="alm-btn alm-btn-hist" title="Ver movimientos de este producto"
                        onclick="window.almAbrirKardex({{ $p->ID_PRODUCTO }},'{{ $codJs }} — {{ $nomJs }}')"><i class="material-icons" style="font-size:16px;">history</i></button>
                @if($puedeManage)
                    <button type="button" class="alm-btn alm-btn-edit" title="Editar producto"
                            onclick="window.almEditarProducto({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}','{{ $catJs }}')"><i class="material-icons" style="font-size:16px;">edit</i></button>
                    <button type="button" class="alm-btn alm-btn-del" title="Eliminar / desactivar producto"
                            onclick="window.almEliminarProducto({{ $p->ID_PRODUCTO }},'{{ $codJs }} — {{ $nomJs }}')"><i class="material-icons" style="font-size:16px;">delete_outline</i></button>
                @endif
                @if(!$puedeMover && !$puedeManage)<span style="color:#cbd5e0;font-size:12px;display:none;">—</span>@endif
            </td>
        </tr>
    @endforeach
@endif
