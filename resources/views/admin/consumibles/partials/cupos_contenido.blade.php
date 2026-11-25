{{-- Contenido de Cupos de Gasoil (se repinta solo, tras guardar o al cambiar de mes): lo de cada
     patio en una línea, el formulario de la cisterna, las cisternas del mes y el modal del cupo. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $nombreMes = fn ($m) => \App\Http\Controllers\CuposCombustibleController::nombreMes($m);
    $hoy = now()->isSameMonth($mes) ? now()->toDateString() : $mes->toDateString();
@endphp
<div id="cuposDatos" data-mes="{{ $mes->format('Y-m') }}" hidden></div>

<div class="cupos-patios">
    @foreach ($patios as $clave => $p)
        @php
            $pasado = $p['cupo'] !== null && $p['disponible'] < 0;
            $pct = $p['cupo'] ? min(100, $p['pedido'] / $p['cupo'] * 100) : ($p['pedido'] > 0 ? 100 : 0);
        @endphp
        <div class="cupo-patio" style="border-left-color: {{ $colores[$clave] }};">
            <b>{{ $p['nombre'] }}</b>
            <span>Cupo <b>{{ $p['cupo'] !== null ? $lt($p['cupo']) . ' L' : 'sin cargar' }}</b></span>
            <span>Pedido <b>{{ $lt($p['pedido']) }} L</b> ({{ $p['cisternas'] }})</span>
            <span>Disponible <b style="color: {{ $pasado ? '#dc2626' : 'inherit' }};">{{ $p['cupo'] !== null ? $lt($p['disponible']) . ' L' : '—' }}</b></span>
            <div class="cupo-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></i></div>
        </div>
    @endforeach
</div>

@if ($puede)
    <form class="cupo-form" data-accion="cisterna">
        <div class="cupo-patio-elegir" role="radiogroup" aria-label="Patio">
            @foreach ($patios as $clave => $p)
                <label><input type="radio" name="patio" value="{{ $clave }}" required> {{ str_replace('Patio ', '', $p['nombre']) }}</label>
            @endforeach
        </div>
        <input type="date" name="fecha" required value="{{ $hoy }}" title="Fecha"
            min="{{ $mes->toDateString() }}" max="{{ $mes->copy()->endOfMonth()->toDateString() }}">
        <input type="text" name="cisterna" maxlength="60" placeholder="Cisterna (placa / n.º)">
        <input type="number" name="litros" min="0.01" step="any" required placeholder="Litros">
        <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">add</i> Agregar</button>
    </form>
@endif

<table class="cupo-tabla">
    <thead><tr><th>Fecha</th><th>Patio</th><th>Cisterna</th><th style="text-align:right;">Litros</th>@if ($puede)<th></th>@endif</tr></thead>
    <tbody>
        @forelse ($cisternas as $k)
            <tr>
                <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                <td>{{ \App\Models\CupoCombustible::PATIOS[$k->PATIO] ?? $k->PATIO }}</td>
                <td>{{ $k->CISTERNA ?? '—' }}</td>
                <td style="text-align:right;">{{ $lt($k->LITROS) }}</td>
                @if ($puede)
                    <td style="width:34px;">
                        <button type="button" class="cupo-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                            data-texto="{{ $k->FECHA->format('d/m/Y') }} · {{ \App\Models\CupoCombustible::PATIOS[$k->PATIO] ?? $k->PATIO }} · {{ $k->CISTERNA ?? 'sin cisterna' }} · {{ $lt($k->LITROS) }} L">
                            <i class="material-icons">delete_outline</i>
                        </button>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $puede ? 5 : 4 }}" class="cupo-vacio">Sin cisternas pedidas en {{ $nombreMes($mes) }}.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- MODAL del cupo mensual: casi nunca cambia; rige desde el mes elegido hasta que se cambie. --}}
<div id="cuposModal" class="modal-overlay" onclick="if (event.target === this) this.classList.remove('active')">
    <div class="modal-content cupos-modal">
        <div class="cupos-modal-cab">
            <div>
                <h2>Cupo mensual de gasoil</h2>
                <p>Lo que se guarde rige desde {{ $nombreMes($mes) }} hasta que se cambie.</p>
            </div>
            <button type="button" class="cupos-modal-x" data-cerrar-modal title="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <form class="cupos-modal-cuerpo" data-accion="cupo">
            @foreach ($patios as $clave => $p)
                <label class="cupos-modal-campo">
                    <span>{{ $p['nombre'] }} <small>{{ $p['desde'] ? 'vigente desde ' . $nombreMes($p['desde']) : 'sin cupo' }}</small></span>
                    <input type="number" name="cupos[{{ $clave }}]" min="0" step="any" placeholder="Litros por mes"
                        value="{{ $p['cupo'] !== null ? $p['cupo'] : '' }}" @disabled(!$puede)>
                </label>
            @endforeach

            <table class="cupo-tabla">
                <thead><tr><th>Mes</th>@foreach ($patios as $p)<th style="text-align:right;">{{ str_replace('Patio ', '', $p['nombre']) }} <span style="opacity:.7;">pedido / cupo</span></th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($historial as $fila)
                        <tr>
                            <td>{{ $nombreMes($fila['mes']) }}</td>
                            @foreach ($fila['patios'] as $r)
                                <td style="text-align:right; color: {{ $r['cupo'] !== null && $r['pedido'] > $r['cupo'] ? '#dc2626' : 'inherit' }};">
                                    {{ $lt($r['pedido']) }} / {{ $r['cupo'] !== null ? $lt($r['cupo']) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="con-acciones" style="margin-top:14px;">
                <button type="button" class="btn-primary-maquinaria btn-secondary btn-compacto" data-cerrar-modal>Cerrar</button>
                @if ($puede)
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">save</i> Guardar</button>
                @endif
            </div>
        </form>
    </div>
</div>
