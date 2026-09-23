{{-- Filas de la tabla de inventario. $productos = Collection|null (lote del scroll infinito) ; $almacen = Almacen|null ;
     $inicial = bool (vista sin filtros: la tabla abre VACÍA con el aviso de filtros) --}}
@php
    $rows    = $productos ?? collect();
    $inicial = $inicial ?? false;
    // Reparto del saldo por proyecto, [ID_PRODUCTO => filas]. Lo arma el controlador de una
    // sola consulta para toda la pagina (AlmacenController::repartoDeLaPagina) y llega VACIO
    // en los almacenes que no separan por proyecto.
    $reparto = $reparto ?? collect();
    // 6 columnas SIEMPRE: Foto · Descripción · Categoría · Stock (con unidad) · Salida · Detalles.
    // El CÓDIGO ya no tiene columna: va dentro de la celda de Descripción, pequeño y encima
    // del nombre (pedido del cliente). La columna "Salida/Cantidad" se muestra a TODOS; el
    // permiso almacen.movimiento NO oculta la captura — solo bloquea ABRIR la salida y
    // ejecutarla (ver almSelAccion y el backend). La unidad (UM) tampoco tiene columna
    // propia: vive junto al número en la celda de Stock.
    $cols    = 6;
@endphp

@if(!$almacen)
    {{-- Empty-state: solo lo ven los usuarios GLOBAL (NIVEL_ACCESO_ALMACEN=1) cuando el
         sistema no tiene almacenes creados todavía. Los LOCAL nunca llegan aquí
         porque el controller los redirige al menú con flash_toast (ver AlmacenController::index). --}}
    <tr>
        <td colspan="{{ $cols }}" class="alm-vacio">
            <i class="material-icons">warehouse</i>
            Aún no hay almacenes registrados. Usa el menú "Acciones → Nuevo almacén" para crear el primero.
        </td>
    </tr>
@elseif($inicial && $rows->count() === 0)
    {{-- Sin filtros: al abrir el módulo la tabla no carga productos (pedido del cliente). --}}
    <tr>
        <td colspan="{{ $cols }}" class="alm-vacio alm-vacio-alto">
            <i class="material-icons">filter_alt</i>
            Usa los filtros para ver el inventario de <strong>{{ $almacen->NOMBRE }}</strong>.
        </td>
    </tr>
