{{-- Filas del kardex (modal "Movimientos"). $movimientos = paginator de MovimientoInventario.
     $tipoMeta viene del modelo (TIPO_META / TIPO_META_DEFAULT) para single source of truth. --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
    // Nombres de las BOLSAS de saldo prestadas que aparecen en esta pagina: cuando una
    // salida no alcanza con lo del proyecto, se toma de otra bolsa
    // (InventarioService::aplicarSalidaConCascada) y el kardex tiene que decir de cuál — si
    // no, el saldo de un proyecto baja sin explicación visible. Una sola consulta por
    // página, con el mismo helper que usa el export de la bitácora.
    $nombreBolsa = \App\Models\MovimientoInventario::nombresDeBolsa($rows);

    // Cuales de estas filas son REALMENTE un prestamo entre bolsas. No se decide fila por
    // fila (ver MovimientoInventario::prestamosPorMovimiento): en un almacen que no separa
    // por proyecto la fila sola diria que TODAS lo son.
    $prestamos = \App\Models\MovimientoInventario::prestamosPorMovimiento($rows);

    // N° de Nota que siguen vigentes entre los que traen las devoluciones de esta pagina
    // (ver $numNota abajo): una nota eliminada ya no tiene PDF que abrir.
    $notasVigentes = \App\Models\MovimientoInventario::notasVigentesDeDevoluciones($rows);
@endphp

@if($rows->count() === 0)
    <tr><td colspan="6" class="mv-vacio">
        <i class="material-icons">receipt_long</i>
        No hay movimientos que coincidan con los filtros.
    </td></tr>
@else
    @foreach($rows as $m)
        @php
            $meta = $tipoMeta[$m->TIPO] ?? \App\Models\MovimientoInventario::TIPO_META_DEFAULT;
            // Entradas, traspasos recibidos y devoluciones suman (TIPOS_ENTRADA del modelo).
            $entra = $m->esEntrada();
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
        <tr class="alm-mov-row" style="--mov-color: {{ $meta[1] }}">
            {{-- Fecha + Tipo COMBINADOS en una sola columna: la fecha arriba y la pill
                 de tipo debajo. En mobile la pill se oculta (.mv-tipo-inline) igual que
                 antes hacía el td.mv-td-tipo — la cantidad ya comunica entrada/salida. --}}
            <td class="mv-td-fecha" data-label="Fecha">
                {{-- Fecha del movimiento (FECHA, solo dia) + HORA real de registro (created_at,
                     que sí guarda la hora — FECHA es tipo date y siempre va en 00:00). --}}
                <div>{{ optional($m->FECHA)->format('d/m/Y') }} <span class="mv-hora">{{ optional($m->created_at)->format('h:i A') }}</span></div>
                {{-- El color lo toma de --mov-color, que la fila publica arriba. --}}
                <span class="mv-tipo-inline">
                    <i class="material-icons">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            {{-- Descripción del producto: "SERIAL: NOMBRE" — el CODIGO va primero (monoespaciado y resaltado)
                 seguido del NOMBRE. La clase col-producto la convierte en ancla del tooltip de usuario.
                 font-size reducido (12.5px vs 14px global del tbody) para que los nombres largos no
                 acaparen visualmente la fila — son la única columna con texto extenso. --}}
            <td class="col-producto mv-td-producto" data-label="Producto">
                @if($m->producto?->CODIGO)
                    {{-- "00042 NOMBRE" como texto continuo. El código usa el MISMO tipo de letra
                         y peso que la descripción (hereda del td: font-weight:400, sin monospace),
                         a pedido del cliente — antes iba en monospace + bold y desentonaba. Peso
                         normal (400) para que la columna use la MISMA letra que el resto de la tabla. --}}
                    <span class="mv-prod-codigo">{{ $m->producto->CODIGO }}</span>
                @endif
                {{ $m->producto?->NOMBRE ?? '—' }}
                @if($m->NUMERO_PARTE)
                    {{-- Nº de parte específico entregado en esta salida (filtros): la equivalencia
                         que se movió realmente, no solo el tipo. --}}
                    <div class="mv-parte">{{ $m->NUMERO_PARTE }}</div>
                @endif
                <div class="tooltip-bubble">
                    👤 {{ $usuarioTip }}
                    {{-- Observación del lote (NOTAS): se muestra aquí, en la burbuja al hacer
                         foco/hover de la fila — igual que el usuario — en vez de inline en la
                         columna Ref (pedido del cliente). Esta burbuja es HOY el único sitio
                         donde la observación llega a verse: la copia inline de la celda Ref
                         (.mv-notas-inline) quedó oculta en los dos tamaños (ver más abajo). --}}
                    @if($m->NOTAS)
                        <div class="mv-tip-notas">📝 {{ $m->NOTAS }}</div>
                    @endif
                    <div class="mv-tip-flecha"></div>
                </div>
            </td>
            {{-- mv-suma / mv-resta deciden el color (verde suma, rojo resta). --}}
            <td class="mv-td-cantidad {{ $entra || ($m->TIPO === 'AJUSTE' && $signo === '+') ? 'mv-suma' : 'mv-resta' }}" data-label="Cantidad">{{ $signo }}{{ $fmt($mag) }} <span class="mv-um">{{ $m->producto?->UM }}</span></td>
            {{-- Stock: solo el saldo RESULTANTE (cómo quedó tras el movimiento). El "antes → después"
                 queda como tooltip de la celda para ver el delta sin saturar la tabla. --}}
            <td class="mv-td-stock" data-label="Stock" title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}">{{ $fmt($m->CANTIDAD_RESULTANTE) }}</td>
            <td class="mv-td-destino" data-label="Destino">
                {{-- Cadena de fallback para el Destino del movimiento:
                     0/1) FRENTE asignado (lo elige el operario en SALIDA / TRASPASO / ENTRADA con
                        frente): SIEMPRE se muestra el nombre del frente — es el dato que el
                        cliente necesita para saber a quién se le entregó cada cosa. Debajo, y
                        solo si el saldo salió de una bolsa distinta a la del destino, va la
                        línea "tomado de X".
                        (Aquí hubo un chip "consumo interno" — se quitó: marcaba TODA salida a
                        un frente que el almacén sirve, y en uno multi-proyecto como Patio El
                        Tigre eso son todas, así que no distinguía nada.)
                     2) Almacén CONTRAPARTE (caso traspasos legacy o sin frente).
                     3) Almacén DEL MOVIMIENTO (caso STOCK INICIAL u otra ENTRADA en un almacén
                        sin frentes asignados — antes salía "—" sin info útil; ahora vemos al
                        menos en qué almacén cayó el stock).
                     4) "—" si por alguna razón nada de lo anterior está. --}}
                @if($m->frente)
                    @php
                        // Prestamo entre bolsas, ya resuelto para toda la pagina arriba.
                        $bolsa = $prestamos[$m->ID_MOVIMIENTO] ?? null;
                    @endphp
                    {{-- A QUIEN se entrego. --}}
                    <div class="mv-destino-frente">{{ $m->frente->NOMBRE_FRENTE }}</div>
                    {{-- DE QUE BOLSA salio (en una devolucion, a cual vuelve), solo cuando NO es la
                         del destino. Se rotula "tomado de" y no "del saldo de": lo que el
                         almacenista necesita leer es a quien se le quito el material, no la
                         mecanica del saldo. La flecha lo ata a la linea de arriba (salio DE aqui
                         PARA aquel). El texto lo decide partials/kardex_bolsa. --}}
                    @if($bolsa !== null)
                        @include('admin.almacen.partials.kardex_bolsa')
                    @endif
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    {{ $m->almacenContraparte?->NOMBRE ?? '—' }}
                @elseif($m->almacen)
                    {{ $m->almacen->NOMBRE }}
                @else
                    —
                @endif
            </td>
            {{-- Ref: apila la trazabilidad del movimiento, cada dato en su columna propia:
                   · N° de Nota (NE-YYYY-NNNN) → link al PDF de la Nota de Entrega. Sale de
                     NUMERO_NOTA en el almacén que emite y de REFERENCIA en el que recibe el
                     traspaso (ver $numNota abajo).
                   · REFERENCIA → Nota de entrega del proveedor (en ENTRADA directa) / N° OC,
                     cuando NO es el N° de Nota que ya salió como enlace. En el STOCK INICIAL
                     (esStockInicial) sale en negrita + "Nuevo material" debajo.
                   · MOTIVO     → en ENTRADA es el PROVEEDOR (ícono 🚚, a quién devolver);
                                  en SALIDA/AJUSTE es el motivo → se deja como tooltip de la celda.
                   · NOTAS      → Observaciones del lote: inline truncado + texto completo al hover.
                 Si no hay nada → "—". --}}
            @php
                $esEntradaDirecta = $m->TIPO === 'ENTRADA';

                // N° de Nota que ESTA fila enlaza al PDF. El almacén que EMITE lo trae en
                // NUMERO_NOTA; el que RECIBE el traspaso NO — su TRASPASO_ENTRADA deja el N°
                // en REFERENCIA y NUMERO_NOTA en NULL. Sin esto la misma nota se veía idéntica
                // en los dos almacenes pero solo abría el PDF desde el emisor: en el destino
                // era texto muerto. El documento es uno solo y sale siempre en el formato del
                // emisor (ver renderNotaEntregaPdfBinary), así que ambos lados abren lo mismo.
                //
                // Se exige que el almacén emisor (la contraparte del traspaso) sea VISIBLE para
                // el usuario: notaEntregaPdf valida con assertPuedeVerAlmacen, y un enlace que
                // responde 403 es peor que dejar el número como texto.
                $numNota = $m->NUMERO_NOTA;
                if (!$numNota
                    && $m->ID_ALMACEN_CONTRAPARTE
                    && preg_match('/^NE-\d{4}-\d+$/', (string) $m->REFERENCIA)
                    && ($almacenesVisibles ?? collect())->contains((int) $m->ID_ALMACEN_CONTRAPARTE)) {
                    $numNota = $m->REFERENCIA;
                }
                // Una DEVOLUCION trae en REFERENCIA la nota de la que vuelve el material, que es
                // del mismo almacén: se enlaza a su PDF mientras esa nota exista.
                if (!$numNota
                    && $m->TIPO === \App\Models\MovimientoInventario::TIPO_DEVOLUCION
                    && isset($notasVigentes[$m->REFERENCIA])) {
                    $numNota = $m->REFERENCIA;
                }
            @endphp
            <td class="mv-td-ref" data-label="Ref" @if(!$esEntradaDirecta && $m->MOTIVO) title="{{ $m->MOTIVO }}" @endif>
                @if($numNota)
                    {{-- Mismo visor in-page que usa /admin/almacen/notas y el resto del módulo
                         (#pdfPreviewModal vía window.openPdfPreview). Conserva fallback a abrir
                         en pestaña nueva si el layout no provee la función. --}}
                    {{-- El icono `description` (mismo del menú Acciones → "Bitácora por Nota (PDF)")
                         marca que el enlace abre un DOCUMENTO. Acompaña al número en escritorio;
                         en la tarjeta móvil el CSS oculta el número y deja solo el icono, que es
                         ahí el único punto que abre el PDF.
                         El título del visor sale de data-pdf-title, NO de this.textContent: con el
                         <i> dentro, textContent valdría "descriptionNE-2026-0041". --}}
                    <a href="{{ route('almacen.nota-entrega', ['numero' => $numNota]) }}"
                       class="mv-nota-link"
                       data-pdf-url="{{ route('almacen.nota-entrega', ['numero' => $numNota]) }}"
                       data-pdf-title="Nota {{ $numNota }}"
                       onclick="if (typeof window.openPdfPreview === 'function') { event.preventDefault(); window.openPdfPreview(this.dataset.pdfUrl, 'nota_entrega', this.dataset.pdfTitle, 0, '', true, 'almacen'); }"
                       target="_blank" rel="noopener"
                       title="Ver Nota de Entrega (PDF)"><i class="material-icons mv-nota-ico">description</i><span class="mv-nota-num">{{ $numNota }}</span></a>
                @endif
                @if($m->esStockInicial())
                    {{-- Entrada que se registra al crear el producto con cantidad inicial. --}}
                    <div class="mv-ref-referencia mv-ref-stock-inicial">{{ $m->REFERENCIA }}</div>
                    <div class="mv-ref-sub">Nuevo material</div>
                @elseif($m->REFERENCIA && $m->REFERENCIA !== $numNota)
                    {{-- Nota de entrega del proveedor (ENTRADA) / N° OC: peso normal (400), no
                         negrita, a pedido del cliente para que la columna Referencia use la misma
                         letra que las demás. Se OMITE si es el N° que ya salió como enlace arriba
                         (en traspasos ambos traían el mismo NE → salía duplicado). --}}
                    <div class="mv-ref-referencia {{ $numNota ? 'mv-ref-apilado' : '' }}" title="Nota de entrega / referencia">{{ $m->REFERENCIA }}</div>
                @endif
                @if($esEntradaDirecta && $m->MOTIVO)
                    {{-- Proveedor: visible (no solo hover) — es el dato clave para una devolución. --}}
                    <div class="mv-ref-proveedor {{ ($m->NUMERO_NOTA || $m->REFERENCIA) ? 'mv-ref-apilado' : '' }}" title="Proveedor">
                        <span>{{ $m->MOTIVO }}</span>
                    </div>
                @endif
                @if($m->NOTAS)
                    {{-- Observación inline. HOY NO SE VE EN NINGÚN TAMAÑO: .mv-notas-inline está
                         en display:none en escritorio (donde la observación vive en la burbuja de
                         hover) y el @media de la tarjeta móvil la vuelve a ocultar con !important
                         junto al resto de la celda Ref, desde que la tarjeta dejó solo el botón
                         del PDF. Se conserva el marcado tal cual estaba; si se confirma que no
                         hace falta, el bloque entero se puede borrar. --}}
                    <div class="mv-notas-inline" title="{{ $m->NOTAS }}">
                        <i class="material-icons">sticky_note_2</i><span class="mv-notas-texto">{{ $m->NOTAS }}</span>
                    </div>
                @endif
                {{-- Envuelto en un span para poder ocultarlo en la tarjeta móvil: ahí la celda
                     solo muestra el icono del PDF, y un "—" suelto quedaría flotando. --}}
                @if(!$m->NUMERO_NOTA && !$m->REFERENCIA && !($esEntradaDirecta && $m->MOTIVO) && !$m->NOTAS)<span class="mv-ref-empty">—</span>@endif
                @can('super.admin')
                    {{-- Botón "eliminar SOLO del historial" CASI INVISIBLE — SOLO super.admin
                         (gateado también en la ruta DELETE almacen.movimientos.destroyHistorial).
                         Borra la fila del kardex SIN tocar el stock: NO revierte ni recalcula el
                         saldo. Va a la IZQUIERDA del deshacer y en tono ámbar para distinguirlo.
                         Irreversible: la confirmación vive en JS. --}}
                    <button type="button" class="alm-mov-purge"
                            data-purge-url="{{ route('almacen.movimientos.destroyHistorial', ['id' => $m->ID_MOVIMIENTO]) }}"
                            title="Eliminar del historial sin tocar el stock (irreversible)"
                            aria-label="Eliminar del historial"
                            onclick="event.stopPropagation(); window.almEliminarSoloHistorial(this);">
                        <i class="material-icons">playlist_remove</i>
                    </button>
                    {{-- Botón "deshacer" CASI INVISIBLE — SOLO super.admin (gateado también en
                         la ruta DELETE almacen.movimientos.destroy, no basta ocultarlo). Borra el
                         movimiento del kardex SIN rastro, revierte el stock y recalcula el saldo
                         de los movimientos posteriores. Irreversible: la confirmación vive en JS y
                         su texto lo da el servidor (data-impacto-url: resto de la nota, envío...).
                         Las URL ya vienen resueltas por fila para no construirlas en JS. --}}
                    <button type="button" class="alm-mov-undo"
                            data-undo-url="{{ route('almacen.movimientos.destroy', ['id' => $m->ID_MOVIMIENTO]) }}"
                            data-impacto-url="{{ route('almacen.movimientos.impactoDeshacer', ['id' => $m->ID_MOVIMIENTO]) }}"
                            title="Deshacer este movimiento (irreversible)"
                            aria-label="Deshacer movimiento"
                            onclick="event.stopPropagation(); window.almDeshacerMovimiento(this);">
                        <i class="material-icons">undo</i>
                    </button>
                @endcan
            </td>
        </tr>
    @endforeach
@endif
