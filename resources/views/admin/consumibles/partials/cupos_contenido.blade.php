{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo, tras guardar o al cambiar de mes): los dos
     patios lado a lado, cada uno con su cupo fijo (lápiz para cambiarlo), las cisternas surtidas del
     mes (fecha y litros) y lo que queda. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $mesTxt = \App\Http\Controllers\CuposCombustibleController::nombreMes($mes);
    $hoy = now()->isSameMonth($mes) ? now()->toDateString() : $mes->toDateString();
@endphp
<div id="cuposDatos" data-mes="{{ $mes->format('Y-m') }}" hidden></div>

<div class="cupos-patios">
    @foreach ($patios as $clave => $p)
        @php
            $pasado = $p['cupo'] !== null && $p['quedan'] < 0;
            $pct = $p['cupo'] ? min(100, $p['surtido'] / $p['cupo'] * 100) : ($p['surtido'] > 0 ? 100 : 0);
        @endphp
        <div class="cupos-patio" data-panel="{{ $clave }}" style="--color: {{ $colores[$clave] }};">
            <div class="cupos-patio-cab">
                <b>{{ $p['nombre'] }}</b>
                <span class="cupos-cupo-ver">
                    Cupo: <b>{{ $p['cupo'] !== null ? $lt($p['cupo']) . ' L' : 'sin cargar' }}</b>
                    @if ($puede)
                        <button type="button" class="cupos-lapiz" data-editar-cupo title="Cambiar el cupo"><i class="material-icons">edit</i></button>
                    @endif
                </span>
                @if ($puede)
                    <form class="cupos-cupo-editar" data-accion="cupo" hidden>
                        <input type="hidden" name="patio" value="{{ $clave }}">
                        <input type="number" name="litros" min="0" step="any" required placeholder="Cupo en litros"
                            value="{{ $p['cupo'] !== null ? $p['cupo'] : '' }}">
                        <button type="submit" class="cupos-mini" title="Guardar"><i class="material-icons">check</i></button>
                        <button type="button" class="cupos-mini cupos-mini-gris" data-cancelar-cupo title="Cancelar"><i class="material-icons">close</i></button>
                    </form>
                @endif
            </div>

            <div class="cupos-cifras">
                <span>Surtido <b>{{ $lt($p['surtido']) }} L</b> ({{ $p['filas']->count() }} {{ $p['filas']->count() === 1 ? 'cisterna' : 'cisternas' }})</span>
                <span>Quedan <b style="color: {{ $pasado ? '#dc2626' : 'inherit' }};">{{ $p['cupo'] !== null ? $lt($p['quedan']) . ' L' : '—' }}</b></span>
            </div>
            <div class="cupos-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></i></div>

            @if ($puede)
                <form class="cupos-form" data-accion="cisterna">
                    <input type="hidden" name="patio" value="{{ $clave }}">
                    <input type="date" name="fecha" required value="{{ $hoy }}" title="Fecha"
                        min="{{ $mes->toDateString() }}" max="{{ $mes->copy()->endOfMonth()->toDateString() }}">
                    <input type="number" name="litros" min="0.01" step="any" required placeholder="Litros">
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">add</i> Agregar</button>
                </form>
            @endif

            <div class="cupos-tbl-wrap">
                <table class="cupos-tbl">
                    <thead><tr><th>#</th><th>Fecha</th><th class="num">Litros</th><th class="num">Quedan</th>@if ($puede)<th></th>@endif</tr></thead>
                    <tbody>
                        @forelse ($p['filas'] as $i => $f)
                            @php $k = $f['cisterna']; @endphp
                            <tr>
                                <td class="cupos-n">{{ $i + 1 }}</td>
                                <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                                <td class="num">{{ $lt($k->LITROS) }} L</td>
                                <td class="num" style="color: {{ $f['quedan'] !== null && $f['quedan'] < 0 ? '#dc2626' : 'inherit' }};">{{ $f['quedan'] !== null ? $lt($f['quedan']) . ' L' : '—' }}</td>
                                @if ($puede)
                                    <td class="cupos-acc">
                                        <button type="button" class="cupos-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                                            data-texto="{{ $p['nombre'] }} · {{ $k->FECHA->format('d/m/Y') }} · {{ $lt($k->LITROS) }} L">
                                            <i class="material-icons">delete_outline</i>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $puede ? 5 : 4 }}" class="cupos-vacio">Sin cisternas en {{ $mesTxt }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
