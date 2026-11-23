@extends('layouts.estructura_base')
@section('title', 'Cargar desde Excel')

@section('content')
    @include('admin.consumibles.partials.importar_estilos')

    <div class="imp-cabecera">
        <h1 class="page-title" style="margin-bottom:4px;">
            <span class="page-title-line2" style="color:#000;">Cargar desde Excel</span>
        </h1>
        <a href="{{ route('consumibles.index') }}" class="btn-primary-maquinaria btn-secondary btn-compacto">
            <i class="material-icons">arrow_back</i> Consumibles
        </a>
    </div>

    @if (session('success'))
        <div class="imp-aviso imp-aviso-ok">{{ session('success') }}</div>
    @endif

    <div class="admin-card imp-card">
        <label class="imp-zona" for="impArchivos" id="impZona">
            <i class="material-icons">upload_file</i>
            <span class="imp-zona-titulo">Arrastra aquí los Excel de gasoil o haz clic para elegirlos</span>
            <small>Puedes subir varios a la vez y en cualquiera de los formatos (control de combustible,
                Gerencia Ambiente, Ventas Patio). Nada se guarda hasta que revises y confirmes.</small>
            <input type="file" id="impArchivos" multiple accept=".xlsx,.xlsm,.xls" hidden>
        </label>
        <ul class="imp-lista-archivos" id="impLista"></ul>
        <div class="imp-acciones">
            <a href="{{ route('consumibles.index') }}" class="btn-primary-maquinaria btn-secondary btn-compacto">Cancelar</a>
            <button type="button" class="btn-primary-maquinaria btn-compacto" id="impLeer" disabled onclick="window.consImpLeer()">
                <i class="material-icons">fact_check</i> Leer archivos
            </button>
        </div>
    </div>

    <div class="admin-card imp-card">
        <h3 class="imp-titulo">Cargas anteriores</h3>
        @if ($cargas->isEmpty())
            <p class="imp-vacio">Todavía no hay cargas desde Excel.</p>
        @else
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
        @endif
    </div>

    <script>
        window.CONS_IMP_CFG = { subir: @json(route('consumibles.importar.subir')) };
    </script>
    <script src="{{ asset('js/maquinaria/consumibles_importar.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_importar.js')) }}"></script>
    <script>window.consImpArrancar(window.CONS_IMP_CFG);</script>
@endsection
