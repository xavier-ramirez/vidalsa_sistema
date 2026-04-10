@if($reportes->count())
<table class="mant-table">
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Frente</th>
            <th>Fallas</th>
            <th>Abiertas</th>
            <th>Estado</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        @foreach($reportes as $rep)
        <tr style="cursor:pointer;" onclick="verReporte({{ $rep->ID_REPORTE }})">
            <td style="font-weight:700;">{{ $rep->FECHA_REPORTE->format('d/m/Y') }}</td>
            <td>{{ $rep->frente->NOMBRE_FRENTE ?? '—' }}</td>
            <td>
                <span style="font-weight:800; color:#1e293b;">{{ $rep->fallas_count }}</span>
            </td>
            <td>
                @if($rep->fallas_abiertas_count > 0)
                    <span class="badge-estado abierta">{{ $rep->fallas_abiertas_count }}</span>
                @else
                    <span style="color:#16a34a; font-weight:700;">0</span>
                @endif
            </td>
            <td>
                <span class="badge-reporte {{ strtolower($rep->ESTADO_REPORTE) }}">
                    {{ $rep->ESTADO_REPORTE }}
                </span>
            </td>
            <td>
                <button class="btn-mant-sm btn-mant-info" onclick="event.stopPropagation(); verReporte({{ $rep->ID_REPORTE }})">
                    <i class="material-icons" style="font-size:14px;">visibility</i> Ver
                </button>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<div class="mant-empty">
    <i class="material-icons">folder_open</i>
    <p>No hay reportes para los filtros seleccionados</p>
</div>
@endif
