{{-- background_svg.blade.php
     Figuras geometricas decorativas del nuevo diseno, compartidas por el login y el menu.
     En el login enmarcan la tarjeta de datos: dispersas ARRIBA, ABAJO y a la IZQUIERDA de ella
     (posicion, altura y tamano variados, no en columna); azul de marca.
     Antes eran 3 acentos navy planos. --}}
<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 0; pointer-events: none; overflow: hidden;">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMinYMin slice" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
        {{-- ARRIBA de la tarjeta --}}
        <polygon points="162,100 214,100 188,52" fill="#0067b1" opacity="0.30"/>
        <circle cx="285" cy="62" r="22" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.42"/>
        <circle cx="285" cy="62" r="12" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.50"/>
        <rect x="330" y="30" width="26" height="26" rx="6" fill="none" stroke="#00004d" stroke-width="3" opacity="0.34" transform="rotate(18 343 43)"/>
        <path d="M405 92 h22 M416 81 v22" stroke="#0067b1" stroke-width="3" opacity="0.45"/>
        <circle cx="470" cy="50" r="6" fill="#00004d" opacity="0.40"/>
        {{-- IZQUIERDA de la tarjeta --}}
        <circle cx="72" cy="250" r="26" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.40"/>
        <circle cx="72" cy="250" r="15" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.50"/>
        <path d="M32 350 h22 M43 339 v22" stroke="#0067b1" stroke-width="3" opacity="0.45"/>
        <rect x="82" y="410" width="34" height="34" rx="8" fill="none" stroke="#00004d" stroke-width="3" opacity="0.34" transform="rotate(15 99 427)"/>
        <circle cx="48" cy="200" r="6" fill="#00004d" opacity="0.40"/>
        <polygon points="55,470 105,470 80,432" fill="#0067b1" opacity="0.30"/>
        {{-- (Las figuras de ABAJO ya NO van aquí: se anclan relativas a la tarjeta en el login
             — ver .login-figs-abajo en inicio_sesion — para que la tarjeta nunca las tape,
             sin importar el alto de pantalla. En el menu no hay tarjeta, asi que no aplican.) --}}
    </svg>
</div>
