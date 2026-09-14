{{-- ─────────────────────────────────────────────────────────────────────────────
     Pestañas de la bandeja de Recepción (index). La pantalla "Entrada por ODC" ya no
     las lleva: allí "Reposición del general" se abre desde su botón Acciones.

     La pestaña de la bandeja es SIEMPRE un enlace, incluso estando en ella. No es
     un descuido: apunta a ?force=1, que es (a) lo único que le enseña la bandeja a
     quien abre Recepción con un almacén GENERAL —sin ese parámetro el controlador
     lo manda a ODC; con uno de PROYECTO entra directo a la bandeja— y (b) la
     forma de volver a la bandeja limpia, sin los filtros puestos. Convertirla en
     texto plano al estar dentro quitaba ese reinicio.

     El @can solo esconde el ENLACE a ODC a quien no puede registrar entradas: la pantalla
     se abre igual (nuevaEntrada no tiene gate) y el permiso se exige al registrar.
     .tr-tabs no es cosmética: el responsive del index la usa para ponerlas lado a lado.
───────────────────────────────────────────────────────────────────────────── --}}
@php
    $base = 'display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;';
@endphp

<div class="tr-tabs" style="display:flex;gap:0;margin-top:12px;border-bottom:2px solid #e2e8f0;">

    {{-- El nombre dice el ORIGEN, no el continente. "Bandeja de entrada" describía el
         cajón; lo que hace falta saber al entrar es de dónde viene el material, porque
         es lo único que separa esta vía de la compra directa. --}}
    <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
       title="Material que el almacén general ya despachó con su nota de entrega. Aquí no se captura nada: solo se confirma lo que llegó."
       style="{{ $base }}font-weight:700;color:#0067b1;border-bottom:2px solid #0067b1;margin-bottom:-2px;text-decoration:none;">
        <i class="material-icons" style="font-size:16px;">inbox</i> Reposición del general
    </a>

    @can('almacen.movimiento')
    <a href="{{ route('almacen.recepcion.nueva') }}"
       style="{{ $base }}font-weight:600;color:#64748b;text-decoration:none;transition:all .15s;"
       onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'">
        <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada por ODC
    </a>
    @endcan
</div>
