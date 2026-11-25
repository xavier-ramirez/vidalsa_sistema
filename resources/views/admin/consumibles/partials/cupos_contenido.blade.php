{{-- Contenido de Cupos de gasoil (se repinta solo, tras guardar): una tarjeta por patio con su
     cupo del mes y las cisternas pedidas de él, y abajo el resumen de los últimos meses. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $mesTxt = \App\Http\Controllers\CuposCombustibleController::nombreMes($mes);
    $hoy = now()->isSameMonth($mes) ? now()->toDateString() : $mes->toDateString();
@endphp
<div id="cuposDatos" data-mes="{{ $mes->format('Y-m') }}"></div>
<div class="cupos-grid">
    @foreach ($patios as $clave => $p)
        @php
            $cupo = $p['cupo'];
            $pct = $cupo && $cupo->LITROS > 0 ? min(100, $p['pedido'] / $cupo->LITROS * 100) : ($p['pedido'] > 0 ? 100 : 0);
            $pasado = $cupo && $p['disponible'] < 0;
        @endphp
        <div class="admin-card cupo-tarjeta" data-patio="{{ $clave }}">
            <div class="cupo-titulo" style="border-left-color: {{ $colores[$clave] }};">
                <span>{{ $p['nombre'] }}</span>
                <small>Cupo de {{ $mesTxt }}</small>
            </div>

            @if ($puede)
                <form class="cupo-form" data-accion="cupo">
                    <input type="hidden" name="patio" value="{{ $clave }}">
                    <label>Cupo del mes (litros)</label>
                    <div class="cupo-fila">
                        <input type="number" name="litros" min="0" step="any" required placeholder="Ej. 150000"
                            value="{{ $cupo ? (float) $cupo->LITROS : '' }}">
                        <button type="submit" class="btn-primary-maquinaria btn-compacto">
                            <i class="material-icons">save</i> {{ $cupo ? 'Cambiar' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            @endif

            <div class="cupo-cifras">
                <div><small>Cupo</small><b>{{ $cupo ? $lt($cupo->LITROS) . ' L' : 'Sin cargar' }}</b></div>
                <div><small>Pedido ({{ $cupo ? $cupo->cisternas->count() : 0 }} {{ ($cupo ? $cupo->cisternas->count() : 0) === 1 ? 'cisterna' : 'cisternas' }})</small><b>{{ $lt($p['pedido']) }} L</b></div>
                <div><small>Disponible</small><b style="color: {{ $pasado ? '#dc2626' : '#0f172a' }};">{{ $cupo ? $lt($p['disponible']) . ' L' : '—' }}</b></div>
            </div>
            <div class="cupo-barra"><span style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></span></div>
            @if ($pasado)
                <div class="cupo-aviso">Se pidió {{ $lt(-$p['disponible']) }} L más que el cupo.</div>
            @endif

            <table class="cupo-tabla">
                <thead><tr><th>Fecha</th><th>Cisterna</th><th style="text-align:right;">Litros</th>@if ($puede)<th></th>@endif</tr></thead>
                <tbody>
                    @forelse ($cupo?->cisternas ?? [] as $k)
                        <tr>
                            <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                            <td>{{ $k->CISTERNA ?? '—' }}</td>
                            <td style="text-align:right;">{{ $lt($k->LITROS) }}</td>
                            @if ($puede)
                                <td style="width:34px;">
                                    <button type="button" class="cupo-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                                        data-texto="{{ $k->FECHA->format('d/m/Y') }} · {{ $k->CISTERNA ?? 'sin cisterna' }} · {{ $lt($k->LITROS) }} L">
                                        <i class="material-icons">delete_outline</i>
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $puede ? 4 : 3 }}" class="cupo-vacio">Sin cisternas pedidas en {{ $mesTxt }}.</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if ($puede && $cupo)
                <form class="cupo-form cupo-nueva" data-accion="cisterna">
                    <input type="hidden" name="patio" value="{{ $clave }}">
                    <input type="date" name="fecha" required value="{{ $hoy }}"
                        min="{{ $mes->toDateString() }}" max="{{ $mes->copy()->endOfMonth()->toDateString() }}" title="Fecha">
                    <input type="text" name="cisterna" maxlength="60" placeholder="Cisterna (placa / n.º)">
                    <input type="number" name="litros" min="0.01" step="any" required placeholder="Litros">
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">add</i> Agregar</button>
                </form>
            @elseif ($puede)
                <div class="cupo-vacio">Cargue primero el cupo para anotar las cisternas.</div>
            @endif
        </div>
    @endforeach
</div>

<div class="admin-card" style="padding: 18px 20px; margin-top: 20px;">
    <div class="cupo-titulo" style="border-left-color:#94a3b8;"><span>Últimos meses</span></div>
    <div style="overflow-x:auto;">
        <table class="cupo-tabla cupo-resumen">
            <thead>
                <tr>
                    <th rowspan="2">Mes</th>
                    @foreach ($patios as $clave => $p)<th colspan="3" style="text-align:center;">{{ $p['nombre'] }}</th>@endforeach
                </tr>
                <tr>
                    @foreach ($patios as $p)<th style="text-align:right;">Cupo</th><th style="text-align:right;">Pedido</th><th style="text-align:right;">Disponible</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen as $fila)
                    <tr>
                        <td><a href="{{ route('consumibles.cupos', ['mes' => $fila['mes']->format('Y-m')]) }}" data-mes-ir="{{ $fila['mes']->format('Y-m') }}">{{ \App\Http\Controllers\CuposCombustibleController::nombreMes($fila['mes']) }}</a></td>
                        @foreach ($fila['patios'] as $r)
                            @if ($r)
                                <td style="text-align:right;">{{ $lt($r['cupo']) }}</td>
                                <td style="text-align:right;">{{ $lt($r['pedido']) }} <small style="color:#94a3b8;">({{ $r['cisternas'] }})</small></td>
                                <td style="text-align:right; color: {{ $r['cupo'] - $r['pedido'] < 0 ? '#dc2626' : 'inherit' }};">{{ $lt($r['cupo'] - $r['pedido']) }}</td>
                            @else
                                <td colspan="3" style="text-align:center; color:#94a3b8;">Sin cupo</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
