{{-- Filas de la tabla de inventario. $productos = Collection|null (lote del scroll infinito) ; $almacen = Almacen|null ; $inicial = bool (la tabla abre vacía hasta que se filtre) --}}
@php
    $rows    = $productos ?? collect();
    $inicial = $inicial ?? false;
    // 6 columnas SIEMPRE: Código · Descripción · Categoría · Stock (con unidad) ·
    // Salida · Detalles. La columna "Salida/Cantidad" se muestra a TODOS; el permiso
    // almacen.movimiento NO oculta la captura — solo bloquea ABRIR la salida y
    // ejecutarla (ver almSelAccion y el backend). La unidad (UM) ya no tiene columna
    // propia: vive junto al número en la celda de Stock.
    $cols    = 6;
@endphp

@if(!$almacen)
    {{-- Empty-state: solo lo ven los usuarios GLOBAL (NIVEL_ACCESO=1) cuando el
         sistema no tiene almacenes creados todavía. Los LOCAL nunca llegan aquí
         porque el controller los redirige al menú con flash_toast (ver AlmacenController::index). --}}
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">warehouse</i>
            Aún no hay almacenes registrados. Usa el menú "Acciones → Nuevo almacén" para crear el primero.
        </td>
    </tr>
@elseif($inicial && $rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">filter_alt</i>
            Usa los filtros para ver el inventario de <strong>{{ $almacen->NOMBRE }}</strong>.
        </td>
    </tr>
@elseif($rows->count() === 0)
    <tr>
        <td colspan="{{ $cols }}" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">search_off</i>
            Sin coincidencias en <strong>{{ $almacen->NOMBRE }}</strong>.
            @if(request()->filled('search'))
                <br><span style="display:inline-block;margin-top:6px;font-size:12.5px;color:#94a3b8;max-width:420px;">
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
            @if($equivs) data-equiv="{{ implode('|', $equivs) }}" data-parte-sel="{{ $equivs[0] }}" @endif>
            <td class="alm-td-codigo" style="font-weight:600;color:#1e293b;white-space:nowrap;padding:12px 8px;">{{ $p->CODIGO }}</td>
            {{-- Descripción + tooltip-bubble con la UBICACION (mismo patrón de /admin/equipos).
                 El tooltip se activa al hover de cualquier parte de la fila por la regla CSS
                 `.alm-row:hover .tooltip-bubble` que agregué en index.blade.php.
                 data-codigo lo lee la regla mobile ::before para mostrar el codigo como
                 prefijo monospace de la descripcion (el cliente lo quiere como un solo
                 dato "00042 · ABRAZADERA"). En desktop hay columna codigo aparte, asi que
                 el atributo queda sin uso pero no estorba. --}}
            <td class="alm-td-nombre" data-codigo="{{ $p->CODIGO }}" style="font-weight:600;color:#1e293b;position:relative;">
                @if($equivs)
                    {{-- Filtros: el TIPO (nombre) va un poco más chico y los NÚMEROS DE PARTE
                         más grandes y oscuros (como el tipo) — el cliente los quiere como el dato
                         principal para distinguir filtros del mismo tipo sin hacer hover. --}}
                    <span style="font-size:12px;">{{ $p->NOMBRE }}</span>
                    @if(count($equivs) > 1)
                        {{-- Números de parte CLICKEABLES: al registrar una SALIDA, haz clic en el
                             que entregas — se resalta (subrayado + negrita) y ESE sale en la Nota
                             de Entrega y en la bitácora. El primero (principal) va marcado por
                             defecto. data-no-toggle: el clic NO togglea la selección de la fila. --}}
                        <div class="alm-parte-list" data-no-toggle style="font-size:13px;color:#1e293b;font-weight:600;margin-top:2px;line-height:1.55;word-break:break-word;">
                            @foreach($equivs as $i => $np)
                                <span class="alm-parte-opt{{ $i === 0 ? ' alm-parte-on' : '' }}" data-no-toggle data-parte="{{ $np }}"
                                      onclick="window.almRowPartePick && window.almRowPartePick(this)">{{ $np }}</span>@unless($loop->last)<span style="color:#cbd5e0;"> · </span>@endunless
                            @endforeach
                        </div>
                    @else
                        <div style="font-size:13px;font-weight:600;color:#1e293b;margin-top:2px;line-height:1.4;word-break:break-word;">{{ $equivs[0] }}</div>
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
                    <div class="tooltip-bubble" style="pointer-events:none;opacity:0;visibility:hidden;position:absolute;bottom:100%;left:0;transform:translateY(5px);background:#1e293b;color:#fff;padding:10px 14px;border-radius:6px;font-size:14px;font-weight:600;line-height:1.6;white-space:normal;width:max-content;max-width:420px;word-wrap:break-word;text-align:left;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transition:all 0.2s ease-in-out;z-index:9001;margin-bottom:5px;">
                        {!! implode('<div style="border-top:1px solid rgba(255,255,255,.2);margin:7px 0;"></div>', $tip) !!}
                        <div style="position:absolute;top:100%;left:30px;margin-left:-4px;border-width:4px;border-style:solid;border-color:#1e293b transparent transparent transparent;"></div>
                    </div>
                @endif
            </td>
            <td class="alm-td-cat" style="font-weight:600;color:#1e293b;">{{ $p->CATEGORIA ?: '—' }}</td>
            {{-- El color del texto siempre es negro (#0f172a). El stock bajo se indica con
                 el fondo rojo de la fila (.alm-row-bajo) y el icono ⚠ amarillo. La unidad
                 (UM) se muestra junto al número — ya no hay columna "UND" aparte. --}}
            <td class="alm-td-stock" style="text-align:center;color:#0f172a;">
                {{ rtrim(rtrim(number_format($saldo, 3, ',', '.'), '0'), ',') ?: '0' }}<span class="alm-stock-um">{{ $p->UM }}</span>
                @if($bajo)<i class="material-icons" style="font-size:14px;color:#f59e0b;vertical-align:middle;" title="Stock en o por debajo del mínimo">warning</i>@endif
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
            <td class="alm-td-cant" style="text-align:center;white-space:nowrap;width:100px;" data-no-toggle>
                <div class="alm-cant-stepper" style="display:inline-flex;align-items:stretch;border:1px solid #cbd5e0;border-radius:6px;overflow:hidden;background:#f1f5f9;height:30px;">
                    <input type="text" inputmode="decimal" class="alm-row-cant" placeholder="0"
                           disabled autocomplete="off"
                           style="width:56px;border:none;background:transparent;text-align:center;font-size:13px;font-weight:700;color:#94a3b8;outline:none;padding:0;"
                           onclick="event.stopPropagation();"
                           onkeydown="return (window.almRowCantKeyDown ? window.almRowCantKeyDown(event) : true)"
                           onpaste="return (window.almRowCantPaste ? window.almRowCantPaste(event) : true)"
                           oninput="window.almRowCantInput && window.almRowCantInput(this)">
                    <div style="display:flex;flex-direction:column;border-left:1px solid #cbd5e0;width:18px;">
                        <button type="button" class="alm-cant-btn" data-step="1" disabled
                                style="flex:1;border:none;background:#e2e8f0;color:#94a3b8;font-weight:800;font-size:11px;line-height:1;cursor:not-allowed;padding:0;border-bottom:1px solid #cbd5e0;"
                                onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,1);">▲</button>
                        <button type="button" class="alm-cant-btn" data-step="-1" disabled
                                style="flex:1;border:none;background:#e2e8f0;color:#94a3b8;font-weight:800;font-size:11px;line-height:1;cursor:not-allowed;padding:0;"
                                onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,-1);">▼</button>
                    </div>
                </div>
            </td>
            <td class="alm-td-det" style="text-align:center;white-space:nowrap;width:38px;padding:12px 6px;" data-no-toggle>
                <button type="button" class="btn-details-mini" title="Ver detalles del producto"
                        onclick="window.almAbrirDetalle({{ $p->ID_PRODUCTO }},'{{ $codJs }}','{{ $nomJs }}','{{ $umJs }}','{{ $catJs }}',{{ $saldo }},{{ $minArg }},'{{ $ubiJs }}')">
                    <i class="material-icons" style="font-size:18px;">visibility</i>
                </button>
            </td>
        </tr>
    @endforeach
@endif
