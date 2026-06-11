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
                // Muestra siempre 5 a la izquierda y 5 a la derecha de la pagina actual
                $start = max(1, $paginator->currentPage() - 5);
                $end = min($paginator->lastPage(), $paginator->currentPage() + 5);
            @endphp

            @for ($i = $start; $i <= $end; $i++)
                {{-- pag-far: en teléfono solo se muestran los números cercanos (actual ±2);
                     el resto se oculta vía CSS para que el paginador no desborde la pantalla. --}}
                @php $esFar = abs($i - $paginator->currentPage()) > 2; @endphp
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
