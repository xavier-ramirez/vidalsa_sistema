{{-- Filas del kardex (modal "Movimientos"). $movimientos = paginator de MovimientoInventario.
     $tipoMeta viene del modelo (TIPO_META / TIPO_META_DEFAULT) para single source of truth. --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
@endphp

@if($rows->count() === 0)
    <tr><td colspan="6" style="text-align:center;padding:36px 16px;color:#94a3b8;font-size:14px;">
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
            {{-- Fecha + Tipo COMBINADOS en una sola columna: la fecha arriba y la pill
                 de tipo debajo. En mobile la pill se oculta (.mv-tipo-inline) igual que
                 antes hacía el td.mv-td-tipo — la cantidad ya comunica entrada/salida. --}}
            <td class="mv-td-fecha" data-label="Fecha" style="white-space:nowrap;line-height:1.6;">
                <div>{{ optional($m->FECHA)->format('d/m/Y') }}</div>
                <span class="mv-tipo-inline" style="display:inline-flex;align-items:center;gap:4px;background:{{ $meta[2] }};color:{{ $meta[1] }};font-weight:700;font-size:11px;padding:2px 8px;border-radius:999px;margin-top:3px;">
                    <i class="material-icons" style="font-size:13px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            {{-- Descripción del producto: "SERIAL: NOMBRE" — el CODIGO va primero (monoespaciado y resaltado)
                 seguido del NOMBRE. La clase col-producto la convierte en ancla del tooltip de usuario.
                 font-size reducido (12.5px vs 14px global del tbody) para que los nombres largos no
                 acaparen visualmente la fila — son la única columna con texto extenso. --}}
            <td class="col-producto mv-td-producto" data-label="Producto" style="font-weight:600;font-size:12.5px;">
                @if($m->producto?->CODIGO)
                    {{-- Sin ":" al final: queremos "00042 NOMBRE" como un texto continuo
                         (mismo patron unificado que /admin/almacen mobile). El monospace
                         del codigo se mantiene SOLO en desktop — en mobile la regla CSS
                         lo fuerza a heredar el font del padre. --}}
                    <span style="font-family:monospace;font-weight:800;color:#0f172a;">{{ $m->producto->CODIGO }}</span>
                @endif
                {{ $m->producto?->NOMBRE ?? '—' }}
                <div class="tooltip-bubble" style="pointer-events:none;opacity:0;visibility:hidden;position:absolute;bottom:100%;left:0;transform:translateY(5px);background:#1e293b;color:#fff;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:500;white-space:normal;width:max-content;max-width:240px;word-wrap:break-word;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transition:all 0.2s ease-in-out;z-index:50;margin-bottom:5px;">
                    👤 {{ $usuarioTip }}
                    <div style="position:absolute;top:100%;left:30px;margin-left:-4px;border-width:4px;border-style:solid;border-color:#1e293b transparent transparent transparent;"></div>
                </div>
            </td>
            <td class="mv-td-cantidad" data-label="Cantidad" style="font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;">{{ $signo }}{{ $fmt($mag) }} <span style="color:#64748b;font-weight:600;">{{ $m->producto?->UM }}</span></td>
            {{-- Stock: solo el saldo RESULTANTE (cómo quedó tras el movimiento). El "antes → después"
                 queda como tooltip de la celda para ver el delta sin saturar la tabla. --}}
            <td class="mv-td-stock" data-label="Stock" title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}" style="font-weight:700;white-space:nowrap;">{{ $fmt($m->CANTIDAD_RESULTANTE) }}</td>
            <td class="mv-td-destino" data-label="Destino">
                {{-- Cadena de fallback para el Destino del movimiento:
                     1) FRENTE asignado (lo elige el operario en SALIDA / TRASPASO / ENTRADA con frente).
                     2) Almacén CONTRAPARTE (caso traspasos legacy o sin frente).
                     3) Almacén DEL MOVIMIENTO (caso STOCK INICIAL u otra ENTRADA en un almacén
                        sin frentes asignados — antes salía "—" sin info útil; ahora vemos al
                        menos en qué almacén cayó el stock).
                     4) "—" si por alguna razón nada de lo anterior está. --}}
                @if($m->frente)
                    {{ $m->frente->NOMBRE_FRENTE }}
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    {{ $m->almacenContraparte?->NOMBRE ?? '—' }}
                @elseif($m->almacen)
                    {{ $m->almacen->NOMBRE }}
                @else
                    —
                @endif
            </td>
            {{-- Ref: apila la trazabilidad del movimiento, cada dato en su columna propia:
                   · NUMERO_NOTA (NE-YYYY-NNNN) → link al PDF de la Nota de Entrega (SALIDA).
                   · REFERENCIA → Nota de entrega del proveedor (en ENTRADA directa) / N° OC.
                   · MOTIVO     → en ENTRADA es el PROVEEDOR (ícono 🚚, a quién devolver);
                                  en SALIDA/AJUSTE es el motivo → se deja como tooltip de la celda.
                   · NOTAS      → Observaciones del lote: inline truncado + texto completo al hover.
                 Si no hay nada → "—". --}}
            @php $esEntradaDirecta = $m->TIPO === 'ENTRADA'; @endphp
            <td class="mv-td-ref" data-label="Ref" @if(!$esEntradaDirecta && $m->MOTIVO) title="{{ $m->MOTIVO }}" @endif>
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
                    <div style="font-size:10.5px;color:#64748b;{{ $m->NUMERO_NOTA ? 'margin-top:2px;' : '' }}" title="Nota de entrega / referencia">{{ $m->REFERENCIA }}</div>
                @endif
                @if($esEntradaDirecta && $m->MOTIVO)
                    {{-- Proveedor: visible (no solo hover) — es el dato clave para una devolución. --}}
                    <div style="font-size:10.5px;color:#64748b;display:flex;align-items:center;gap:3px;{{ ($m->NUMERO_NOTA || $m->REFERENCIA) ? 'margin-top:2px;' : '' }}" title="Proveedor">
                        <i class="material-icons" style="font-size:12px;color:#94a3b8;">local_shipping</i><span>{{ $m->MOTIVO }}</span>
                    </div>
                @endif
                @if($m->NOTAS)
                    <div style="font-size:10.5px;color:#94a3b8;display:flex;align-items:center;gap:3px;margin-top:2px;" title="{{ $m->NOTAS }}">
                        <i class="material-icons" style="font-size:12px;">sticky_note_2</i><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px;">{{ $m->NOTAS }}</span>
                    </div>
                @endif
                @if(!$m->NUMERO_NOTA && !$m->REFERENCIA && !($esEntradaDirecta && $m->MOTIVO) && !$m->NOTAS)—@endif
                @can('super.admin')
                    {{-- Botón "deshacer" CASI INVISIBLE — SOLO super.admin (gateado también en
                         la ruta DELETE almacen.movimientos.destroy, no basta ocultarlo). Borra el
                         movimiento del kardex SIN rastro, revierte el stock y recalcula el saldo
                         de los movimientos posteriores. Irreversible: la confirmación vive en JS.
                         La URL ya viene resuelta por fila (data-undo-url) para no construirla en JS. --}}
                    <button type="button" class="alm-mov-undo"
                            data-undo-url="{{ route('almacen.movimientos.destroy', ['id' => $m->ID_MOVIMIENTO]) }}"
                            title="Deshacer este movimiento (irreversible)"
                            aria-label="Deshacer movimiento"
                            onclick="event.stopPropagation(); window.almDeshacerMovimiento(this);">
                        <i class="material-icons" style="font-size:14px;">undo</i>
                    </button>
                @endcan
            </td>
        </tr>
    @endforeach
@endif
