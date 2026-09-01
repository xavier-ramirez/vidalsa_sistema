{{--
    Cuerpo del PDF "Nota de Entrega de Materiales" — formato HORIZONTAL
    (Almacen::FORMATO_NOTA_HORIZONTAL): hoja A4 ACOSTADA.

    Replica el formulario fisico "CONTROL DE SALIDA" del almacen, estandarizado con el resto
    del sistema: lleva el MISMO cabezote oficial VID-FO-GEN-019 que el formato vertical
    (logo | titulo | sello con N° de Nota, CODIGO, REV y PAG. X DE Y — lo dibuja
    NotaEntregaPDF::Header(), que se adapta solo al ancho de la hoja acostada), las mismas
    convenciones de tabla y la misma tipografia. Lo que cambia respecto al vertical:

      • FECHA y HORA del despacho en el SELLO del cabezote (el vertical las lleva dentro del
        cuerpo, en "FECHA DE ENTREGA", y no imprime la hora). Las estampa
        NotaEntregaPDF::Header a partir de la propiedad $fechaHora, que solo se rellena para
        este formato — por eso esta vista NO usa $datos['fecha'] ni $datos['hora'].
      • Columna DESTINO en la tabla de items.
      • CINCO bloques de firma (ENTREGADO · SOPORTADO · SOPORTADO · RECIBIDO · SEGURIDAD)
        en vez de los dos del vertical, cada uno con NOMBRE / CARGO / CEDULA / FIRMA.

    Convenciones TCPDF (identicas al formato vertical, ver nota_entrega_pdf):
      • Atributos HTML clasicos (border/width/align/cellpadding) en vez de CSS.
      • width EXPLICITO en TODOS los <td> de TODAS las filas — sin eso TCPDF recalcula
        por contenido y los bordes verticales no alinean entre tablas.
      • face="helvetica" explicito en cada <font> (TCPDF no trae Arial; Helvetica es su
        equivalente visual) para que ningun fragmento herede otra fuente.
      • Gris de cabeceras #D9D9D9, igual que el vertical.

    Variables disponibles:
      - $datos:     mismas claves que el vertical + 'hora'.
      - $movs:      Collection<MovimientoInventario> con {producto, frente}.
      - $firmantes: los 5 bloques de firma que devuelve Almacen::firmantesNota().
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';

    // Filas de la tabla de items: se rellena con filas vacias hasta esta cantidad para que la
    // nota luzca como el formulario en fisico.
    //
    // 12 es el MAXIMO MEDIDO que mantiene la nota en UNA sola hoja acostada junto con el
    // cabezote oficial, Observaciones, Vehiculo/Chofer y las 5 firmas (con 13 la fila FIRMA
    // se va a una 2.ª pagina). El formulario en fisico trae 13, pero ese no lleva el sello
    // VID-FO-GEN-019 del cabezote; con el sello no dan los 154 mm utiles de alto que tiene
    // la hoja acostada (210 de A4 menos el cabezote hasta 40 mm y el margen inferior de 16),
    // contra ~241 mm de la de pie — por eso cabe bastante menos que las 20 del vertical.
    //
    // NO subir este numero sin volver a medir: bajar la altura de Observaciones no compra
    // filas (TCPDF impone un alto minimo de celda por el line-height de la fuente) y el
    // cellpadding tampoco. Se probaron ambas cosas.
    $minFilas = 12;

    // ── DESTINO por item ──────────────────────────────────────────────────────────────
    // El destino general de la nota va ARRIBA, en PROYECTO. Esta columna solo existe para
    // el caso en que una misma nota reparte material a VARIOS frentes: ahi si hace falta
    // decir renglon por renglon a cual va cada cosa. Medido sobre las notas ya emitidas,
    // pasa en el 3% (13 de 434), asi que cuando la nota tiene un solo frente la columna se
    // deja en blanco a proposito: repetir 13 veces el mismo texto que ya esta en PROYECTO
    // no agrega informacion y ensucia la hoja.
    //
    // Cada movimiento guarda su propio ID_FRENTE, asi que el dato ya existe: no hay que
    // pedirlo ni configurarlo. `?? null` en vez de `?->` porque $movs tambien puede traer
    // stdClass armados en memoria (vista previa) donde la propiedad podria no venir.
    $destinoPorItem = $movs
        ->map(fn ($m) => ($m->frente ?? null)?->NOMBRE_FRENTE)
        ->filter()
        ->unique()
        ->count() > 1;
@endphp

{{-- ── Bloque de datos de la nota ────────────────────────────────────────────────────
     Grid de 4 columnas: 11% | 39% | 11% | 39%  (suma 100%), iguales en las dos filas para
     que los bordes verticales queden alineados. Las etiquetas caben todas en el 11% (~30 mm)
     sin partirse en dos lineas.

     ORDEN: primero DE DONDE sale (ALMACEN) y despues PARA DONDE va (PROYECTO) — es el orden
     en que se lee un despacho.

     Este formato NO imprime CONTRATO Nº ni RQ N° (el vertical si imprime los dos): son datos
     de la contratacion y del pedido, no del despacho fisico que esta hoja controla.

     FECHA y HORA tampoco van aqui: se imprimen en el SELLO del cabezote, junto al N° de Nota
     (ver NotaEntregaPDF::Header). Ahi es donde se busca el "cuando" de un documento y no se
     repite dos veces en la misma hoja. OJO: la fila "FECHA EMIS" del sello es otra cosa — es
     la fecha en que se emitio el FORMULARIO (01/10/19), fija, no la de esta nota.

     Los datos que este formato no imprime NO se pierden: siguen guardados en el movimiento y
     el formato vertical los sigue imprimiendo. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td width="11%"><font face="helvetica" size="8"><b>ALMACEN:</b></font></td>
        <td width="39%"><font face="helvetica" size="8"><b>{{ $datos['almacen'] ?: '' }}</b></font></td>
        <td width="11%"><font face="helvetica" size="8"><b>PROYECTO:</b></font></td>
        <td width="39%"><font face="helvetica" size="8"><b>{{ $datos['proyecto'] ?: '' }}</b></font></td>
    </tr>
    <tr>
        <td width="11%"><font face="helvetica" size="8"><b>SOLICITANTE:</b></font></td>
        <td width="39%"><font face="helvetica" size="8">{{ $datos['solicitante'] ?: '' }}</font></td>
        <td width="11%"><font face="helvetica" size="8"><b>DEPARTAMENTO:</b></font></td>
        <td width="39%"><font face="helvetica" size="8">{{ $datos['departamento'] ?: '' }}</font></td>
    </tr>
</table>

{{-- ── Tabla de items: N° | DESCRIPCION | SERIAL/CODIGO | CANTIDAD | UND | DESTINO ──
     Anchos: 4% | 40% | 13% | 8% | 6% | 29% (= 100%). En hoja acostada el 40% de
     DESCRIPCION son ~111 mm, mas que el 62% de la hoja de pie (~118 mm es casi igual),
     asi que los nombres largos de producto siguen entrando en una linea.
     <thead> se re-imprime solo si la tabla se parte de pagina (TCPDF lo maneja). --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <thead>
        <tr bgcolor="#D9D9D9">
            <td width="4%"  align="center"><font face="helvetica" size="8"><b>N°</b></font></td>
            <td width="40%" align="center"><font face="helvetica" size="8"><b>DESCRIPCION</b></font></td>
            <td width="13%" align="center"><font face="helvetica" size="8"><b>SERIAL / CODIGO</b></font></td>
            <td width="8%"  align="center"><font face="helvetica" size="8"><b>CANTIDAD</b></font></td>
            <td width="6%"  align="center"><font face="helvetica" size="8"><b>UND</b></font></td>
            <td width="29%" align="center"><font face="helvetica" size="8"><b>DESTINO</b></font></td>
        </tr>
    </thead>
    <tbody>
        @foreach($movs as $i => $m)
            @php
                // Mismo criterio que el vertical: si al entregar se eligio un nº de parte
                // (filtros), sale junto a la descripcion; SERIAL/CODIGO muestra SIEMPRE el
                // codigo del producto.
                $np      = $m->NUMERO_PARTE ?? null;
                $destino = $destinoPorItem ? (($m->frente ?? null)?->NOMBRE_FRENTE ?? '') : '';
            @endphp
            <tr>
                <td width="4%"  align="center"><font face="helvetica" size="8">{{ $i + 1 }}</font></td>
                <td width="40%"><font face="helvetica" size="8">{{ $m->producto?->NOMBRE ?? '' }}@if($np) &nbsp;—&nbsp; {{ $np }}@endif</font></td>
                <td width="13%" align="center"><font face="helvetica" size="8">{{ $m->producto?->CODIGO ?? '' }}</font></td>
                <td width="8%"  align="center"><font face="helvetica" size="8">{{ $fmt($m->CANTIDAD) }}</font></td>
                <td width="6%"  align="center"><font face="helvetica" size="8">{{ $m->producto?->UM ?? '' }}</font></td>
                <td width="29%"><font face="helvetica" size="8">{{ $destino }}</font></td>
            </tr>
        @endforeach
        {{-- Filas de relleno hasta $minFilas. El <font> explicito en cada celda vacia es
             necesario: sin el, el &nbsp; hereda el font del documento (9.5pt) y la fila
             vacia queda mas alta que las llenas (8pt). --}}
        @for($j = $movs->count(); $j < $minFilas; $j++)
            <tr>
                <td width="4%"  align="center"><font face="helvetica" size="8">{{ $j + 1 }}</font></td>
                <td width="40%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="13%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="8%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="6%"><font face="helvetica" size="8">&nbsp;</font></td>
                <td width="29%"><font face="helvetica" size="8">&nbsp;</font></td>
            </tr>
        @endfor
    </tbody>
</table>

{{-- ── Observaciones ──
     UNA sola celda con la etiqueta DENTRO de la caja, igual que el formulario en fisico
     (el vertical usa dos filas: etiqueta arriba, caja abajo). No es solo estetica: la fila
     que se ahorra es la que permite pasar de 11 a 12 renglones de items.
     TCPDF interpreta el atributo HTML height como pixeles a 72dpi: height="34" ≈ 12 mm,
     espacio para 2 lineas manuscritas. Es mas bajo que los 42 del vertical porque la hoja
     acostada tiene ~87 mm menos de alto util y ese aire se necesita para las 5 firmas. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td width="100%" height="34"><font face="helvetica" size="8"><b>OBSERVACIONES:</b> {{ $datos['motivo'] ?: '' }}</font></td>
    </tr>
</table>

@include('admin.almacen.partials.nota_vehiculo_chofer', ['wLabel' => 12, 'wValor' => 38])

{{-- ── Firmas: 5 bloques en columnas de 20% ──
     Es lo que separa este formato del vertical (que lleva solo ENTREGADO POR / RECIBIDO POR):
     la salida de almacen la firman mas personas.

     UNA sola tabla de 5 columnas x 5 filas en vez de 5 tablas anidadas: TCPDF dibuja el borde
     de una tabla hija DENTRO del td del wrapper y la caja queda corrida respecto a la tabla de
     arriba (el mismo motivo esta explicado a fondo en partials/nota_vehiculo_chofer). Ademas,
     una sola tabla garantiza que las 4 filas de cada bloque queden a la misma altura entre
     columnas.

     Los nombres NO se escriben aqui: salen de Almacen::firmantesNota(), que es el punto
     unico donde se decide quien va pre-impreso en cada rol. Los que ese metodo devuelve
     vacios quedan como raya en blanco para llenar a mano. --}}
<table border="1" cellpadding="2" cellspacing="0" width="100%">
    <tr bgcolor="#D9D9D9">
        @foreach($firmantes as $f)
            <td width="20%" align="center"><font face="helvetica" size="8"><b>{{ $f['rol'] }}</b></font></td>
        @endforeach
    </tr>
    <tr>
        @foreach($firmantes as $f)
            <td width="20%"><font face="helvetica" size="7"><b>NOMBRE:</b> {{ $f['nombre'] }}</font></td>
        @endforeach
    </tr>
    <tr>
        @foreach($firmantes as $f)
            <td width="20%"><font face="helvetica" size="7"><b>CARGO:</b> {{ $f['cargo'] }}</font></td>
        @endforeach
    </tr>
    <tr>
        @foreach($firmantes as $f)
            <td width="20%"><font face="helvetica" size="7"><b>CEDULA:</b> {{ $f['cedula'] }}</font></td>
        @endforeach
    </tr>
    {{-- Fila de la firma manuscrita: height=24 (~8.5 mm), el mismo alto que usa el vertical. --}}
    <tr>
        @foreach($firmantes as $f)
            <td width="20%" height="24"><font face="helvetica" size="7"><b>FIRMA:</b></font></td>
        @endforeach
    </tr>
</table>
