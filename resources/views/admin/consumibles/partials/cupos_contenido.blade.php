{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo, tras guardar o al cambiar de mes): una
     pestaña por patio con su cupo (se edita ahí mismo), el formulario de la cisterna, las cisternas
     del mes con acumulado y disponible, y los últimos 12 meses. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $nombreMes = fn ($m) => \App\Http\Controllers\CuposCombustibleController::nombreMes($m);
    $hoy = now()->isSameMonth($mes) ? now()->toDateString() : $mes->toDateString();
@endphp
<div id="cuposDatos" data-mes="{{ $mes->format('Y-m') }}" hidden></div>
<div id="cuposResumenNuevo" hidden>@include('admin.consumibles.partials.cupos_resumen')</div>

<div class="cupos-tabs" role="tablist">
    @foreach ($patios as $clave => $p)
        <button type="button" class="cupos-tab" role="tab" data-tab="{{ $clave }}" style="--color: {{ $colores[$clave] }};">
            {{ $p['nombre'] }}
            <small>{{ $p['cupo'] !== null ? $lt($p['disponible']) . ' L disp.' : 'sin cupo' }}</small>
        </button>
    @endforeach
</div>

@foreach ($patios as $clave => $p)
    @php
        $pasado = $p['cupo'] !== null && $p['disponible'] < 0;
        $pct = $p['cupo'] ? min(100, $p['pedido'] / $p['cupo'] * 100) : ($p['pedido'] > 0 ? 100 : 0);
    @endphp
    <div class="cupos-panel" data-panel="{{ $clave }}" hidden>
        <div class="cupos-cifras">
            <div class="cupos-cifra cupos-cifra-cupo">
                <small>Cupo mensual</small>
                <div class="cupos-cupo-ver">
                    <b>{{ $p['cupo'] !== null ? $lt($p['cupo']) . ' L' : 'Sin cargar' }}</b>
                    @if ($puede)
                        <button type="button" class="cupos-lapiz" data-editar-cupo title="{{ $p['cupo'] !== null ? 'Cambiar el cupo' : 'Cargar el cupo' }}">
                            <i class="material-icons">edit</i>
                        </button>
                    @endif
                </div>
                @if ($puede)
                    <form class="cupos-cupo-editar" data-accion="cupo" hidden>
                        <input type="number" name="cupos[{{ $clave }}]" min="0" step="any" required placeholder="Litros por mes"
                            value="{{ $p['cupo'] !== null ? $p['cupo'] : '' }}">
                        <button type="submit" class="cupos-mini" title="Guardar"><i class="material-icons">check</i></button>
                        <button type="button" class="cupos-mini cupos-mini-gris" data-cancelar-cupo title="Cancelar"><i class="material-icons">close</i></button>
                    </form>
                @endif
                <span class="cupos-nota">{{ $p['desde'] ? 'Vigente desde ' . $nombreMes($p['desde']) : 'Rige desde el mes elegido' }}</span>
            </div>
            <div class="cupos-cifra"><small>Pedido en {{ $nombreMes($mes) }}</small><b>{{ $lt($p['pedido']) }} L</b>
                <span class="cupos-nota">{{ $p['filas']->count() }} {{ $p['filas']->count() === 1 ? 'cisterna' : 'cisternas' }}</span></div>
            <div class="cupos-cifra"><small>Disponible</small>
                <b style="color: {{ $pasado ? '#dc2626' : '#0f172a' }};">{{ $p['cupo'] !== null ? $lt($p['disponible']) . ' L' : '—' }}</b>
                <span class="cupos-nota" style="color: {{ $pasado ? '#dc2626' : '' }};">{{ $pasado ? 'Se pasó del cupo' : ($p['cupo'] ? round(100 - $pct) . ' % del cupo' : '') }}</span></div>
        </div>
        <div class="cupos-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></i></div>

        @if ($puede)
            <form class="cupos-form" data-accion="cisterna">
                <input type="hidden" name="patio" value="{{ $clave }}">
                <input type="date" name="fecha" required value="{{ $hoy }}" title="Fecha"
                    min="{{ $mes->toDateString() }}" max="{{ $mes->copy()->endOfMonth()->toDateString() }}">
                <input type="text" name="cisterna" maxlength="60" placeholder="Cisterna (placa / n.º)">
                <input type="number" name="litros" min="0.01" step="any" required placeholder="Litros">
                <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">add</i> Agregar</button>
            </form>
        @endif

        <div class="cupos-tbl-wrap">
            <table class="cupos-tbl">
                <thead>
                    <tr><th>#</th><th>Fecha</th><th>Cisterna</th><th class="num">Litros</th><th class="num">Acumulado</th><th class="num">Disponible</th>@if ($puede)<th></th>@endif</tr>
                </thead>
                <tbody>
                    @forelse ($p['filas'] as $i => $f)
                        @php $k = $f['cisterna']; @endphp
                        <tr>
                            <td class="cupos-n">{{ $i + 1 }}</td>
                            <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                            <td>{{ $k->CISTERNA ?? '—' }}</td>
                            <td class="num">{{ $lt($k->LITROS) }} L</td>
                            <td class="num">{{ $lt($f['acumulado']) }} L</td>
                            <td class="num" style="color: {{ $f['disponible'] !== null && $f['disponible'] < 0 ? '#dc2626' : 'inherit' }};">{{ $f['disponible'] !== null ? $lt($f['disponible']) . ' L' : '—' }}</td>
                            @if ($puede)
                                <td class="cupos-acc">
                                    <button type="button" class="cupos-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                                        data-texto="{{ $p['nombre'] }} · {{ $k->FECHA->format('d/m/Y') }} · {{ $k->CISTERNA ?? 'sin cisterna' }} · {{ $lt($k->LITROS) }} L">
                                        <i class="material-icons">delete_outline</i>
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $puede ? 7 : 6 }}" class="cupos-vacio">Sin cisternas pedidas en {{ $nombreMes($mes) }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <h4 class="cupos-sub">En el tiempo</h4>
        <div class="cupos-tbl-wrap">
            <table class="cupos-tbl">
                <thead><tr><th>Mes</th><th class="num">Cupo</th><th class="num">Pedido</th><th class="num">Cisternas</th><th class="num">Disponible</th><th style="width:30%;">Uso</th></tr></thead>
                <tbody>
                    @foreach ($p['historial'] as $h)
                        @php
                            $disp = $h['cupo'] !== null ? $h['cupo'] - $h['pedido'] : null;
                            $uso = $h['cupo'] ? min(100, $h['pedido'] / $h['cupo'] * 100) : ($h['pedido'] > 0 ? 100 : 0);
                        @endphp
                        <tr class="{{ $h['mes']->isSameMonth($mes) ? 'cupos-actual' : '' }}">
                            <td><a href="#" data-mes-ir="{{ $h['mes']->format('Y-m') }}">{{ $nombreMes($h['mes']) }}</a></td>
                            <td class="num">{{ $h['cupo'] !== null ? $lt($h['cupo']) : '—' }}</td>
                            <td class="num">{{ $lt($h['pedido']) }}</td>
                            <td class="num">{{ $h['n'] }}</td>
                            <td class="num" style="color: {{ $disp !== null && $disp < 0 ? '#dc2626' : 'inherit' }};">{{ $disp !== null ? $lt($disp) : '—' }}</td>
                            <td><div class="cupos-barra cupos-barra-fina"><i style="width: {{ round($uso, 1) }}%; background: {{ $disp !== null && $disp < 0 ? '#dc2626' : $colores[$clave] }};"></i></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach
