{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo tras guardar). Arriba el patio (desplegable);
     del patio elegido, tres bloques: el resumen del mes (el cupo en un cuadro de texto, quedan y la
     barra de lo surtido), la tablita de cisternas nuevas (fecha y litros; «Quedan» se calcula al
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
                    {{-- Un cuadro de texto, sin botones: se guarda al pulsar Enter o al salir del cuadro. --}}
                    @if ($puede)
                        <form class="cupos-cupo" data-accion="cupo">
                            <input type="hidden" name="patio" value="{{ $clave }}">
                            <label class="cupos-cupo-caja">
                                <input type="text" name="litros" inputmode="decimal" placeholder="Escriba el cupo" autocomplete="off"
                                    aria-label="Cupo mensual en litros" value="{{ $p['cupo'] !== null ? $lt($p['cupo']) : '' }}">
                                <span>L</span>
                            </label>
                        </form>
                    @elseif ($p['cupo'] !== null)
                        <b>{{ $lt($p['cupo']) }} L</b>
                    @else
                        <b class="cupos-tenue">Sin cargar</b>
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

        {{-- Cisternas nuevas: una tablita; al llenar los litros de la última fila aparece otra vacía
             debajo, y se guardan todas juntas. novalidate: la fila vacía del final no cuenta ni frena
             el guardado; valida el servidor, fila por fila. --}}
        @if ($puede)
            <form class="cupos-bloque" data-accion="cisterna" novalidate>
                <input type="hidden" name="patio" value="{{ $clave }}">
                <h3>Registrar cisternas</h3>
                <table class="cupos-tbl">
                    <thead><tr><th>Fecha</th><th class="num">Litros</th><th class="num cupos-col-queda">Quedan</th><th title="Foto de la cisterna (opcional)">Foto</th><th></th></tr></thead>
                    <tbody data-filas>
                        <tr data-fila>
                            <td><input type="date" data-campo="fecha" required value="{{ now()->toDateString() }}" aria-label="Fecha"
                                min="{{ \App\Http\Controllers\CuposCombustibleController::desde()->toDateString() }}" max="{{ now()->toDateString() }}"></td>
                            <td><input type="text" data-campo="litros" class="num" inputmode="decimal" placeholder="Litros" autocomplete="off" aria-label="Litros"></td>
                            <td class="num cupos-previo cupos-col-queda" data-previo></td>
                            {{-- Foto opcional de ESTA cisterna (sale con el Guardar). --}}
                            <td class="cupos-acc"><label class="cupos-foto-btn" title="Foto de la cisterna (opcional)"><input type="file" accept="image/*" data-foto-fila hidden><i class="material-icons">photo_camera</i></label></td>
                            <td class="cupos-acc"><button type="button" class="cupos-borrar" data-quitar-fila title="Quitar fila"><i class="material-icons">close</i></button></td>
                        </tr>
                    </tbody>
                </table>
                <div class="cupos-pie">
                    <span class="cupos-lote-total" data-lote-total></span>
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">save</i> Guardar</button>
                </div>
            </form>
        @endif

        {{-- Historial: empieza cerrado («Ver historial» lo abre; queda abierto al repintar) y se
             descarga en Excel. Un bloque por mes con su resumen y sus cisternas. --}}
        <section class="cupos-bloque">
            <div class="cupos-hist-cab">
                <h3>Historial</h3>
                <button type="button" class="cupos-hist-btn" data-ver-historial aria-expanded="false">
                    <i class="material-icons">visibility</i> <span>Ver historial</span>
                </button>
                <button type="button" class="cupos-hist-btn cupos-hist-excel" data-excel-historial="{{ $clave }}"
                    data-nombre="Historial_Cupo_Gasoil_{{ str_replace(' ', '_', $p['nombre']) }}.xlsx"
                    @if (!$p['meses']) disabled title="Todavía no hay cisternas" @endif>
                    <i class="material-icons">download</i> Excel
                </button>
            </div>
            <div class="cupos-hist-lista" data-lista-historial hidden>
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
                            {{-- Foto de la cisterna: si tiene, se ve; si no, se carga (super.admin). --}}
                            @if ($k->FOTO)
                                <button type="button" class="cupos-foto-btn cupos-foto-tiene" title="Ver la foto" data-ver-foto="{{ $k->FOTO }}"
                                    data-texto="{{ $p['nombre'] }} · {{ $k->FECHA->format('d/m/Y') }} · {{ $lt($k->LITROS) }} L"><i class="material-icons">image</i></button>
                            @elseif ($puede)
                                <button type="button" class="cupos-foto-btn" title="Cargar la foto de esta cisterna" data-cargar-foto="{{ $k->ID_CISTERNA }}"><i class="material-icons">photo_camera</i></button>
                            @else
                                <span class="cupos-foto-vacia"></span>
                            @endif
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
            </div>
        </section>
    </div>
@endforeach
