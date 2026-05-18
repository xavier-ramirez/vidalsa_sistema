{{-- Filas del kardex (modal "Movimientos"). $movimientos = paginator de MovimientoInventario.
     $tipoMeta viene del modelo (TIPO_META / TIPO_META_DEFAULT) para single source of truth. --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
@endphp

@if($rows->count() === 0)
    <tr><td colspan="7" style="text-align:center;padding:36px 16px;color:#94a3b8;font-size:14px;">
        <i class="material-icons" style="font-size:40px;color:#cbd5e0;display:block;margin:0 auto 8px;">receipt_long</i>
        No hay movimientos que coincidan con los filtros.
    </td></tr>
@else
    @foreach($rows as $m)
        @php
            $meta = $tipoMeta[$m->TIPO] ?? \App\Models\MovimientoInventario::TIPO_META_DEFAULT;
            $entra = in_array($m->TIPO, ['ENTRADA', 'TRASPASO_ENTRADA'], true);
            $signo = $m->TIPO === 'AJUSTE'
                ? (((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) >= 0 ? '+' : '−')
                : ($entra ? '+' : '−');
            $mag = $m->TIPO === 'AJUSTE' ? abs((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) : (float) $m->CANTIDAD;
            // El usuario que registró el movimiento NO tiene columna propia: aparece como burbuja
            // (.tooltip-bubble — misma clase que el patrón global de /admin/equipos) anclada a la
            // celda Producto, que se muestra al pasar el mouse por CUALQUIER PARTE de la fila.
            $usuarioTip = $m->usuario?->NOMBRE_COMPLETO
                ? 'Registrado por: ' . $m->usuario->NOMBRE_COMPLETO
                : 'Usuario no registrado';
        @endphp
        <tr class="alm-mov-row">
            <td style="white-space:nowrap;">{{ optional($m->FECHA)->format('d/m/Y') }}</td>
            <td style="white-space:nowrap;">
                {{-- La pill mantiene su color de fondo y texto propios (visualmente distingue ENTRADA / SALIDA / etc.). --}}
                <span style="display:inline-flex;align-items:center;gap:4px;background:{{ $meta[2] }};color:{{ $meta[1] }};font-weight:700;font-size:11px;padding:2px 8px;border-radius:999px;">
                    <i class="material-icons" style="font-size:13px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            {{-- Descripción del producto: "SERIAL: NOMBRE" — el CODIGO va primero (monoespaciado y resaltado)
                 seguido del NOMBRE. La clase col-producto la convierte en ancla del tooltip de usuario.
                 font-size reducido (12.5px vs 14px global del tbody) para que los nombres largos no
                 acaparen visualmente la fila — son la única columna con texto extenso. --}}
            <td class="col-producto" style="font-weight:600;font-size:12.5px;">
                @if($m->producto?->CODIGO)
                    <span style="font-family:monospace;font-weight:800;color:#0f172a;">{{ $m->producto->CODIGO }}:</span>
                @endif
                {{ $m->producto?->NOMBRE ?? '—' }}
                <div class="tooltip-bubble" style="pointer-events:none;opacity:0;visibility:hidden;position:absolute;bottom:100%;left:0;transform:translateY(5px);background:#1e293b;color:#fff;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:500;white-space:normal;width:max-content;max-width:240px;word-wrap:break-word;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transition:all 0.2s ease-in-out;z-index:50;margin-bottom:5px;">
                    👤 {{ $usuarioTip }}
                    <div style="position:absolute;top:100%;left:30px;margin-left:-4px;border-width:4px;border-style:solid;border-color:#1e293b transparent transparent transparent;"></div>
                </div>
            </td>
            <td style="font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;">{{ $signo }}{{ $fmt($mag) }} <span style="color:#64748b;font-weight:600;">{{ $m->producto?->UM }}</span></td>
            {{-- Stock: solo el saldo RESULTANTE (cómo quedó tras el movimiento). El "antes → después"
                 queda como tooltip de la celda para ver el delta sin saturar la tabla. --}}
            <td title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}" style="font-weight:700;white-space:nowrap;">{{ $fmt($m->CANTIDAD_RESULTANTE) }}</td>
            <td>
                {{-- Mostrar el FRENTE primero (es lo que el operario eligió como destino real);
                     si no hay frente (traspasos legacy o movimientos sin frente), caer al almacén contraparte. --}}
                @if($m->frente)
                    {{ $m->frente->NOMBRE_FRENTE }}
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    {{ $m->almacenContraparte?->NOMBRE ?? '—' }}
                @else
                    —
                @endif
            </td>
            {{-- Ref: N° de Nota (NE-YYYY-NNNN) como link al PDF + REFERENCIA debajo (más chica).
                 NUMERO_NOTA lo asigna el backend cuando es SALIDA / TRASPASO_SALIDA generadas via
                 Nota de Entrega VID-FO-GEN-019; REFERENCIA viene del modal de Entrada directa
                 (N° de OC). Si ambos están presentes se muestran apilados; si ninguno → "—". --}}
            <td title="{{ $m->MOTIVO ?? '' }}">
                @if($m->NUMERO_NOTA)
                    {{-- Mismo visor in-page que usa /admin/almacen/notas y el resto del módulo
                         (#pdfPreviewModal vía window.openPdfPreview). Conserva fallback a abrir
                         en pestaña nueva si el layout no provee la función. --}}
                    <a href="{{ route('almacen.nota-entrega', ['numero' => $m->NUMERO_NOTA]) }}"
                       onclick="if (typeof window.openPdfPreview === 'function') { event.preventDefault(); window.openPdfPreview(this.href, 'nota_entrega', 'Nota ' + this.textContent.trim(), 0, '', true, 'almacen'); }"
                       target="_blank" rel="noopener"
                       style="color:#334155;text-decoration:none;font-weight:700;font-family:monospace;font-size:12px;"
                       title="Ver Nota de Entrega (PDF)">{{ $m->NUMERO_NOTA }}</a>
                @endif
                @if($m->REFERENCIA)
                    <div style="font-size:10.5px;color:#64748b;{{ $m->NUMERO_NOTA ? 'margin-top:2px;' : '' }}">{{ $m->REFERENCIA }}</div>
                @endif
                @if(!$m->NUMERO_NOTA && !$m->REFERENCIA)—@endif
            </td>
        </tr>
    @endforeach
@endif
