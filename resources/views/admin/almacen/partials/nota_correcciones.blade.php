{{--
    Bloque "CORRECCIONES" al pie de la nota ORIGINAL de una nota corregida
    (?version=original), en rojo, en el vertical y en el horizontal. Dice qué se cambió,
    cuándo, quién y por qué. La nota de hoy (la corregida) no lo lleva: sale limpia.

      - $correcciones: Collection<CorreccionNota> con producto y usuario (vacía = no sale).
      - $fmt:          el mismo formateador de cantidades que usa la tabla de ítems.

    "de 180 a 100" y no con una flecha: la Helvetica de TCPDF no la tiene y sale "?".
--}}
@if(($correcciones ?? collect())->isNotEmpty())
    <br/>
    <table border="0" cellpadding="2" width="100%">
        <tr>
            <td><font face="helvetica" size="8" color="#b91c1c"><b>NOTA CORREGIDA — ESTA ES LA VERSIÓN ORIGINAL. CORRECCIONES:</b></font></td>
        </tr>
    </table>
    <table border="1" cellpadding="2" width="100%">
        <thead>
            <tr>
                <td width="18%" align="center"><font face="helvetica" size="8" color="#b91c1c"><b>FECHA</b></font></td>
                <td width="16%" align="center"><font face="helvetica" size="8" color="#b91c1c"><b>CANT.</b></font></td>
                <td width="66%" align="center"><font face="helvetica" size="8" color="#b91c1c"><b>PRODUCTO / MOTIVO / CORREGIDO POR</b></font></td>
            </tr>
        </thead>
        <tbody>
            @foreach($correcciones as $c)
                <tr>
                    <td width="18%" align="center"><font face="helvetica" size="8" color="#b91c1c">{{ optional($c->created_at)->format('d/m/Y h:i A') }}</font></td>
                    <td width="16%" align="center"><font face="helvetica" size="8" color="#b91c1c">de {{ $fmt($c->CANTIDAD_ANTES) }} a {{ $fmt($c->CANTIDAD_DESPUES) }} {{ $c->producto?->UM ?? '' }}</font></td>
                    <td width="66%"><font face="helvetica" size="8" color="#b91c1c">{{ $c->producto?->NOMBRE ?? '' }}@if(trim((string) $c->MOTIVO) !== '') &nbsp;—&nbsp; {{ $c->MOTIVO }}@endif @if($c->usuario) &nbsp;—&nbsp; {{ $c->usuario->NOMBRE_COMPLETO }}@endif</font></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
