@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" style="display:flex; justify-content:center; align-items:center;">
        <ul class="pag-sliding" style="display:flex; flex-wrap:wrap; list-style:none; padding:0; margin:0; justify-content:center; gap:6px;">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <li>
                    <span style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #e2e8f0; background:#f8fafc; color:#94a3b8; cursor:not-allowed;">
                        &laquo; <span class="pag-nav-text">Anterior</span>
                    </span>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="page-link" style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #cbd5e1; background:#fff; color:#475569; text-decoration:none; transition:all 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                        &laquo; <span class="pag-nav-text">Anterior</span>
                    </a>
                </li>
            @endif

            {{-- Sliding numbers logic --}}
            @php
                // ── Ventana de tamaño FIJO que se desliza ──────────────────────
                // Antes: start = current-5 (recortado a 1) y end = current+5. Cerca
                // de la página 1 el lado izquierdo se recortaba, así que la barra
                // CRECÍA al avanzar (pág 1 → 6 botones, pág 4 → 9, centro → 11). Ahora
                // el bloque tiene ancho constante y sólo se desplaza: la cantidad de
                // botones NO cambia al navegar (salvo cuando hay menos páginas que la
                // ventana, o en los extremos donde ya no existen más páginas).
                $current = $paginator->currentPage();
                $last    = $paginator->lastPage();

                // Desktop: bloque de 11 (actual ±5 ideal).
                $side   = 5;
                $window = $side * 2 + 1;
                $start  = max(1, min($current - $side, $last - $window + 1));
                $end    = min($last, $start + $window - 1);

                // Móvil: bloque de 5 (actual ±2 ideal). Marca los .pag-far (ocultos en
                // teléfono vía CSS) con ventana fija también, para que en móvil la
                // cantidad de números tampoco cambie al navegar.
                $mSide   = 2;
                $mWindow = $mSide * 2 + 1;
                $mStart  = max(1, min($current - $mSide, $last - $mWindow + 1));
                $mEnd    = min($last, $mStart + $mWindow - 1);
            @endphp

            @for ($i = $start; $i <= $end; $i++)
                {{-- pag-far: en teléfono sólo se muestran los números de la ventana
                     móvil (fija, actual ±2 deslizante); el resto se oculta vía CSS
                     para que el paginador no desborde la pantalla. --}}
                @php $esFar = ($i < $mStart || $i > $mEnd); @endphp
                @if ($i == $paginator->currentPage())
                    <li class="pag-num">
                        <span style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:700; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8;">
                            {{ $i }}
                        </span>
                    </li>
                @else
                    <li class="pag-num{{ $esFar ? ' pag-far' : '' }}">
                        <a href="{{ $paginator->url($i) }}" class="page-link" style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #cbd5e1; background:#fff; color:#0f172a; text-decoration:none; transition:all 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                            {{ $i }}
                        </a>
                    </li>
                @endif
            @endfor

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="page-link" style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #cbd5e1; background:#fff; color:#475569; text-decoration:none; transition:all 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                        <span class="pag-nav-text">Siguiente</span> &raquo;
                    </a>
                </li>
            @else
                <li>
                    <span style="display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #e2e8f0; background:#f8fafc; color:#94a3b8; cursor:not-allowed;">
                        <span class="pag-nav-text">Siguiente</span> &raquo;
                    </span>
                </li>
            @endif
        </ul>
    </nav>
@endif
