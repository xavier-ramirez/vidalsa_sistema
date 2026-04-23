<!-- background_svg.blade.php
     Figuras decorativas de fondo para las paginas admin.
     La figura gris del top-left fue removida: generaba ruido visual
     y rompia la sensacion "limpia" pedida por el cliente.
     Se mantienen 3 acentos azul corporativo muy discretos. -->
<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 0; pointer-events: none; overflow: hidden;">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMinYMin slice" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
        <!-- Acento azul inferior (curva limpia, opacidad baja para no distraer) -->
        <path d="M0 900 V 400 Q 150 750 600 850 T 1440 900 Z" fill="#00004d" opacity="0.92" />
        <!-- Acento azul derecho superior -->
        <path d="M1440 0 V 250 Q 1330 200 1280 0 Z" fill="#00004d" />
        <!-- Acento azul derecho inferior -->
        <path d="M1440 900 V 650 Q 1380 750 1440 850 Z" fill="#00004d" opacity="0.9" />
    </svg>
</div>
