{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo tras guardar). Arriba el patio (desplegable);
     del patio elegido, tres bloques: el resumen del mes (cupo con su botón para cambiarlo, quedan y
     la barra de lo surtido), la carga de cisternas nuevas (fecha y litros; «Quedan» se calcula al
     escribir) y el historial mes a mes. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $nombreMes = fn ($m) => \App\Http\Controllers\CuposCombustibleController::nombreMes($m);
    $rojo = fn ($n) => $n !== null && $n < 0 ? ' cupos-pasado' : '';
@endphp

<div class="cupos-patio">
    <i class="material-icons">location_on</i>
    <select data-elegir-patio aria-label="Patio">
        @foreach ($patios as $clave => $p)
            <option value="{{ $clave }}">{{ $p['nombre'] }}</option>
        @endforeach
    </select>
</div>

@foreach ($patios as $clave => $p)
    @php
        $pct = $p['cupo'] ? min(100, $p['surtido'] / $p['cupo'] * 100) : ($p['surtido'] > 0 ? 100 : 0);
        $pasado = $p['cupo'] !== null && $p['quedan'] < 0;
    @endphp
    <div class="cupos-panel" data-panel="{{ $clave }}" style="--color: {{ $colores[$clave] }};" hidden
        data-mes="{{ $hoy->format('Y-m') }}" data-quedan="{{ $p['quedan'] ?? '' }}">

        {{-- Resumen del mes en curso. --}}
        <section class="cupos-resumen">
            <div class="cupos-resumen-fila">
                <div class="cupos-dato">
                    <small>Cupo mensual</small>
                    <div class="cupos-cupo-ver">
                        @if ($p['cupo'] !== null)
                            <b>{{ $lt($p['cupo']) }} L</b>
                            @if ($puede)
                                <button type="button" class="cupos-lapiz" data-editar-cupo title="Cambiar el cupo"><i class="material-icons">edit</i></button>
                            @endif
                        @elseif ($puede)
                            <button type="button" class="cupos-cargar-cupo" data-editar-cupo><i class="material-icons">add</i> Cargar cupo</button>
                        @else
                            <b class="cupos-tenue">Sin cargar</b>
                        @endif
                    </div>
                    @if ($puede)
                        <form class="cupos-cupo-editar" data-accion="cupo" hidden>
                            <input type="hidden" name="patio" value="{{ $clave }}">
                            <input type="text" name="litros" inputmode="decimal" required placeholder="Litros al mes" autocomplete="off"
                                value="{{ $p['cupo'] !== null ? $lt($p['cupo']) : '' }}">
                            <button type="submit" class="cupos-mini" title="Guardar"><i class="material-icons">check</i></button>
                            <button type="button" class="cupos-mini cupos-mini-gris" data-cancelar-cupo title="Cancelar"><i class="material-icons">close</i></button>
                        </form>
                    @endif
                </div>
                <div class="cupos-dato cupos-dato-der">
                    <small>Quedan · {{ $nombreMes($hoy) }}</small>
                    @if ($p['cupo'] !== null)
                        <b class="cupos-quedan{{ $rojo($p['quedan']) }}">{{ $lt($p['quedan']) }} L</b>
                    @else
                        <b class="cupos-tenue">—</b>
                    @endif
                </div>
            </div>
            <div class="cupos-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></i></div>
            <div class="cupos-surtido">
                Surtido <b>{{ $lt($p['surtido']) }} L</b>
                @if ($p['cupo'] !== null) de {{ $lt($p['cupo']) }} L · {{ round($pct) }} % @else · cargue el cupo para ver cuánto queda @endif
            </div>
        </section>

        {{-- Cisternas nuevas: una fila cada una; Enter u «Otra cisterna» agrega otra y se guardan juntas.
             novalidate: las filas vacías no cuentan y no frenan el guardado; valida el servidor, fila por fila. --}}
        @if ($puede)
            <form class="cupos-bloque" data-accion="cisterna" novalidate>
                <input type="hidden" name="patio" value="{{ $clave }}">
                <h3>Registrar cisternas</h3>
                <div class="cupos-nuevas-cab"><span>Fecha</span><span>Litros</span><span></span><span></span></div>
                <div class="cupos-nuevas" data-filas>
                    <div class="cupos-nueva" data-fila>
                        <input type="date" data-campo="fecha" required value="{{ now()->toDateString() }}" aria-label="Fecha"
                            min="{{ \App\Http\Controllers\CuposCombustibleController::desde()->toDateString() }}" max="{{ now()->toDateString() }}">
                        <label class="cupos-litros">
                            <input type="text" data-campo="litros" inputmode="decimal" placeholder="0" autocomplete="off" aria-label="Litros">
                            <span>L</span>
                        </label>
                        <span class="cupos-previo" data-previo></span>
                        <button type="button" class="cupos-borrar" data-quitar-fila title="Quitar fila"><i class="material-icons">close</i></button>
                    </div>
                </div>
                <div class="cupos-pie">
                    <button type="button" class="cupos-otra" data-otra-fila><i class="material-icons">add</i> Otra cisterna</button>
                    <span class="cupos-lote-total" data-lote-total></span>
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">save</i> Guardar</button>
                </div>
            </form>
        @endif

        {{-- Historial: un bloque por mes con su resumen y sus cisternas. --}}
        <section class="cupos-bloque">
            <h3>Historial</h3>
            @forelse ($p['meses'] as $m)
                <div class="cupos-mes">
                    <div class="cupos-mes-cab">
                        <b>{{ $nombreMes($m['mes']) }}</b>
                        <span>@if ($m['cupo'] !== $p['cupo'])Cupo {{ $m['cupo'] !== null ? $lt($m['cupo']) . ' L' : '—' }} · @endif{{ $m['filas']->count() === 1 ? '1 cisterna' : $m['filas']->count() . ' cisternas' }} · Surtido {{ $lt($m['surtido']) }} L · Quedó <b class="{{ $rojo($m['quedan']) }}">{{ $m['quedan'] !== null ? $lt($m['quedan']) . ' L' : '—' }}</b></span>
                    </div>
                    @foreach ($m['filas'] as $f)
                        @php $k = $f['cisterna']; @endphp
                        <div class="cupos-hist">
                            <span class="cupos-hist-fecha">{{ $k->FECHA->format('d/m/Y') }}</span>
                            <span class="cupos-hist-litros">{{ $lt($k->LITROS) }} L</span>
                            <span class="cupos-hist-queda{{ $rojo($f['quedan']) }}">@if ($f['quedan'] !== null)<small>quedan</small> {{ $lt($f['quedan']) }} L @endif</span>
                            @if ($puede)
                                <button type="button" class="cupos-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                                    data-texto="{{ $p['nombre'] }} · {{ $k->FECHA->format('d/m/Y') }} · {{ $lt($k->LITROS) }} L">
                                    <i class="material-icons">delete_outline</i>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="cupos-vacio">
                    <i class="material-icons">local_shipping</i>
                    Todavía no hay cisternas de {{ $p['nombre'] }}.
                </div>
            @endforelse
        </section>
    </div>
@endforeach
