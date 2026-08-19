{{-- ─────────────────────────────────────────────────────────────────────────────
     Pestañas de Recepción. Las comparten la bandeja (index) y la pantalla de
     Entrada por ODC (nueva): antes el bloque estaba COPIADO en las dos, y cada
     retoque había que hacerlo dos veces. Renombrar "Bandeja de entrada" a
     "Reposición del general" obligó a tocar los dos archivos más el comentario
     del CSS; con el bloque aquí, el texto vive en un solo sitio.

     Parámetros:
       $activa → 'bandeja' | 'odc'. Solo decide el ESTILO (y, en ODC, si es enlace).
       $clase  → clase del contenedor. NO es cosmética: el responsive apunta a cada
                 una por separado (.tr-tabs en index; .ent-tabs bajo
                 body:has(.ent-layout) en estilos_globales), así que cada página
                 conserva la suya.

     La pestaña de la bandeja es SIEMPRE un enlace, incluso estando en ella. No es
     un descuido: apunta a ?force=1, que es (a) lo único que le enseña la bandeja a
     un usuario GLOBAL —sin ese parámetro el controlador lo manda a ODC— y (b) la
     forma de volver a la bandeja limpia, sin los filtros puestos. Convertirla en
     texto plano al estar dentro quitaba ese reinicio.

     La de ODC sí es texto plano cuando es la activa: no lleva parámetros que
     reiniciar y ya estás dentro. El @can solo envuelve el ENLACE; la ruta tiene
     su propio gate.
───────────────────────────────────────────────────────────────────────────── --}}
@php
    $enBandeja = ($activa ?? 'bandeja') === 'bandeja';
    $base      = 'display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;';
    $on        = $base . 'font-weight:700;color:#0067b1;border-bottom:2px solid #0067b1;margin-bottom:-2px;text-decoration:none;';
    $off       = $base . 'font-weight:600;color:#64748b;text-decoration:none;transition:all .15s;';
@endphp

<div class="{{ $clase ?? 'tr-tabs' }}" style="display:flex;gap:0;margin-top:12px;border-bottom:2px solid #e2e8f0;">

    {{-- El nombre dice el ORIGEN, no el continente. "Bandeja de entrada" describía el
         cajón; lo que hace falta saber al entrar es de dónde viene el material, porque
         es lo único que separa esta vía de la compra directa. --}}
    <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
       title="Material que el almacén general ya despachó con su nota de entrega. Aquí no se captura nada: solo se confirma lo que llegó."
       style="{{ $enBandeja ? $on : $off }}"
       @unless($enBandeja) onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'" @endunless>
        <i class="material-icons" style="font-size:16px;">inbox</i> Reposición del general
    </a>

    @if($enBandeja)
        @can('almacen.movimiento')
        <a href="{{ route('almacen.recepcion.nueva') }}"
           style="{{ $off }}"
           onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'">
            <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada<span class="ent-txt-full"> por ODC</span>
        </a>
        @endcan
    @else
        <span style="{{ $on }}">
            <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada<span class="ent-txt-full"> por ODC</span>
        </span>
    @endif
</div>
