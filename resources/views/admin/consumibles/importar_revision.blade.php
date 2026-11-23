@extends('layouts.estructura_base')
@section('title', 'Revisar carga de Excel')

@section('content')
    @include('admin.consumibles.partials.importar_estilos')
    @php
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $fecha = fn ($f) => $f ? \Carbon\Carbon::parse($f)->format('d/m/Y') : '—';
        [$nuevos, $litrosNuevos] = $resumen['totales']['nuevo'];
        [$cargados, $litrosCargados] = $resumen['totales']['cargado'];
        [$repetidos, $litrosRepetidos] = $resumen['totales']['repetido'];
        $porIdentificar = $nuevos - $resumen['identificados'] - $resumen['no_equipo'];
        $sinProyecto = collect($resumen['sin_frente'])->sum('filas');
        $nombreMes = fn ($m) => ucfirst(\Carbon\Carbon::createFromFormat('Y-m', $m)->locale('es')->translatedFormat('F Y'));
        $mostrarEquipos = 40;
    @endphp

    <div class="imp-cabecera">
        <h1 class="page-title" style="margin-bottom:4px;">
            <span class="page-title-line2" style="color:#000;">Revisar carga de Excel</span>
        </h1>
        <a href="{{ route('consumibles.importar') }}" class="btn-primary-maquinaria btn-secondary btn-compacto">
            <i class="material-icons">arrow_back</i> Cargas
        </a>
    </div>

    @if (!empty($imp->ARCHIVOS['errores']))
        <div class="imp-aviso imp-aviso-error">{{ implode(' ', $imp->ARCHIVOS['errores']) }}</div>
    @endif

    {{-- ═══ Cifras ═══ --}}
    <div class="admin-card imp-card">
        <div class="imp-cifras">
            <div class="imp-cifra imp-cifra-ok"><span>Nuevos</span><strong>{{ $n($nuevos) }}</strong><small>{{ $n($litrosNuevos) }} L</small></div>
            <div class="imp-cifra"><span>Ya estaban cargados</span><strong>{{ $n($cargados) }}</strong><small>{{ $n($litrosCargados) }} L</small></div>
            <div class="imp-cifra"><span>Repetidos entre archivos</span><strong>{{ $n($repetidos) }}</strong><small>{{ $n($litrosRepetidos) }} L, se cuentan una vez</small></div>
            <div class="imp-cifra imp-cifra-ok"><span>Equipo identificado</span><strong>{{ $n($resumen['identificados']) }}</strong>
                <small>{{ $nuevos ? round($resumen['identificados'] * 100 / $nuevos) : 0 }} % de los nuevos</small></div>
            <div class="imp-cifra {{ $porIdentificar ? 'imp-cifra-alerta' : '' }}"><span>Por identificar</span><strong>{{ $n($porIdentificar) }}</strong><small>se cargan con el texto del Excel</small></div>
            <div class="imp-cifra {{ $sinProyecto ? 'imp-cifra-alerta' : '' }}"><span>Sin proyecto</span><strong>{{ $n($sinProyecto) }}</strong><small>asígnalos abajo</small></div>
        </div>
    </div>

    {{-- ═══ Archivos y hojas ═══ --}}
    <div class="admin-card imp-card">
        <h3 class="imp-titulo">Archivos leídos</h3>
        <p class="imp-sub">Cada hoja con despachos. Las recargas del tanque, «inicio de mes» y las filas de total no son consumos y no se cargan.</p>
        <div class="imp-tabla-wrap">
            <table class="imp-tabla">
                <thead>
                    <tr>
                        <th>Archivo / hoja</th>
                        <th>Período</th>
                        <th class="num">Despachos</th>
                        <th class="num">Litros</th>
                        <th>Total del Excel</th>
                        <th class="num">Nuevos</th>
                        <th>No son consumos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resumen['hojas'] as $h)
                        @php
                            $t = $h['total'];
                            $cuenta = $resumen['por_hoja'][$h['archivo'] . '|' . $h['hoja']] ?? [];
                            $o = $h['omitidas'];
                        @endphp
                        <tr>
                            <td><strong>{{ $h['archivo'] }}</strong><span class="imp-nota">{{ $h['hoja'] }} · {{ $h['formato'] }}</span></td>
                            <td>{{ $fecha($h['desde']) }} – {{ $fecha($h['hasta']) }}</td>
                            <td class="num">{{ $n($h['filas']) }}</td>
                            <td class="num">{{ $n($h['litros']) }}</td>
                            <td>
                                @if (!$t)
                                    <span class="imp-nota">Sin fila de total</span>
                                @elseif ($t['cuadra'] && abs($t['valor'] - $h['litros']) < 0.5)
                                    <span class="imp-ok">✓ Cuadra</span> {{ $n($t['valor']) }} L
                                @elseif ($t['cuadra'])
                                    <span class="imp-mal">Total parcial</span> {{ $n($t['valor']) }} L
                                    <span class="imp-nota">Su fórmula ({{ $t['formula'] }}) solo suma las filas {{ $t['desde'] }} a {{ $t['hasta'] }}, y esas filas sí dan {{ $n($t['suma_rango']) }} L. La hoja completa suma {{ $n($h['litros']) }} L.</span>
                                @elseif (stripos($t['formula'], 'SUBTOTAL') !== false)
                                    <span class="imp-mal">No cuadra</span> {{ $n($t['valor']) }} L
                                    <span class="imp-nota">Usa SUBTOTAL, que suma solo las filas visibles: el Excel quedó con un filtro puesto. Sus filas suman {{ $n($t['suma_rango']) }} L.</span>
                                @else
                                    <span class="imp-mal">No cuadra</span> {{ $n($t['valor']) }} L
                                    <span class="imp-nota">Las filas de su fórmula ({{ $t['formula'] }}) suman {{ $n($t['suma_rango']) }} L.</span>
                                @endif
                            </td>
                            <td class="num">{{ $n($cuenta['nuevo'] ?? 0) }}
                                @if (($cuenta['cargado'] ?? 0) + ($cuenta['repetido'] ?? 0))
                                    <span class="imp-nota">{{ $n(($cuenta['cargado'] ?? 0) + ($cuenta['repetido'] ?? 0)) }} ya estaban</span>
                                @endif
                            </td>
                            <td class="imp-nota">
                                @if ($o['recarga']) Recargas del tanque: {{ $o['recarga'] }} ({{ $n($o['litros_recarga']) }} L)<br>@endif
                                @if ($o['inicio']) Inicio de mes: {{ $o['inicio'] }}<br>@endif
                                @if ($o['sin_litros']) Sin litros: {{ $o['sin_litros'] }}<br>@endif
                                @if ($o['sin_fecha']) Sin fecha legible: {{ $o['sin_fecha'] }} (filas {{ implode(', ', $h['sin_fecha']) }})<br>@endif
                                @if ($h['corregidas']) Fechas corregidas: {{ $h['corregidas'] }}@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($nuevos)
        {{-- ═══ Meses ═══ --}}
        <div class="admin-card imp-card">
            <h3 class="imp-titulo">Meses a cargar</h3>
            <p class="imp-sub">Los Excel traen varios meses acumulados. Desmarca los que no quieras cargar ahora; lo ya cargado nunca se repite.</p>
            <div class="imp-meses" id="impMeses">
                @foreach ($resumen['meses'] as $mes => [$filas, $litros])
                    <label class="imp-mes">
                        <input type="checkbox" value="{{ $mes }}" data-filas="{{ $filas }}" checked>
                        <span><strong>{{ $nombreMes($mes) }}</strong> <small>{{ $n($filas) }} despachos · {{ $n($litros) }} L</small></span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- ═══ Proyectos ═══ --}}
        <div class="admin-card imp-card">
            <h3 class="imp-titulo">Proyectos</h3>
            <p class="imp-sub">Cada despacho va al proyecto que dice su fila. El campo o la zona que viene entre paréntesis queda guardado como actividad.</p>
            <div class="imp-tabla-wrap">
                <table class="imp-tabla">
                    <thead><tr><th>Proyecto (frente)</th><th class="num">Despachos nuevos</th><th class="num">Litros</th></tr></thead>
                    <tbody>
                        @foreach ($resumen['por_frente'] as $id => [$filas, $litros])
                            <tr><td>{{ $frentes[$id] ?? '—' }}</td><td class="num">{{ $n($filas) }}</td><td class="num">{{ $n($litros) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($resumen['sin_frente'])
                <h3 class="imp-titulo" style="margin-top:20px;">Textos sin proyecto</h3>
                <p class="imp-sub">Elige el frente una vez: queda aprendido para esta carga y las siguientes.</p>
                <div class="imp-tabla-wrap">
                    <table class="imp-tabla">
                        <thead><tr><th>Texto del Excel</th><th>Archivo</th><th class="num">Despachos</th><th class="num">Litros</th><th>Frente</th></tr></thead>
                        <tbody>
                            @foreach ($resumen['sin_frente'] as $g)
                                <tr>
                                    <td class="imp-texto-excel">{{ $g['clave'] !== '' ? $g['clave'] : '(vacío)' }}</td>
                                    <td>{{ $g['archivo'] }}</td>
                                    <td class="num">{{ $n($g['filas']) }}</td>
                                    <td class="num">{{ $n($g['litros']) }}</td>
                                    <td>
                                        @if ($g['clave'] !== '')
                                            <select class="imp-select" data-frente-clave="{{ $g['clave'] }}" onchange="window.consImpMarcarCambio()">
                                                <option value="">— Sin proyecto —</option>
                                                @foreach ($frentes as $idF => $nombreF)
                                                    <option value="{{ $idF }}">{{ $nombreF }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="imp-nota">Sin texto: se carga sin proyecto</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ═══ Equipos por identificar ═══ --}}
        @if ($resumen['sin_equipo'])
            <div class="admin-card imp-card">
                <h3 class="imp-titulo">Equipos por identificar ({{ $n(count($resumen['sin_equipo'])) }})</h3>
                <p class="imp-sub">No se encontró su placa ni su serial en el sistema. Se cargan igual, con el texto del Excel, como «por identificar».
                    Si sabes cuál es, búscalo por placa, serial o modelo; si no es un equipo (lavado, apoyo a otro tanque), márcalo. Queda aprendido.</p>
                <div class="imp-tabla-wrap">
                    <table class="imp-tabla" id="impTablaEquipos">
                        <thead><tr><th>Texto del Excel</th><th class="num">Despachos</th><th class="num">Litros</th><th>Proyecto</th><th>Equipo del sistema</th></tr></thead>
                        <tbody>
                            @foreach ($resumen['sin_equipo'] as $i => $g)
                                <tr data-equipo-clave="{{ $g['clave'] }}" @if ($i >= $mostrarEquipos) hidden data-oculta @endif>
                                    <td>
                                        <span class="imp-texto-excel">{{ $g['clave'] !== '' ? $g['clave'] : '(sin texto)' }}</span>
                                        @if ($g['aviso'])<span class="imp-nota">{{ $g['aviso'] }}</span>@endif
                                    </td>
                                    <td class="num">{{ $n($g['filas']) }}</td>
                                    <td class="num">{{ $n($g['litros']) }}</td>
                                    <td>{{ $g['frente'] ?? '—' }}</td>
                                    <td>
                                        @if ($g['clave'] !== '')
                                            <div class="imp-buscador">
                                                <input type="text" class="imp-input" placeholder="Placa, serial o modelo…" autocomplete="off"
                                                    oninput="window.consImpBuscar(this)" onfocus="window.consImpBuscar(this)">
                                                <div class="imp-sugerencias"></div>
                                                <span class="imp-elegido" hidden></span>
                                            </div>
                                            <label class="imp-no-equipo"><input type="checkbox" onchange="window.consImpNoEquipo(this)"> No es un equipo</label>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($resumen['sin_equipo']) > $mostrarEquipos)
                    <button type="button" class="imp-link imp-mas" onclick="window.consImpVerTodos(this)">Ver los {{ count($resumen['sin_equipo']) }}</button>
                @endif
            </div>
        @endif

        {{-- ═══ Fechas corregidas ═══ --}}
        @if ($resumen['corregidas'])
            <div class="admin-card imp-card">
                <details>
                    <summary class="imp-titulo" style="cursor:pointer;">Fechas corregidas ({{ count($resumen['corregidas']) }})</summary>
                    <p class="imp-sub" style="margin-top:8px;">Año imposible o ilegible, o día y mes invertidos: se corrigieron con las filas vecinas. La corrección queda en las notas del consumo.</p>
                    <div class="imp-tabla-wrap">
                        <table class="imp-tabla">
                            <thead><tr><th>Dónde</th><th>Corrección</th></tr></thead>
                            <tbody>
                                @foreach ($resumen['corregidas'] as $c)
                                    <tr><td>{{ $c['origen'] }}</td><td>{{ $c['aviso'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        @endif
    @endif

    <div class="admin-card imp-card">
        <div class="imp-acciones" style="margin-top:0;">
            <button type="button" class="btn-primary-maquinaria btn-secondary btn-compacto" onclick="window.consImpDescartar(window.CONS_IMP_CFG.descartar, true)">Descartar</button>
            @if ($nuevos)
                <button type="button" class="btn-primary-maquinaria btn-compacto" onclick="window.consImpAplicar()">
                    <i class="material-icons">save</i> Cargar consumos
                </button>
            @else
                <span class="imp-nota">No hay nada nuevo: todo lo de estos archivos ya está cargado.</span>
            @endif
        </div>
    </div>

    <script>
        window.CONS_IMP_CFG = {
            buscar: @json(route('consumibles.importar.buscarEquipo')),
            ensenar: @json(route('consumibles.importar.ensenar', $imp)),
            aplicar: @json(route('consumibles.importar.aplicar', $imp)),
            descartar: @json(route('consumibles.importar.descartar', $imp)),
            cargas: @json(route('consumibles.importar')),
        };
    </script>
    <script src="{{ asset('js/maquinaria/consumibles_importar.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_importar.js')) }}"></script>
    <script>window.consImpArrancar(window.CONS_IMP_CFG);</script>
@endsection
