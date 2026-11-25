{{-- Una línea por patio en la tarjeta de Cupos de Gasoil (Cargar consumibles): lo disponible del mes. --}}
@php $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.'); @endphp
@foreach ($patios as $clave => $p)
    <span class="cupos-res-patio">
        {{ $p['nombre'] }}:
        @if ($p['cupo'] === null)
            <b>sin cupo</b>
        @else
            <b style="color: {{ $p['disponible'] < 0 ? '#dc2626' : '#0f172a' }};">{{ $lt($p['disponible']) }} L</b> disponibles de {{ $lt($p['cupo']) }}
        @endif
    </span>
@endforeach
