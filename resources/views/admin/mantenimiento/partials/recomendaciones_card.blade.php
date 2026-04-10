{{-- Rendered server-side when needed; also used as reference for JS rendering --}}
@if(isset($recomendaciones) && count($recomendaciones) > 0)
<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 14px; margin-top:8px;">
    <div style="font-size:11px; font-weight:700; color:#2563eb; margin-bottom:8px; display:flex; align-items:center; gap:4px;">
        <i class="material-icons" style="font-size:14px;">auto_awesome</i>
        Recomendaciones para {{ $equipo->MARCA ?? '' }} {{ $equipo->MODELO ?? '' }}
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:6px;">
        @foreach($recomendaciones as $rec)
            <span style="display:inline-flex; align-items:center; gap:4px; padding:5px 10px; background:white; border:1px solid #93c5fd; border-radius:8px; font-size:11px; font-weight:600; color:#1e40af;">
                <i class="material-icons" style="font-size:12px;">label</i>
                {{ $rec['DESCRIPCION_MATERIAL'] }}
            </span>
        @endforeach
    </div>
</div>
@endif
