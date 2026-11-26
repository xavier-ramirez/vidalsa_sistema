{{-- Las cisternas de UN patio en el mes elegido, en una sola tabla: cada fila es una solicitud
     (número y plan de despacho); cuando la cisterna llega se le pone la llegada real, los litros y la
     foto, ahí mismo. La última fila (vacía) es para una solicitud nueva; al llenarla aparece otra.
     Solo las que llegaron cuentan en lo recibido. Se repinta sola (consumibles.cupos?parcial=1&patio=…).
     Recibe $clave, $p (de CuposCombustibleController::datos), $mes y $meses. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $puede = auth()->user()->can('super.admin');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $desde = \App\Http\Controllers\CuposCombustibleController::desde()->toDateString();
    $hoy = now()->toDateString();
    $tope = now()->startOfMonth()->addMonths(3)->endOfMonth()->toDateString();
    $ym = $mes->format('Y-m');
    $programadas = $p['filas']->count() - $p['recibidas'];
@endphp
<div class="cupos-panel" data-panel="{{ $clave }}" data-mes="{{ $ym }}" data-siguiente="{{ $p['siguiente'] }}"
    style="--color: {{ $colores[$clave] ?? '#1F4E79' }};" hidden>
    <form class="cupos-bloque" data-accion="cisterna" novalidate>
        <input type="hidden" name="patio" value="{{ $clave }}">
        <div class="cupos-tbl-cab">
            <h3><i class="material-icons">local_shipping</i> Cisternas</h3>
            {{-- El mes: el actual para cargar; los anteriores son el historial; los que vienen, para programar. --}}
            <select class="cupos-mes-sel" data-elegir-mes aria-label="Mes">
                @foreach ($meses as $clMes => $nombreMes)
                    <option value="{{ $clMes }}" @selected($clMes === $ym)>{{ $nombreMes }}</option>
                @endforeach
            </select>
            <button type="button" class="cupos-hist-btn cupos-hist-excel" data-excel-historial="{{ $clave }}"
                data-nombre="Cisternas_Cupo_Gasoil_{{ str_replace(' ', '_', $p['nombre']) }}.xlsx" title="Todas las cisternas del patio, mes a mes, en Excel">
                <i class="material-icons">download</i> Excel
            </button>
        </div>

        <div class="cupos-tbl-marco">
            <table class="cupos-tbl">
                <thead>
                    <tr>
                        <th class="cupos-c-num" title="Número de solicitud">N.º</th>
                        <th title="Fecha del plan de despacho">Plan</th>
                        <th title="Fecha en que llegó la cisterna">Llegada</th>
                        <th class="num">Litros</th>
                        <th class="cupos-c-acc" title="Foto de la cisterna">Foto</th>
                        @if ($puede)<th class="cupos-c-acc"></th>@endif
                    </tr>
                </thead>
                <tbody data-filas>
                    @foreach ($p['filas'] as $k)
                        @php
                            $llego = $k->llego();
                            $texto = $p['nombre'] . ($k->NUMERO ? ' · Solicitud ' . $k->NUMERO : '')
                                . ' · ' . ($llego ? $k->FECHA->format('d/m/Y') . ' · ' . $lt($k->LITROS) . ' L' : 'plan ' . optional($k->FECHA_PLAN)->format('d/m/Y'));
                        @endphp
                        <tr data-fila data-id="{{ $k->ID_CISTERNA }}" class="{{ $llego ? 'cupos-llego' : 'cupos-programada' }}">
                            @if ($puede)
                                <td class="cupos-c-num"><span class="cupos-estado" title="{{ $llego ? 'Recibida' : 'Programada' }}"></span><input type="text" data-campo="numero" inputmode="numeric" value="{{ $k->NUMERO }}" aria-label="Número de solicitud" autocomplete="off"></td>
                                <td data-l="Plan"><input type="date" data-campo="plan" value="{{ optional($k->FECHA_PLAN)->toDateString() }}" min="{{ $desde }}" max="{{ $tope }}" aria-label="Plan de despacho"></td>
                                <td data-l="Llegada"><input type="date" data-campo="llegada" value="{{ optional($k->FECHA)->toDateString() }}" min="{{ $desde }}" max="{{ $hoy }}" aria-label="Llegada"></td>
                                <td data-l="Litros"><input type="text" data-campo="litros" class="num" inputmode="decimal" value="{{ $k->LITROS !== null ? $lt($k->LITROS) : '' }}" placeholder="—" aria-label="Litros" autocomplete="off"></td>
                            @else
                                <td class="cupos-c-num"><span class="cupos-estado" title="{{ $llego ? 'Recibida' : 'Programada' }}"></span>{{ $k->NUMERO ?? '—' }}</td>
                                <td class="cupos-txt" data-l="Plan">{{ optional($k->FECHA_PLAN)->format('d/m/Y') ?? '—' }}</td>
                                <td class="cupos-txt" data-l="Llegada">{{ optional($k->FECHA)->format('d/m/Y') ?? '—' }}</td>
                                <td class="cupos-txt num" data-l="Litros">{{ $k->LITROS !== null ? $lt($k->LITROS) : '—' }}</td>
                            @endif
                            <td class="cupos-c-acc">
                                @if ($k->FOTO)
                                    <button type="button" class="cupos-foto-btn cupos-foto-tiene" title="Ver la foto" data-ver-foto="{{ $k->FOTO }}"
                                        data-id-foto="{{ $puede ? $k->ID_CISTERNA : '' }}" data-texto="{{ $texto }}"><i class="material-icons">image</i></button>
                                @elseif ($puede)
                                    <label class="cupos-foto-btn" title="Foto de la cisterna (sale con Guardar)"><input type="file" accept="image/*" data-foto-fila hidden><i class="material-icons">photo_camera</i></label>
                                @endif
                            </td>
                            @if ($puede)
                                <td class="cupos-c-acc"><button type="button" class="cupos-borrar" data-borrar="{{ $k->ID_CISTERNA }}" data-texto="{{ $texto }}" title="Quitar"><i class="material-icons">delete_outline</i></button></td>
                            @endif
                        </tr>
                    @endforeach
                    @if ($puede)
                        {{-- Fila para una solicitud nueva (la vacía del final no cuenta al guardar). --}}
                        <tr data-fila data-nueva>
                            <td class="cupos-c-num"><span class="cupos-estado"></span><input type="text" data-campo="numero" inputmode="numeric" placeholder="{{ $p['siguiente'] }}" aria-label="Número de solicitud" autocomplete="off"></td>
                            <td data-l="Plan"><input type="date" data-campo="plan" min="{{ $desde }}" max="{{ $tope }}" aria-label="Plan de despacho"></td>
                            <td data-l="Llegada"><input type="date" data-campo="llegada" min="{{ $desde }}" max="{{ $hoy }}" aria-label="Llegada"></td>
                            <td data-l="Litros"><input type="text" data-campo="litros" class="num" inputmode="decimal" placeholder="Litros" aria-label="Litros" autocomplete="off"></td>
                            <td class="cupos-c-acc"><label class="cupos-foto-btn" title="Foto de la cisterna (sale con Guardar)"><input type="file" accept="image/*" data-foto-fila hidden><i class="material-icons">photo_camera</i></label></td>
                            <td class="cupos-c-acc"><button type="button" class="cupos-borrar" data-quitar-fila title="Quitar fila"><i class="material-icons">close</i></button></td>
                        </tr>
                    @elseif ($p['filas']->isEmpty())
                        <tr><td colspan="5" class="cupos-vacio"><i class="material-icons">local_shipping</i>Sin cisternas en {{ \App\Http\Controllers\CuposCombustibleController::nombreMes($mes) }}.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

        <div class="cupos-pie">
            <span class="cupos-resumen-mes" data-resumen-mes>
                <b>{{ $p['recibidas'] === 1 ? '1 recibida' : $p['recibidas'] . ' recibidas' }} · {{ $lt($p['recibido']) }} L</b>
                @if ($programadas > 0) · {{ $programadas === 1 ? '1 programada' : $programadas . ' programadas' }}@endif
                @if ($p['cupo_mes'] !== null && $p['cupo_mes'] !== $p['cupo']) · cupo de ese mes {{ $lt($p['cupo_mes']) }} L @endif
            </span>
            @if ($puede)
                <span class="cupos-cambios" data-cambios></span>
                <button type="submit" class="btn-primary-maquinaria btn-compacto" data-guardar disabled><i class="material-icons">save</i> Guardar</button>
            @endif
        </div>
    </form>
</div>
