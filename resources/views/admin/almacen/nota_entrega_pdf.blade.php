{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — formato VID-FO-GEN-019.

    Construido con atributos HTML clásicos (border, width, align, valign,
    cellpadding, cellspacing) en vez de CSS — TCPDF tiene soporte completo de
    esos atributos y limitado de CSS, así que el render es más predecible.

    El grosor de las líneas (border=1) lo controla SetLineWidth(0.15) que se
    setea en el controller antes de renderizar — el mismo valor que el Header()
    usa, así las cajas del cuerpo quedan con el mismo grosor que el cabezote.

    Fuente: TCPDF no trae Arial nativamente. Helvetica es visualmente identica
    (Arial fue creada por Microsoft como sustituto de Helvetica). Forzamos
    face="helvetica" en TODOS los <font> para garantizar que ningun fragmento
    herede una fuente diferente — toda la nota se ve uniforme.

    Variables disponibles:
      - $datos: ['numero_nota','proyecto','contrato','fecha','rq','solicitante',
                 'departamento','almacen','entregado_por','motivo']
      - $movs:  Collection<MovimientoInventario>  con relaciones {producto}
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $minFilas = 24;
@endphp

{{-- N° de Nota se renderiza en el cabezote (esquina derecha, Header() del PDF).
     Antes esta vista lo imprimía arriba del cuerpo — lo que duplicaba el dato y
     empujaba el contenido a una 2.ª página. Se quitó intencionalmente. --}}

{{-- ── Bloque de datos del proyecto: tabla con 4 filas, grid de 6 columnas ──
     Anchos: 20% | 20% | 10% | 15% | 13% | 22%  (suma 100%)
     fila 1: PROYECTO  (itálica, como el formato oficial)
     fila 2: CONTRATO Nº
     fila 3: FECHA DE ENTREGA · RQ N° · Solicitante
     fila 4: DEPARTAMENTO

     TCPDF: width EXPLICITO en TODOS los <td> incluidos los colspan (con width =
     suma de las columnas que cubren). Si se deja algun td sin width, TCPDF lo
     calcula a partir del contenido minimo y la tabla termina con un ancho real
     diferente al 100% que pedimos -> desalineada vs la tabla de items de abajo.
     cellpadding=2 unificado con la tabla de items para que los bordes verticales
     coincidan visualmente. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td width="20%"><font face="helvetica" size="9"><b><i>PROYECTO:</i></b></font></td>
        <td width="80%" colspan="5"><font face="helvetica" size="9"><b><i>{{ $datos['proyecto'] ?: '' }}</i></b></font></td>
    </tr>
    <tr>
        <td width="20%"><font face="helvetica" size="9"><b>CONTRATO Nº:</b></font></td>
        <td width="80%" colspan="5"><font face="helvetica" size="9">{{ $datos['contrato'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td width="20%"><font face="helvetica" size="9"><b>FECHA DE ENTREGA:</b></font></td>
        <td width="20%"><font face="helvetica" size="9">{{ $datos['fecha'] }}</font></td>
        <td width="10%"><font face="helvetica" size="9"><b>RQ N°:</b></font></td>
        <td width="15%"><font face="helvetica" size="9">{{ $datos['rq'] ?: '' }}</font></td>
        <td width="13%"><font face="helvetica" size="9"><b>Solicitante:</b></font></td>
        <td width="22%"><font face="helvetica" size="9">{{ $datos['solicitante'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td width="20%"><font face="helvetica" size="9"><b>DEPARTAMENTO:</b></font></td>
        <td width="80%" colspan="5"><font face="helvetica" size="9">{{ $datos['departamento'] ?: '' }}</font></td>
    </tr>
</table>

{{-- ── Tabla de ítems: ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL ──
     Notas TCPDF:
     • El width hay que repetirlo en CADA <td> de TODAS las filas (no sólo el header).
       TCPDF no propaga el width del header al resto cuando las celdas están casi vacías.
     • <thead> + <tbody>: TCPDF re-imprime <thead> en cada nueva página si la tabla se
       parte (writeHTMLDocument lo trata como TFOOT/THEAD del estándar HTML/CSS).
     • Filas vacías: <font face="helvetica" size="8">&nbsp;</font> explícito en cada celda — sin esto
       el &nbsp; hereda el font del documento (9.5pt) y la fila vacía queda más alta
       que las llenas (8pt). Con el font explícito, todas las filas tienen mismo alto. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <thead>
        {{-- Header: gris claro #D9D9D9 (visualmente identico al gris del Excel
             "Nueva Nota de entrega de materiales 2025" — indexed 22 con el
             render anti-aliased de Excel se ve mas claro que el #C0C0C0 puro).
             Fuente size=8 (igual que las filas del cuerpo) para que TODA la
             tabla tenga el mismo tamaño de letra — mismo criterio del Excel,
             que usa Arial 8 para items y headers.

             Anchos: 6% / 10% / 10% / 56% / 18% (= 100%). La columna "N° COLADA
             / SERIAL" es ESTRECHA a proposito — el header "N° COLADA /  SERIAL"
             se parte en 2 lineas (intencional, igual que el Excel original) y
             asi DESCRIPCIÓN queda con 56% de ancho (~104 mm) para nombres
             largos de producto. --}}
        <tr bgcolor="#D9D9D9">
            <td width="6%"  align="center"><font face="helvetica" size="8"><b>ITEM</b></font></td>
            <td width="10%" align="center"><font face="helvetica" size="8"><b>CANTIDAD</b></font></td>
            <td width="10%" align="center"><font face="helvetica" size="8"><b>UNIDAD</b></font></td>
            <td width="56%" align="center"><font face="helvetica" size="8"><b>DESCRIPCIÓN</b></font></td>
            <td width="18%" align="center"><font face="helvetica" size="8"><b>N° COLADA /<br/>SERIAL</b></font></td>
        </tr>
    </thead>
    <tbody>
        @foreach($movs as $i => $m)
            <tr>
                <td width="6%"  align="center"><font face="helvetica" size="8">{{ $i + 1 }}</font></td>
                <td width="10%" align="center"><font face="helvetica" size="8">{{ $fmt($m->CANTIDAD) }}</font></td>
                <td width="10%" align="center"><font face="helvetica" size="8">{{ $m->producto?->UM ?? '' }}</font></td>
                <td width="56%"><font face="helvetica" size="8">{{ $m->producto?->NOMBRE ?? '' }}</font></td>
                <td width="18%" align="center"><font face="helvetica" size="8">{{ $m->producto?->CODIGO ?? '' }}</font></td>
            </tr>
        @endforeach
        @for($j = $movs->count(); $j < $minFilas; $j++)
            <tr>
                <td width="6%"  align="center"><font face="helvetica" size="8">{{ $j + 1 }}</font></td>
                <td width="10%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="10%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="56%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="18%"><font face="helvetica" size="8">&nbsp;</font></td>
            </tr>
        @endfor
    </tbody>
</table>

{{-- ── Observaciones: label + caja para escribir. cellpadding unificado a 2.
     TCPDF interpreta el atributo HTML height como pixeles (getHTMLUnitToUnits con
     unidad por defecto 'px'). height="42" ≈ 15mm a 72dpi — espacio comodo para
     2-3 lineas manuscritas. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td><font face="helvetica" size="9"><b>OBSERVACIONES:</b></font></td>
    </tr>
    <tr>
        <td height="42"><font face="helvetica" size="9">{{ $datos['motivo'] ?: ' ' }}</font></td>
    </tr>
</table>

{{-- ── Firmas: label | ENTREGADO POR | RECIBIDO POR ──
     Almacenista del almacén origen va pre-impreso como ENTREGADO POR + cargo
     "COORD. DE MATERIALES" (estándar VIDALSA). RECIBIDO POR en blanco para
     que la persona del proyecto lo rellene a mano al recibir.

     Grid de 3 columnas: 14% | 43% | 43% = 100%. WIDTH EXPLICITO en TODOS los
     <td> de TODAS las filas — sin esto TCPDF recalcula a partir del contenido
     y los bordes verticales no alinean entre filas (esto era parte del problema
     "casillas no alineadas" que reportaste).

     Fila "Firma": height=42 (era 18). Necesita espacio real para una firma
     manuscrita — antes se veía como una línea apretada. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td width="14%">&nbsp;</td>
        <td width="43%" align="center"><font face="helvetica" size="9"><b>ENTREGADO POR:</b></font></td>
        <td width="43%" align="center"><font face="helvetica" size="9"><b>RECIBIDO POR:</b></font></td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="9"><b>Nombre:</b></font></td>
        <td width="43%" align="center"><font face="helvetica" size="9"><b>NOMBRE:</b> {{ $datos['entregado_por'] ?: '' }}</font></td>
        <td width="43%"><font face="helvetica" size="9"><b>NOMBRE:</b></font></td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="9"><b>Cargo:</b></font></td>
        <td width="43%" align="center"><font face="helvetica" size="9"><b>CARGO:</b> COORD. DE MATERIALES</font></td>
        <td width="43%"><font face="helvetica" size="9"><b>CARGO:</b></font></td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="9"><b>Firma:</b></font></td>
        <td width="43%" height="42">&nbsp;</td>
        <td width="43%" height="42">&nbsp;</td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="9"><b>Fecha:</b></font></td>
        <td width="43%" align="center"><font face="helvetica" size="9">{{ $datos['fecha'] }}</font></td>
        <td width="43%">&nbsp;</td>
    </tr>
</table>

{{-- ── Vehículo / Chofer: 2 cuadros pegados side-by-side ──
     ANTES eran 2 tablas anidadas dentro de un wrapper <table border=0> con dos
     <td width=50%>. TCPDF no alinea bien los bordes de tablas anidadas con la
     tabla padre (border del child se dibuja DENTRO del td del wrapper, lo que
     desplaza la caja unos puntos a la derecha del borde izquierdo de la tabla
     de items que viene arriba) -> se veía "metido hacia adentro" por la izquierda.

     AHORA: UNA sola tabla de 4 columnas (14% | 36% | 14% | 36% = 100%). El
     título de cada cuadro va con colspan=2 (50% = 14% + 36%) y de paso la
     columna divisoria central se ve como un borde continuo entre ambas mitades.
     Una sola tabla con widths explicitos en todos los <td> garantiza que el
     borde izquierdo cae EXACTO en la misma X que la tabla de items. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    {{-- Mismo gris #D9D9D9 + tamaño 8pt unificado con la tabla de items. --}}
    <tr bgcolor="#D9D9D9">
        <td width="50%" colspan="2" align="center"><font face="helvetica" size="8" color="#000000"><b>DATOS DEL VEHICULO</b></font></td>
        <td width="50%" colspan="2" align="center"><font face="helvetica" size="8" color="#000000"><b>DATOS DEL CHOFER</b></font></td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="8"><b>VEHICULO:</b></font></td>
        <td width="36%">&nbsp;</td>
        <td width="14%"><font face="helvetica" size="8"><b>NOMBRE:</b></font></td>
        <td width="36%">&nbsp;</td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="8"><b>PLACA:</b></font></td>
        <td width="36%">&nbsp;</td>
        <td width="14%"><font face="helvetica" size="8"><b>CEDULA:</b></font></td>
        <td width="36%">&nbsp;</td>
    </tr>
    <tr>
        <td width="14%"><font face="helvetica" size="8"><b>EMPRESA:</b></font></td>
        <td width="36%"><font face="helvetica" size="8">Constructora Vidalsa 27, C.A.</font></td>
        <td width="14%"><font face="helvetica" size="8"><b>FIRMA:</b></font></td>
        <td width="36%">&nbsp;</td>
    </tr>
</table>
