{{-- Filas de la tabla de inventario. $productos = Collection|null (lote del scroll infinito) ; $almacen = Almacen|null ; $inicial = bool (la tabla abre vacía hasta que se filtre) --}}
@php
    $rows    = $productos ?? collect();
    $inicial = $inicial ?? false;
    // Reparto del saldo por proyecto, [ID_PRODUCTO => filas]. Lo arma el controlador de una
    // sola consulta para toda la pagina (AlmacenController::repartoDeLaPagina) y llega VACIO
    // en los almacenes que no separan por proyecto: alli todo el saldo es de la bolsa comun
    // y el desglose repetiria el total.
    $reparto = $reparto ?? collect();
    // 6 columnas SIEMPRE: Código · Descripción · Categoría · Stock (con unidad) ·
    // Salida · Detalles. La columna "Salida/Cantidad" se muestra a TODOS; el permiso
    // almacen.movimiento NO oculta la captura — solo bloquea ABRIR la salida y
    // ejecutarla (ver almSelAccion y el backend). La unidad (UM) ya no tiene columna
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
        @endphp
        <tr class="alm-row {{ $bajo ? 'alm-row-bajo' : '' }} alm-row-clickable"
            data-id-producto="{{ $p->ID_PRODUCTO }}" data-codigo="{{ $p->CODIGO }}" data-nombre="{{ $p->NOMBRE }}" data-um="{{ $p->UM }}" data-saldo="{{ $saldo }}"
            data-bajo="{{ $bajo ? '1' : '0' }}" data-minimo="{{ $minimo !== null ? $minimo : '' }}"
            @if($equivs) data-equiv="{{ implode('|', $equivs) }}" data-parte-sel="{{ $equivs[0] }}" @endif
            {{-- Bolsa de la que sale el material de ESTA fila. Vacío = automático (la del
                 proyecto destino de la nota). Solo la escriben las filas con desglose: si el
                 saldo tiene un dueño único no hay nada que elegir. Lo mismo que data-parte-sel
                 hace con el nº de parte. --}}
            data-bolsa-sel="">
            <td class="alm-td-codigo">{{ $p->CODIGO }}</td>
            {{-- Descripción + tooltip-bubble con la UBICACION (mismo patrón de /admin/equipos).
                 El tooltip se activa al hover de cualquier parte de la fila por la regla CSS
                 `.alm-row:hover .tooltip-bubble` que agregué en index.blade.php.
                 data-codigo lo lee la regla mobile ::before para mostrar el codigo como
                 prefijo monospace de la descripcion (el cliente lo quiere como un solo
                 dato "00042 · ABRAZADERA"). En desktop hay columna codigo aparte, asi que
                 el atributo queda sin uso pero no estorba. --}}
            <td class="alm-td-nombre" data-codigo="{{ $p->CODIGO }}">
                @if($equivs)
                    {{-- Filtros: el TIPO (nombre) va un poco más chico y los NÚMEROS DE PARTE
                         más grandes y oscuros (como el tipo) — el cliente los quiere como el dato
                         principal para distinguir filtros del mismo tipo sin hacer hover. --}}
                    <span class="alm-nombre-txt">{{ $p->NOMBRE }}</span>
                    @if(count($equivs) > 1)
                        {{-- Números de parte CLICKEABLES: al registrar una SALIDA, haz clic en el
                             que entregas — se resalta (subrayado + negrita) y ESE sale en la Nota
                             de Entrega y en la bitácora. El primero (principal) va marcado por
                             defecto. data-no-toggle: el clic NO togglea la selección de la fila. --}}
                        <div class="alm-parte-list" data-no-toggle>
                            @foreach($equivs as $i => $np)
                                <span class="alm-parte-opt{{ $i === 0 ? ' alm-parte-on' : '' }}" data-no-toggle data-parte="{{ $np }}"
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
                        // Cada equipo en su línea: "Tipo · Marca · Modelo" (uno abajo del otro).
                        // Se muestran TODOS: la burbuja crece para que quepan (sin cortar con "+N").
                        // CADA PALABRA con su primera letra en mayúscula (Title Case): los datos
                        // vienen en minúsculas y "camion de plataforma" debe verse "Camion De
                        // Plataforma". Str::title es multibyte (respeta acentos).
                        $cap    = fn ($s) => \Illuminate\Support\Str::title(trim((string) ($s ?? '')));
                        $fmt    = fn ($e) => implode(' · ', array_filter([$cap($e['t'] ?? null), $cap($e['m'] ?? null), $cap($e['mo'] ?? null)]));
                        $lineas = array_map(fn ($e) => '&bull; ' . e($fmt($e)), $equipos);
                        $tip[]  = '🚜 Equipos asociados:<br>' . implode('<br>', $lineas);
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
            @php
                // Bolsas de ESTE producto en el almacen abierto. 0 filas = el almacen no
                // separa por proyecto (o el producto no tiene saldo): la celda queda como
                // siempre, un numero y nada mas.
                $bolsas = $reparto->get($p->ID_PRODUCTO, collect());
                $unaBolsa = $bolsas->count() === 1 ? $bolsas->first() : null;
            @endphp
            <td class="alm-td-stock">
                <span class="alm-stock-num">{{ rtrim(rtrim(number_format($saldo, 3, ',', '.'), '0'), ',') ?: '0' }}<span class="alm-stock-um">{{ $p->UM }}</span>
                    @if($bajo)<i class="material-icons alm-ico-minimo" title="Stock en o por debajo del mínimo">warning</i>@endif
                </span>
                {{-- Un solo dueño: el nombre va debajo del numero, sin nada que abrir. Es el
                     caso mas frecuente y no merece un clic. --}}
                @if($unaBolsa)
                    <span class="alm-bolsa-uno {{ \App\Services\InventarioService::esBolsaComun($unaBolsa->ID_FRENTE, $unaBolsa->NOMBRE_FRENTE) ? 'es-comun' : '' }}"
                          title="Todo este saldo es de: {{ \App\Services\InventarioService::rotuloBolsa($unaBolsa->ID_FRENTE, $unaBolsa->NOMBRE_FRENTE) }}">
                        {{ \App\Services\InventarioService::rotuloBolsa($unaBolsa->ID_FRENTE, $unaBolsa->NOMBRE_FRENTE) }}
                    </span>
                {{-- Repartido entre varios proyectos: el total manda y el desglose se abre.
                     data-no-toggle para que el clic no marque la fila para despachar. --}}
                @elseif($bolsas->count() > 1)
                    <button type="button" class="alm-bolsa-tog" data-no-toggle
                            aria-expanded="false" title="Elegir de qué proyecto sale el material"
                            onclick="event.stopPropagation(); window.almToggleBolsas && window.almToggleBolsas(this)">
                        {{ $bolsas->count() }} proyectos
                        <i class="material-icons">expand_more</i>
                    </button>
                    {{-- Bolsa elegida en el desglose. Arranca oculto (automático) y lo llena
                         almRowBolsaLabel al elegir: así el usuario ve de qué proyecto sale sin
                         tener que volver a abrir el desglose. --}}
                    <span class="alm-bolsa-elegida" hidden></span>
                @endif
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
                        onclick="window.almAbrirDetalle({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}','{{ $catJs }}',{{ $saldo }},{{ $minArg }},'{{ $ubiJs }}')">
                    <i class="material-icons alm-ico-detalle">visibility</i>
                </button>
            </td>
        </tr>
        {{-- Desglose por proyecto: fila propia debajo del producto, oculta hasta que se
             pulsa el boton de la celda Stock. Va como <tr> y no dentro de la celda porque
             la columna Stock es angosta y los nombres de frente son largos. En telefono el
             CSS la pega a la tarjeta de arriba (ver .alm-row-bolsas en index).

             Cada bolsa es ELEGIBLE, no solo informativa: en un almacén multi-proyecto el
             material de cada frente está separado también en el patio, así que quien despacha
             sabe de qué pila sacó y aquí lo deja escrito. Lo elegido viaja por línea
             (id_frente_saldo) y es de esa bolsa de donde se descuenta.

             Mismo patrón que los números de parte de la descripción (almRowPartePick):
             data-no-toggle para que el clic no marque/desmarque la fila, y la elección se
             guarda en la fila y en almSeleccion para sobrevivir a las recargas del tbody. --}}
        @if($bolsas->count() > 1)
            <tr class="alm-row-bolsas" data-de-producto="{{ $p->ID_PRODUCTO }}" hidden>
                <td colspan="{{ $cols }}">
                    <div class="alm-bolsa-wrap" data-no-toggle>
                        {{-- Automático = lo de siempre: empieza por la bolsa del proyecto destino
                             de la nota y sigue con la común. Va PRIMERO y marcado por defecto
                             porque el destino se elige después (en el modal de salida), así que
                             la fila todavía no sabe cuál es "el suyo". --}}
                        <div class="alm-bolsa-item alm-bolsa-opt alm-bolsa-on es-auto" data-no-toggle data-bolsa=""
                             title="Descuenta del proyecto al que se entrega, y sigue con el saldo sin proyecto si no alcanza"
                             onclick="window.almRowBolsaPick && window.almRowBolsaPick(this)">
                            <span class="nom">Automático (el del proyecto destino)</span>
                        </div>
                        @foreach($bolsas as $b)
                            <div class="alm-bolsa-item alm-bolsa-opt {{ \App\Services\InventarioService::esBolsaComun($b->ID_FRENTE, $b->NOMBRE_FRENTE) ? 'es-comun' : '' }}"
                                 data-no-toggle data-bolsa="{{ (int) $b->ID_FRENTE }}"
                                 data-bolsa-nom="{{ \App\Services\InventarioService::rotuloBolsa($b->ID_FRENTE, $b->NOMBRE_FRENTE) }}"
                                 title="Descontar del saldo de {{ \App\Services\InventarioService::rotuloBolsa($b->ID_FRENTE, $b->NOMBRE_FRENTE) }}"
                                 onclick="window.almRowBolsaPick && window.almRowBolsaPick(this)">
                                <span class="nom">{{ \App\Services\InventarioService::rotuloBolsa($b->ID_FRENTE, $b->NOMBRE_FRENTE) }}</span>
                                <span class="qty">{{ rtrim(rtrim(number_format((float) $b->CANTIDAD, 3, ',', '.'), '0'), ',') ?: '0' }}<span class="alm-stock-um">{{ $p->UM }}</span></span>
                            </div>
                        @endforeach
                    </div>
                </td>
            </tr>
        @endif
    @endforeach
@endif
