@forelse($notas as $n)
    @php
        $tipoNum = $n->TIPO;
        $alm     = isset($almById) && isset($n->ID_ALMACEN) ? ($almById[$n->ID_ALMACEN] ?? null) : null;
        $fre     = isset($freById) && isset($n->ID_FRENTE) ? ($freById[$n->ID_FRENTE] ?? null) : null;
        $contra  = isset($almById) && isset($n->ID_ALMACEN_CONTRAPARTE) ? ($almById[$n->ID_ALMACEN_CONTRAPARTE] ?? null) : null;
        $pdfUrl  = route('almacen.nota-entrega', ['numero' => $n->NUMERO_NOTA]);
    @endphp
    <tr>
        <td style="white-space:nowrap;">
            <div>{{ \Illuminate\Support\Carbon::parse($n->FECHA)->format('d/m/Y') }}</div>
            <div style="margin-top:3px;">
                @if(in_array($tipoNum, ['SALIDA', 'TRASPASO_SALIDA']))
                    <span style="font-size:12px;font-weight:700;color:#dc2626;">Salida</span>
                @elseif(in_array($tipoNum, ['ENTRADA', 'TRASPASO_ENTRADA']))
                    <span style="font-size:12px;font-weight:700;color:#16a34a;">Entrada</span>
                @else
                    <span style="font-size:12px;font-weight:700;color:#475569;">Auditoría</span>
                @endif
            </div>
        </td>
        <td>
            <span style="font-weight:700;color:#334155;font-size:13px;">{{ $n->NUMERO_NOTA }}</span>
        </td>
        <td>
            {{ $alm?->NOMBRE ?? '—' }}
            @if($alm)
                <div style="font-size:11px;color:#94a3b8;font-weight:500;">{{ $alm->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }}</div>
            @endif
        </td>
        <td>
            @if($contra)
                {{ $contra->NOMBRE }}
                @if($fre)
                    <div style="font-size:11px;color:#94a3b8;font-weight:500;">{{ $fre->NOMBRE_FRENTE }}</div>
                @endif
            @elseif($fre)
                {{ $fre->NOMBRE_FRENTE }}
            @else
                <span style="color:#94a3b8;">—</span>
            @endif
        </td>
        <td>
            <button type="button" class="anf-pdf-btn"
               onclick="window.openPdfPreview('{{ $pdfUrl }}', 'nota_entrega', 'Nota {{ $n->NUMERO_NOTA }}', 0, '', true, 'almacen');"
               title="Ver Nota {{ $n->NUMERO_NOTA }} (PDF)">
                <i class="material-icons">description</i>
            </button>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="anf-empty">
            <i class="material-icons">description</i>
            No hay Notas de Entrega que coincidan con los filtros.
            <div style="font-size:12px;color:#94a3b8;margin-top:6px;">
                Las notas se generan automáticamente al registrar una Salida o un Traspaso desde <a href="{{ route('almacen.index') }}" style="color:#0067b1;">Almacén</a>.
            </div>
        </td>
    </tr>
@endforelse
