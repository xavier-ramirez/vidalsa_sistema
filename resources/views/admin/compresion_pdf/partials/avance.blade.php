{{-- Las filas de "Por dónde va la revisión". Es la consulta más cara de la pestaña
     (VerificacionDocumento::avances, ~70 ms), así que la pantalla sale SIN ella ($avanceDocs null:
     los cuatro nombres con la barra vacía) y el panel la pide justo después
     (HistorialDocumentosController::index con ?avance=1), que la devuelve con los números. --}}
@php
    $cpdfFilasAvance = $avanceDocs ?? collect(\App\Models\VerificacionDocumento::ENLACES)
        ->map(fn ($c, $t) => ['nombre' => \App\Models\VerificacionDocumento::NOMBRES[$t] ?? $t, 'total' => null])->all();
@endphp
<div data-cpdf-avance @if ($avanceDocs === null) data-cpdf-avance-pendiente @endif>
    @foreach ($cpdfFilasAvance as $tipo => $a)
        <a class="cpdf-avance-fila" href="{{ route('historial-documentos.index', ['pestana' => 'documentos', 'tipo_doc' => $tipo]) }}"
           onclick="event.preventDefault(); window.cpdfPoner({ tipo_doc: @js($tipo), estado_doc: null })">
            <span class="cpdf-avance-nombre">{{ $a['nombre'] }}</span>
            @if ($a['total'] === null)
                <span class="cpdf-avance-barra"><i style="width: 0%;"></i></span>
                <span class="cpdf-avance-cifra">…</span>
            @else
                <span class="cpdf-avance-barra"><i style="width: {{ $a['total'] ? round($a['leidos'] * 100 / $a['total']) : 100 }}%;"></i></span>
                <span class="cpdf-avance-cifra">
                    {{ number_format($a['leidos'], 0, ',', '.') }}/{{ number_format($a['total'], 0, ',', '.') }}
                    @if (!$a['faltan'] && $a['total']) <b class="cpdf-avance-listo">listo</b> @endif
                </span>
            @endif
        </a>
    @endforeach
</div>
