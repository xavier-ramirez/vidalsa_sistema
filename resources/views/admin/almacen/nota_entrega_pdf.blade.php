{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — formato VID-FO-GEN-019.

    Construido con atributos HTML clásicos (border, width, align, valign,
    cellpadding, cellspacing) en vez de CSS — TCPDF tiene soporte completo de
    esos atributos y limitado de CSS, así que el render es más predecible.

    El grosor de las líneas (border=1) lo controla SetLineWidth(0.15) que se
    setea en el controller antes de renderizar — el mismo valor que el Header()
    usa, así las cajas del cuerpo quedan con el mismo grosor que el cabezote.

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

{{-- ── Bloque de datos del proyecto: una tabla con 4 filas ──
     fila 1: PROYECTO  (en itálica como el formato oficial)
     fila 2: CONTRATO Nº
     fila 3: FECHA DE ENTREGA · RQ N° · Solicitante
     fila 4: DEPARTAMENTO
     Grid de 6 columnas: 20% | 20% | 10% | 15% | 13% | 22% --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td width="20%"><font size="9"><b><i>PROYECTO:</i></b></font></td>
        <td colspan="5"><font size="9"><b><i>{{ $datos['proyecto'] ?: '' }}</i></b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>CONTRATO Nº:</b></font></td>
        <td colspan="5"><font size="9">{{ $datos['contrato'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td><font size="9"><b>FECHA DE ENTREGA:</b></font></td>
        <td width="20%"><font size="9">{{ $datos['fecha'] }}</font></td>
        <td width="10%"><font size="9"><b>RQ N°:</b></font></td>
        <td width="15%"><font size="9">{{ $datos['rq'] ?: '' }}</font></td>
        <td width="13%"><font size="9"><b>Solicitante:</b></font></td>
        <td><font size="9">{{ $datos['solicitante'] ?: '' }}</font></td>
    </tr>
    <tr>
        <td><font size="9"><b>DEPARTAMENTO:</b></font></td>
        <td colspan="5"><font size="9">{{ $datos['departamento'] ?: '' }}</font></td>
    </tr>
</table>

{{-- ── Tabla de ítems: ITEM | CANTIDAD | UNIDAD | DESCRIPCIÓN | N° COLADA/SERIAL ──
     IMPORTANTE: el width hay que repetirlo en CADA <td> de TODAS las filas (no solo el
     header). TCPDF en writeHTML no propaga el width del <thead> al <tbody> cuando las
     celdas estan casi vacias — sin esto las columnas se "fusionan" visualmente. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr bgcolor="#C0C0C0">
        <td width="7%"  align="center"><font size="9" color="#000000"><b>ITEM</b></font></td>
        <td width="11%" align="center"><font size="9" color="#000000"><b>CANTIDAD</b></font></td>
        <td width="12%" align="center"><font size="9" color="#000000"><b>UNIDAD</b></font></td>
        <td width="50%" align="center"><font size="9" color="#000000"><b>DESCRIPCIÓN</b></font></td>
        <td width="20%" align="center"><font size="9" color="#000000"><b>N° COLADA / SERIAL</b></font></td>
    </tr>
    @foreach($movs as $i => $m)
        <tr>
            <td width="7%"  align="center"><font size="8">{{ $i + 1 }}</font></td>
            <td width="11%" align="center"><font size="8">{{ $fmt($m->CANTIDAD) }}</font></td>
            <td width="12%" align="center"><font size="8">{{ $m->producto?->UM ?? '' }}</font></td>
            <td width="50%"><font size="8">{{ $m->producto?->NOMBRE ?? '' }}</font></td>
            <td width="20%" align="center"><font size="8">{{ $m->producto?->CODIGO ?? '' }}</font></td>
        </tr>
    @endforeach
    @for($j = $movs->count(); $j < $minFilas; $j++)
        <tr>
            <td width="7%"  align="center"><font size="8">{{ $j + 1 }}</font></td>
            <td width="11%">&nbsp;</td>
            <td width="12%">&nbsp;</td>
            <td width="50%">&nbsp;</td>
            <td width="20%">&nbsp;</td>
        </tr>
    @endfor
</table>

{{-- ── Observaciones: label + caja vacía para escribir ── --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td><font size="9"><b>OBSERVACIONES:</b></font></td>
    </tr>
    <tr>
        <td height="30"><font size="9">{{ $datos['motivo'] ?: ' ' }}</font></td>
    </tr>
</table>

{{-- ── Firmas: label | ENTREGADO POR | RECIBIDO POR ──
     Almacenista del almacén origen va pre-impreso como ENTREGADO POR + cargo
     "COORD. DE MATERIALES" (estándar VIDALSA). RECIBIDO POR en blanco para
     que la persona del proyecto lo rellene a mano al recibir. --}}
<table border="1" cellpadding="3" cellspacing="0" width="100%">
    <tr>
        <td width="14%">&nbsp;</td>
        <td width="43%" align="center"><font size="9"><b>ENTREGADO POR:</b></font></td>
        <td width="43%" align="center"><font size="9"><b>RECIBIDO POR:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Nombre:</b></font></td>
        <td align="center"><font size="9"><b>NOMBRE:</b> {{ $datos['entregado_por'] ?: '' }}</font></td>
        <td><font size="9"><b>NOMBRE:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Cargo:</b></font></td>
        <td align="center"><font size="9"><b>CARGO:</b> COORD. DE MATERIALES</font></td>
        <td><font size="9"><b>CARGO:</b></font></td>
    </tr>
    <tr>
        <td><font size="9"><b>Firma:</b></font></td>
        <td height="18">&nbsp;</td>
        <td height="18">&nbsp;</td>
    </tr>
    <tr>
        <td><font size="9"><b>Fecha:</b></font></td>
        <td align="center"><font size="9">{{ $datos['fecha'] }}</font></td>
        <td>&nbsp;</td>
    </tr>
</table>

{{-- ── Vehículo / Chofer: 2 cuadros pegados side-by-side (50% + 50% = 100%).
     Antes había un gap de 4% entre los dos cuadros; se eliminó para que las
     cajas queden flush — donde termina la de Vehículo, empieza la de Chofer.
     Etiquetas a 28% del ancho del cuadro. Font 8pt. --}}
<table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="50%" valign="top">
            <table border="1" cellpadding="2" cellspacing="0" width="100%">
                <tr bgcolor="#C0C0C0">
                    <td colspan="2" align="center"><font size="9" color="#000000"><b>DATOS DEL VEHICULO</b></font></td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>VEHICULO:</b></font></td>
                    <td width="72%">&nbsp;</td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>PLACA:</b></font></td>
                    <td width="72%">&nbsp;</td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>EMPRESA:</b></font></td>
                    <td width="72%"><font size="8">Constructora Vidalsa 27, C.A.</font></td>
                </tr>
            </table>
        </td>
        <td width="50%" valign="top">
            <table border="1" cellpadding="2" cellspacing="0" width="100%">
                <tr bgcolor="#C0C0C0">
                    <td colspan="2" align="center"><font size="9" color="#000000"><b>DATOS DEL CHOFER</b></font></td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>NOMBRE:</b></font></td>
                    <td width="72%">&nbsp;</td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>CEDULA:</b></font></td>
                    <td width="72%">&nbsp;</td>
                </tr>
                <tr>
                    <td width="28%"><font size="8"><b>FIRMA:</b></font></td>
                    <td width="72%">&nbsp;</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
