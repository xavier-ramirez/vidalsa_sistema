{{-- Cargar consumibles → Excel de gasoil (solo super.admin, como sus rutas). Dos entradas al
     MISMO lector (ConsumiblesImportacionController): los archivos de esta zona (se leen al
     elegirlos) y la tabla copiada del Excel con sus encabezados y pegada con Ctrl+V (el paste
     de cargar.blade la manda a consumibles.importar.pegar). Columnas por encabezado, repetidos
     fuera, revisión y deshacer. Debajo, la tabla para escribir a mano o pegar filas sin encabezado. --}}
@include('admin.consumibles.partials.importar_estilos')

<div class="admin-card imp-card">
    <label class="imp-zona" for="impArchivos" id="impZona">
        <i class="material-icons">upload_file</i>
        <span>
            <span class="imp-zona-titulo">Arrastra los Excel de gasoil o haz clic para elegirlos</span>
            <small>Se leen solos, uno o varios, en cualquier formato. También puedes copiar la tabla
                completa del Excel (con sus encabezados) y pegarla con Ctrl+V. Nada se guarda hasta que revises.</small>
        </span>
        <input type="file" id="impArchivos" multiple accept=".xlsx,.xlsm,.xls" hidden>
    </label>
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

<h3 class="imp-titulo" style="margin:4px 0 12px;">O llena la tabla: escribe o pega filas (cualquier consumible)</h3>

<script>
    window.CONS_IMP_CFG = { subir: @json(route('consumibles.importar.subir')), pegar: @json(route('consumibles.importar.pegar')) };
</script>
<script src="{{ asset('js/maquinaria/consumibles_importar.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_importar.js')) }}"></script>
<script>window.consImpArrancar(window.CONS_IMP_CFG);</script>
