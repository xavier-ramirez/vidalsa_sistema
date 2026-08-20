{{-- ===================== TARJETA DE FIRMA (acta de traslado) =====================
     Fuente ÚNICA del marcado de una casilla de firma. Antes este mismo bloque estaba
     copiado cuatro veces en acta_traslado_pdf.blade.php (una por cada rama de layout),
     y el "RECIBIDO POR" tenía además su propia copia en tres de ellas.

     Recibe:
       $f     → array de firma ('label','car','nom','ced')  ó  ['recibido' => true]
       $ancho → ancho de la tabla interna: '85%' cuando va en pareja, '40%' cuando va sola
       $fmtCed viene heredado de la vista padre (@include comparte las variables).

     IMPORTANTE — las dos variantes dejan la MISMA cantidad de renglones bajo la línea
     (3). Como las celdas de la fila usan valign="bottom", eso hace que las líneas de
     firma queden a la misma altura cuando una tarjeta va al lado de la otra.
=============================================================================== --}}
@php $esRecibido = ! empty($f['recibido']); @endphp

<table width="{{ $ancho }}" align="center" border="0" cellpadding="0" cellspacing="0">
    {{-- Todo en UNA linea: TCPDF convierte los saltos de linea del HTML en espacios,
         y un espacio de mas descentra la etiqueta. --}}
    <tr><td align="center" style="font-size: 9pt;"><b>{{ $esRecibido ? 'RECIBIDO POR (DESTINO):' : $f['label'] }}</b></td></tr>
    <tr><td height="30">&nbsp;</td></tr>
    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>

    @if($esRecibido)
        {{-- Se llena a mano en destino: renglones en blanco. --}}
        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ____________________</td></tr>
        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ____________________</td></tr>
        {{-- Renglón vacío: iguala la altura con la tarjeta de firma (3 renglones). --}}
        <tr><td align="center" style="font-size: 8pt; line-height: 1.5;">&nbsp;</td></tr>
    @else
        <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($f['car']) }}</b></td></tr>
        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($f['nom']) }}</td></tr>
        <tr><td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">{{ $fmtCed($f['ced']) }}</td></tr>
    @endif
</table>
