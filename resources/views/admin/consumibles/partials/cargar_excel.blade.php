{{-- Cargar consumibles → «Carga Masiva desde Excel» (solo super.admin, como sus rutas), arriba de
     la tabla y con el aspecto de la de Registrar equipos (admin.partials.bulk_upload_card). Dos
     entradas al MISMO lector (ConsumiblesImportacionController): los Excel de gasoil elegidos con
     «Cargar Excel» o soltados en la tarjeta (se leen al elegirlos), y el reporte de gasoil completo
     copiado con sus encabezados y pegado con Ctrl+V (el paste de cargar.blade lo manda a
     consumibles.importar.pegar; lo demás va a la tabla). Columnas por encabezado, repetidos fuera,
     revisión y deshacer. --}}
@include('admin.consumibles.partials.importar_estilos')

<div id="impZona" class="admin-card imp-carga">
    <div>
        <h3 class="imp-carga-titulo"><i class="material-icons">upload_file</i> Carga Masiva desde Excel</h3>
        <p class="imp-carga-sub">Sube los Excel de gasoil (uno o varios, en cualquier formato) o suéltalos aquí; también puedes pegar el reporte completo con Ctrl+V. Nada se guarda hasta que revises.</p>
    </div>
    <label for="impArchivos" class="btn-primary-maquinaria imp-carga-btn">
        <i class="material-icons">upload</i> Cargar Excel
    </label>
    <input type="file" id="impArchivos" multiple accept=".xlsx,.xlsm,.xls" hidden>
</div>

{{-- Solo si hay alguna: desde aquí se retoma una lectura sin confirmar o se deshace una carga. --}}
@if ($cargas->isNotEmpty())
<div class="admin-card imp-card">
    <h3 class="imp-titulo">Cargas de Excel</h3>
    <div class="imp-tabla-wrap">
        <table class="imp-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Archivos</th>
                    <th class="num">Consumos</th>
                    <th class="num">Litros</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cargas as $c)
                    @php $nombres = collect($c->ARCHIVOS['hojas'] ?? [])->pluck('archivo')->unique(); @endphp
                    <tr>
                        <td>{{ $c->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $c->usuario?->NOMBRE_COMPLETO ?? '—' }}</td>
                        <td class="imp-archivos">{{ $nombres->implode(', ') }}</td>
                        <td class="num">{{ $c->ESTADO === 'BORRADOR' ? '—' : number_format($c->INSERTADOS, 0, ',', '.') }}</td>
                        <td class="num">{{ $c->ESTADO === 'BORRADOR' ? '—' : number_format((float) $c->LITROS, 0, ',', '.') }}</td>
                        <td>
                            <span class="imp-estado imp-estado-{{ strtolower($c->ESTADO) }}">
                                {{ ['BORRADOR' => 'Sin confirmar', 'APLICADA' => 'Cargada', 'DESHECHA' => 'Deshecha'][$c->ESTADO] ?? $c->ESTADO }}
                            </span>
                        </td>
                        <td class="imp-acciones-fila">
                            @if ($c->ESTADO === 'BORRADOR')
                                <a href="{{ route('consumibles.importar.revisar', $c) }}" class="imp-link">Revisar</a>
                                <button type="button" class="imp-link imp-link-rojo"
                                    onclick="window.consImpDescartar('{{ route('consumibles.importar.descartar', $c) }}')">Descartar</button>
                            @elseif ($c->ESTADO === 'APLICADA')
                                <button type="button" class="imp-link imp-link-rojo"
                                    onclick="window.consImpDeshacer('{{ route('consumibles.importar.deshacer', $c) }}', {{ (int) $c->INSERTADOS }})">Deshacer</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<script>
    window.CONS_IMP_CFG = { subir: @json(route('consumibles.importar.subir')), pegar: @json(route('consumibles.importar.pegar')), columnas: @json(route('consumibles.importar.columnas')) };
</script>
<script src="{{ asset('js/maquinaria/consumibles_importar.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_importar.js')) }}"></script>
<script>window.consImpArrancar(window.CONS_IMP_CFG);</script>
