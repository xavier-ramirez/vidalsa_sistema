{{--
    Bloque "DEVOLUCIONES" al pie de la Nota de Entrega (PDF), en el vertical y en el
    horizontal. SOLO sale cuando esa nota tiene material devuelto: una nota sin
    devoluciones se imprime exactamente igual que siempre.

    El cuerpo de la nota NO se toca: es el papel que se firmó y su formato está congelado
    por nota (FORMATO_NOTA). Lo devuelto se añade aparte, con su fecha, para que el papel
    y el sistema cuenten lo mismo sin reescribir lo firmado.

      - $devoluciones: Collection<MovimientoInventario> (TIPO DEVOLUCION) con su producto.
      - $fmt:          el mismo formateador de cantidades que usa la tabla de ítems.
--}}
@if(($devoluciones ?? collect())->isNotEmpty())
    <br/>
    <table border="0" cellpadding="2" width="100%">
        <tr>
            <td><font face="helvetica" size="8"><b>DEVOLUCIONES REGISTRADAS DE ESTA NOTA</b></font></td>
        </tr>
    </table>
    <table border="1" cellpadding="2" width="100%">
        <thead>
            <tr>
                <td width="14%" align="center"><font face="helvetica" size="8"><b>FECHA</b></font></td>
                <td width="10%" align="center"><font face="helvetica" size="8"><b>CANT.</b></font></td>
                <td width="10%" align="center"><font face="helvetica" size="8"><b>UNIDAD</b></font></td>
                <td width="66%" align="center"><font face="helvetica" size="8"><b>DESCRIPCIÓN / MOTIVO</b></font></td>
            </tr>
        </thead>
        <tbody>
            @foreach($devoluciones as $d)
                <tr>
                    <td width="14%" align="center"><font face="helvetica" size="8">{{ optional($d->FECHA)->format('d/m/Y') }}</font></td>
                    <td width="10%" align="center"><font face="helvetica" size="8">{{ $fmt($d->CANTIDAD) }}</font></td>
                    <td width="10%" align="center"><font face="helvetica" size="8">{{ $d->producto?->UM ?? '' }}</font></td>
                    <td width="66%"><font face="helvetica" size="8">{{ $d->producto?->NOMBRE ?? '' }}@if(trim((string) $d->MOTIVO) !== '') &nbsp;—&nbsp; {{ $d->MOTIVO }}@endif</font></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