@elseif($rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" class="alm-vacio">
            <i class="material-icons">search_off</i>
            Sin coincidencias en <strong>{{ $almacen->NOMBRE }}</strong>.
            @if(request()->filled('search'))
                <br><span class="alm-vacio-pista">
                    Quizá existe en el catálogo pero sin movimientos aquí. Agrégalo con una entrada o traspaso.
                </span>
            @endif
        </td>
    </tr>
@else
    @foreach($rows as $p)
        @php
            $saldo   = (float) ($p->saldo ?? 0);
            $minimo  = $p->minimo !== null ? (float) $p->minimo : null;
            $bajo    = $minimo !== null && $saldo <= $minimo;
            $codJs   = addslashes($p->CODIGO);
            $nomJs   = addslashes($p->NOMBRE);
            $umJs    = addslashes($p->UM);
            $catJs   = addslashes($p->CATEGORIA ?? '');
            $ubiJs   = addslashes($p->UBICACION ?? '');
            $minArg  = $minimo !== null ? $minimo : 'null';
            // Filtros: números de parte EQUIVALENTES + MODELOS de equipo compatibles (para
            // el tooltip de la fila y el modal de detalles). Vacío en productos no-filtro.
            $equivs  = $p->relationLoaded('equivalencias')       ? $p->equivalencias->pluck('NUMERO_PARTE')->all() : [];
            // Cada equipo compatible como {t: tipo, m: marca, mo: modelo} para mostrar los
            // tres campos (tipo · marca · modelo) en el tooltip y apilados en el modal.
            $equipos = $p->relationLoaded('modelosCompatibles')
                ? $p->modelosCompatibles->map(fn ($m) => ['t' => $m->TIPO, 'm' => $m->marca_equipo ?? null, 'mo' => $m->MODELO])->all()
                : [];
            // Saldo por proyecto (solo almacenes que separan): la fila se ve igual que cualquier
            // otra, pero al seleccionarla el modal «¿De qué proyecto sale?» enseña cuánto tiene
            // cada proyecto y pide de cuál se descuenta. También con uno solo: así quien despacha
            // ve de qué proyecto sale antes de poner la cantidad (pedido del cliente).
            $bolsas     = $reparto->get($p->ID_PRODUCTO, collect());
            $bolsasJson = $bolsas->isNotEmpty()
                ? $bolsas->map(fn ($b) => [
                    'f' => (int) $b->ID_FRENTE,
                    'n' => \App\Services\InventarioService::rotuloBolsa($b->ID_FRENTE, $b->NOMBRE_FRENTE),
                    'q' => (float) $b->CANTIDAD,
                    'c' => \App\Services\InventarioService::esBolsaComun($b->ID_FRENTE, $b->NOMBRE_FRENTE),
                ])->values()->toJson(JSON_UNESCAPED_UNICODE)
                : null;
        @endphp
        <tr class="alm-row {{ $bajo ? 'alm-row-bajo' : '' }} alm-row-clickable"
            data-id-producto="{{ $p->ID_PRODUCTO }}" data-codigo="{{ $p->CODIGO }}" data-nombre="{{ $p->NOMBRE }}" data-um="{{ $p->UM }}" data-saldo="{{ $saldo }}"
            data-bajo="{{ $bajo ? '1' : '0' }}" data-minimo="{{ $minimo !== null ? $minimo : '' }}"
            {{-- Con UNA equivalencia es esa; con varias, el almacenista elige cuál entrega
                 (almRowPartePick) antes de poder poner la cantidad. --}}
            @if($equivs) data-equiv="{{ implode('|', $equivs) }}" data-parte-sel="{{ count($equivs) === 1 ? $equivs[0] : '' }}" @endif
            @if($bolsasJson) data-bolsas="{{ $bolsasJson }}" @endif>
            {{-- Foto del producto, a la IZQUIERDA de la descripción. Sin foto se ve un recuadro
                 con su ícono, para que la columna no baile de ancho. Se sube desde "Detalles
                 del producto" (ver AlmacenController::subirFotoProducto); aqui va la miniatura
                 (?sz=w120, cacheada en disco por el proxy) y tocarla abre la foto entera (almVerFoto). --}}
            <td class="alm-td-foto">
                @if($p->FOTO)
                    <img src="{{ $p->FOTO }}{{ str_contains($p->FOTO, '?') ? '&' : '?' }}sz=w120" alt="{{ $p->NOMBRE }}" class="alm-foto" loading="lazy"
                         title="Ver la foto" onclick="event.stopPropagation(); window.almVerFoto(this.src)">
                @else
                    <span class="alm-foto alm-foto-sin" title="Sin foto"><i class="material-icons">inventory_2</i></span>
                @endif
            </td>
            {{-- Descripción + tooltip-bubble con la UBICACION (mismo patrón de /admin/equipos).
                 El tooltip se activa al hover de cualquier parte de la fila por la regla CSS
                 `.alm-row:hover .tooltip-bubble` que agregué en index.blade.php.
                 El CÓDIGO abre la celda, en pequeño y encima del nombre. Es el ÚNICO sitio
                 donde se pinta: antes tenía columna propia en PC y además se repetía en
                 teléfono con un ::before que leía data-codigo. Una sola fuente. --}}
            <td class="alm-td-nombre">
                <span class="alm-cod-mini">{{ $p->CODIGO }}</span>
                @if($equivs)
                    {{-- Filtros: el TIPO (nombre) va un poco más chico y los NÚMEROS DE PARTE
                         más grandes y oscuros (como el tipo) — el cliente los quiere como el dato
                         principal para distinguir filtros del mismo tipo sin hacer hover. --}}
                    <span class="alm-nombre-txt">{{ $p->NOMBRE }}</span>
                    @if(count($equivs) > 1)
                        {{-- Números de parte CLICKEABLES: al registrar una SALIDA, haz clic en el
                             que entregas — se resalta (subrayado + negrita) y ESE sale en la Nota
                             de Entrega y en la bitácora. Ninguno va marcado de antemano: al
                             seleccionar la fila se pide elegir uno (.alm-row-pide-parte).
                             data-no-toggle va SOLO en cada número (su clic elige la parte): el hueco
                             y los separadores de la línea seleccionan la fila como el resto; con la
                             marca en toda la línea, tocar ahí no hacía nada. --}}
                        <div class="alm-parte-list">
                            @foreach($equivs as $np)
                                <span class="alm-parte-opt" data-no-toggle data-parte="{{ $np }}"
                                      onclick="window.almRowPartePick && window.almRowPartePick(this)">{{ $np }}</span>@unless($loop->last)<span class="alm-parte-sep"> · </span>@endunless
                            @endforeach
                        </div>
                    @else
                        <div class="alm-equiv-linea">{{ $equivs[0] }}</div>
                    @endif
                @else
                    {{ $p->NOMBRE }}
                @endif
                @php
                    // El tooltip (burbuja al hover) muestra ubicación + equipos. Las equivalencias
                    // ya NO van aquí: se muestran inline debajo del nombre (arriba). Cada parte se
                    // escapa con e(); el <br> literal es lo único sin escapar (de ahí el {!! !!}).
                    $tip = [];
                    if (!empty($p->UBICACION)) $tip[] = '📍 ' . e($p->UBICACION);
                    if ($equipos) {
                        // Cada equipo en su línea: "TIPO · MARCA · MODELO" (uno abajo del otro), TODO
                        // en mayúsculas como en Detalles del producto y en /admin/equipos: el tipo en
                        // Title Case junto a la marca en mayúsculas se leía disparejo. mb_strtoupper
                        // respeta acentos; los espacios de más se juntan ("VOLTEO  HIDROJET").
                        $may    = fn ($s) => mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) ($s ?? ''))));
                        $fmt    = fn ($e) => implode(' · ', array_filter([$may($e['t'] ?? null), $may($e['m'] ?? null), $may($e['mo'] ?? null)]));
                        // SIN REPETIDOS: el catálogo guarda una ficha por año/versión del mismo
                        // modelo y el producto queda vinculado a TODAS, así que "Camioneta ·
                        // Toyota · Hilux" salía dos y tres veces. Se compara el texto ya armado
                        // (mismo criterio con que agrupa CompatibilidadProductoService::equipos()).
                        $nombres = array_values(array_unique(array_map($fmt, $equipos)));
                        // Y SOLO LOS PRIMEROS: hay filtros que sirven a 40 equipos y la burbuja
                        // se volvía una lista interminable que tapaba la tabla. El resto se
                        // cuenta; la lista completa está en "Detalles del producto".
                        $tope    = 6;
                        $lineas  = array_map(fn ($n) => '&bull; ' . e($n), array_slice($nombres, 0, $tope));
                        $ocultos = count($nombres) - count($lineas);
                        if ($ocultos > 0) {
                            $lineas[] = '<span class="alm-tip-mas">… y ' . $ocultos . ' equipo' . ($ocultos === 1 ? '' : 's') . ' más (ábrelo en Detalles)</span>';
                        }
                        $tip[] = '🚜 Equipos asociados (' . count($nombres) . '):<br>' . implode('<br>', $lineas);
                    }
                @endphp
                @if($tip)
                    {{-- Los bloques del tooltip (ubicación / equipos asociados) van separados por
                         una raya sutil — no un simple <br>, que los dejaba pegados como si fueran
                         una sola idea. Solo aparece la raya cuando hay MÁS de un bloque. --}}
                    <div class="tooltip-bubble">
                        {!! implode('<div class="alm-tip-sep"></div>', $tip) !!}
                        <div class="alm-tip-flecha"></div>
                    </div>
                @endif
            </td>
            <td class="alm-td-cat">{{ $p->CATEGORIA ?: '—' }}</td>
            {{-- El color del texto siempre es negro (#0f172a). El stock bajo se indica con
                 el fondo rojo de la fila (.alm-row-bajo) y el icono ⚠ amarillo. La unidad
                 (UM) se muestra junto al número — ya no hay columna "UND" aparte. --}}
            <td class="alm-td-stock">
                <span class="alm-stock-num">{{ rtrim(rtrim(number_format($saldo, 3, ',', '.'), '0'), ',') ?: '0' }}<span class="alm-stock-um">{{ $p->UM }}</span>
                    @if($bajo)<i class="material-icons alm-ico-minimo" title="Stock en o por debajo del mínimo">warning</i>@endif
                </span>
            </td>
            {{-- Cantidad de salida por fila: stepper con input a la izquierda y dos botones
                 verticales (+ arriba, − abajo) pegados a la derecha — patrón "spinner clásico".
                 Los tres elementos se habilitan SOLO cuando la fila está seleccionada (clic
                 fuera de inputs/botones). El valor se persiste en almSeleccion[id].cantidad
                 y sobrevive a recargas del tbody (paginación / filtros) vía almSelApplyToVisible.
                 Sanitización + tope al saldo: almRowCantInput / almRowCantStep recortan al
                 stock disponible y muestran un toast si el usuario intenta excederlo.
                 La validación de "cantidad > 0" en cada fila se vuelve a verificar al
                 confirmar la Nota de Entrega (las filas faltantes se resaltan en rojo). --}}
            <td class="alm-td-cant" data-no-toggle>
                <div class="alm-cant-stepper">
                    {{-- name/aria-label por fila: sin ellos Chrome avisa en consola ("A form
                         field element should have an id or name attribute") una vez por fila y
                         en cada filtrado, porque el tbody se vuelve a pintar entero. No hay
                         <form> alrededor —la salida se manda por AJAX— así que el name solo
                         sirve para identificar el campo; el aria-label es el que da el nombre
                         accesible, que antes se apoyaba solo en el placeholder "0". --}}
                    <input type="text" inputmode="decimal" class="alm-row-cant" placeholder="0"
                           name="cant_{{ $p->ID_PRODUCTO }}"
                           aria-label="Cantidad de salida de {{ $p->NOMBRE }}"
                           disabled autocomplete="off"
                           onclick="event.stopPropagation();"
                           onkeydown="return (window.almRowCantKeyDown ? window.almRowCantKeyDown(event) : true)"
                           onpaste="return (window.almRowCantPaste ? window.almRowCantPaste(event) : true)"
                           oninput="window.almRowCantInput && window.almRowCantInput(this)">
                    <div class="alm-cant-flechas">
                        <button type="button" class="alm-cant-btn alm-cant-btn-sube" data-step="1" disabled
                                onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,1);">▲</button>
                        <button type="button" class="alm-cant-btn" data-step="-1" disabled
                                onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,-1);">▼</button>
                    </div>
                </div>
            </td>
            <td class="alm-td-det" data-no-toggle>
                <button type="button" class="btn-details-mini" title="Ver detalles del producto"
                        onclick="window.almAbrirDetalle({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}','{{ $catJs }}',{{ $saldo }},{{ $minArg }},'{{ $ubiJs }}','{{ addslashes($p->FOTO ?? '') }}')">
                    <i class="material-icons alm-ico-detalle">visibility</i>
                </button>
            </td>
        </tr>
    @endforeach
@endif
