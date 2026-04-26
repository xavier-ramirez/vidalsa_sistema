<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
</head>

<body>

    <!-- ===================== N° OPERACIÓN ===================== -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="right" style="font-size: 9pt;">
                <b>N° OPERACIÓN: {{ str_pad($movilizacion->CODIGO_CONTROL ?? 0, 6, '0', STR_PAD_LEFT) }}</b>
            </td>
        </tr>
    </table>

    <!-- Separador N° Operación / Título (20px) -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td height="20">&nbsp;</td>
        </tr>
    </table>

    <!-- ===================== TÍTULO ===================== -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="font-size: 15pt;">
                <b>ACTA DE TRASLADO</b>
            </td>
        </tr>
    </table>

    <!-- Separador Título / Cuerpo (10px) -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td height="10">&nbsp;</td>
        </tr>
    </table>
    @php
        $tipoOrigen = strtoupper($frenteOrigen->TIPO_FRENTE ?? 'OPERACION');
        $nombreOrigen = strtoupper($frenteOrigen->NOMBRE_FRENTE ?? '');
        $isResguardoOrigen = ($tipoOrigen === 'RESGUARDO') || str_contains($nombreOrigen, 'PATIO') || str_contains($nombreOrigen, 'SEDE') || str_contains($nombreOrigen, 'TALLER') || str_contains($nombreOrigen, 'ALMACEN');
        $labelOrigen = $isResguardoOrigen ? 'el centro de resguardo' : 'el frente de trabajo';

        $tipoDestino = strtoupper($frenteDestino->TIPO_FRENTE ?? 'OPERACION');
        $nombreDestino = strtoupper($frenteDestino->NOMBRE_FRENTE ?? '');
        $isResguardoDestino = ($tipoDestino === 'RESGUARDO') || str_contains($nombreDestino, 'PATIO') || str_contains($nombreDestino, 'SEDE') || str_contains($nombreDestino, 'TALLER') || str_contains($nombreDestino, 'ALMACEN');
        $labelDestino = $isResguardoDestino ? 'el centro de resguardo' : 'el frente de trabajo';

        $ubicacionDestino = trim($frenteDestino->UBICACION ?? '');
    @endphp

    <!-- ===================== CUERPO DEL TEXTO =====================
         IMPORTANTE: TCPDF respeta el whitespace inicial dentro del <td>,
         asi que el texto NO debe tener indentacion ni saltos antes del
         "Por medio..." (eso producia una sangria visible en la primera
         linea del PDF). Mantener todo el bloque al inicio de la columna. -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
<td align="justify" style="font-size: 10pt; line-height: 1.5;">Por medio del presente documento, {{ $labelOrigen }} <b>{{ strtoupper($frenteOrigen->NOMBRE_FRENTE ?? 'OFICINA PRINCIPAL') }}</b> de la CONSTRUCTORA VIDALSA 27, C.A., deja constancia formal del despacho y traslado de los equipos detallados a continuación hacia {{ $labelDestino }} <b>{{ strtoupper($frenteDestino->NOMBRE_FRENTE ?? 'DESTINO DESCONOCIDO') }}</b>@if($ubicacionDestino), ubicado en {{ strtoupper($ubicacionDestino) }}@endif.</td>
        </tr>
    </table>

    <!-- Separador Cuerpo / Tabla (14px) -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td height="14">&nbsp;</td>
        </tr>
    </table>

    <!-- ===================== TABLA DE EQUIPOS =====================
     - Sin nobr en la tabla completa: permite que fluya entre páginas naturalmente
     - nobr="true" en cada fila: evita que UNA fila quede partida a mitad
     - thead: TCPDF repite el encabezado automáticamente en cada página nueva
-->
    <table width="100%" border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
        <thead>
            <tr bgcolor="#e6f2ff">
                <th width="5%" align="center" style="font-size: 8.5pt; font-weight: bold;">N°</th>
                <th width="50%" align="center" style="font-size: 8.5pt; font-weight: bold;">DESCRIPCIÓN / EQUIPO</th>
                <th width="20%" align="center" style="font-size: 8.5pt; font-weight: bold;">MARCA</th>
                <th width="25%" align="center" style="font-size: 8.5pt; font-weight: bold;">SERIAL / PLACA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($equipos as $index => $item)
                @php
                    // SERIAL / PLACA: prioriza PLACA si existe (vehiculos con
                    // documentacion); fallback a SERIAL_CHASIS. Antes solo
                    // mostraba SERIAL_CHASIS y el header era engañoso.
                    $placa = trim((string) (optional($item->documentacion)->PLACA ?? ''));
                    $serial = trim((string) ($item->SERIAL_CHASIS ?? ''));
                    if ($placa !== '' && strtoupper($placa) !== 'S/P' && strtoupper($placa) !== 'N/A') {
                        $serialPlacaCell = $placa;
                    } elseif ($serial !== '') {
                        $serialPlacaCell = $serial;
                    } else {
                        $serialPlacaCell = '---';
                    }
                @endphp
                <tr nobr="true">
                    <td width="5%" align="center" style="font-size: 8.5pt;">{{ $index + 1 }}</td>
                    <td width="50%" align="center" style="font-size: 8.5pt;">
                        {{ strtoupper($item->tipo->nombre ?? 'SIN TIPO') }}
                    </td>
                    <td width="20%" align="center" style="font-size: 8.5pt;">{{ strtoupper($item->MARCA ?? '---') }}</td>
                    <td width="25%" align="center" style="font-size: 8.5pt;">{{ strtoupper($serialPlacaCell) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- ===================== BLOQUE COMPLETO DE FIRMAS =====================
     nobr="true" en la tabla maestra: garantiza que las 3 firmas (Aprobado 1,
     Aprobado 2 y Recibido) NUNCA queden solas en otra hoja.
     Si no caben en la página actual, TCPDF las mueve completas a la siguiente.
-->
    @php
        $categoriesInActa = $equipos->pluck('CATEGORIA_FLOTA')->unique()->filter()->values()->toArray();

        $responsablesToShow = [];
        for ($i = 1; $i <= 4; $i++) {
            $nomKey = "RESP_{$i}_NOM";
            $carKey = "RESP_{$i}_CAR";
            $equKey = "RESP_{$i}_EQU";
            $cedKey = "RESP_{$i}_CED";

            $nom = trim($frenteOrigen->$nomKey ?? '');
            $car = trim($frenteOrigen->$carKey ?? 'RESPONSABLE');
            $equ = trim($frenteOrigen->$equKey ?? '');
            $ced = trim($frenteOrigen->$cedKey ?? '');

            $isPlaceholder = empty($nom) || 
                             strtolower($nom) === 'nombre y apellido' ||
                             strtolower($nom) === 'por definir' ||
                             strtolower($nom) === 'n/a';

            if (!$isPlaceholder) {
                if ($equ) {
                    if (in_array($equ, $categoriesInActa)) {
                        $responsablesToShow[] = ['nom' => $nom, 'car' => $car, 'ced' => $ced];
                    }
                } else {
                    $responsablesToShow[] = ['nom' => $nom, 'car' => $car, 'ced' => $ced];
                }
            }
        }

        // Agrupar en filas de a 2
        $rows = array_chunk($responsablesToShow, 2);
    @endphp

    @php $totalResp = count($responsablesToShow); @endphp

    <table width="100%" border="0" cellpadding="0" cellspacing="0" nobr="true">

        <!-- Separador Tabla / Firmas (20px) dentro del bloque nobr -->
        <tr>
            <td colspan="3" height="20">&nbsp;</td>
        </tr>

        @if($totalResp === 1)
            {{-- ── Caso especial: 1 responsable → APROBADO izquierda | RECIBIDO derecha ── --}}
            @php $resp = $responsablesToShow[0]; @endphp
            <tr>
                {{-- APROBADO POR (izquierda) --}}
                <td width="45%" align="center" valign="bottom">
                    <table width="100%" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>APROBADO POR (ORIGEN):</b></td></tr>
                        <tr><td height="35">&nbsp;</td></tr>
                        <tr>
                            <td>
                                <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($resp['car']) }}</b></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($resp['nom']) }}</td></tr>
                                    <tr>
                                        <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                            @if(!empty($resp['ced']))
                                                @php
                                                    $cedNum = preg_replace('/[^0-9]/', '', $resp['ced']);
                                                    $cedFmt = strrev(implode('.', str_split(strrev($cedNum), 3)));
                                                @endphp
                                                C.I.: {{ $cedFmt }}
                                            @else
                                                C.I.: _______________
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>

                {{-- Espacio central --}}
                <td width="10%"></td>

                {{-- RECIBIDO POR (derecha) --}}
                <td width="45%" align="center" valign="bottom">
                    <table width="100%" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="35">&nbsp;</td></tr>
                        <tr>
                            <td>
                                <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                                    <tr><td height="1">&nbsp;</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

        @else
            {{-- ── Caso normal: 2+ responsables → loop de APROBADO POR ── --}}
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $index => $resp)
                        <td width="45%" align="center" valign="bottom">
                            <table width="100%" border="0" cellpadding="0" cellspacing="0">
                                <tr><td align="center" style="font-size: 9pt;"><b>APROBADO POR (ORIGEN):</b></td></tr>
                                <tr><td height="35">&nbsp;</td></tr>
                                <tr>
                                    <td>
                                        <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                            <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                            <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($resp['car']) }}</b></td></tr>
                                            <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($resp['nom']) }}</td></tr>
                                            <tr>
                                                <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                                    @if(!empty($resp['ced']))
                                                        @php
                                                            $cedNum = preg_replace('/[^0-9]/', '', $resp['ced']);
                                                            $cedFmt = strrev(implode('.', str_split(strrev($cedNum), 3)));
                                                        @endphp
                                                        C.I.: {{ $cedFmt }}
                                                    @else
                                                        C.I.: _______________
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>

                        @if($loop->first && count($row) > 1)
                            <td width="10%"></td>
                        @elseif($loop->first && count($row) == 1)
                            <td width="55%" colspan="2"></td>
                        @endif
                    @endforeach
                </tr>
                <tr><td colspan="3" height="30">&nbsp;</td></tr>
            @endforeach

            {{-- ── RECIBIDO POR centrado debajo (2+ responsables) ── --}}
            <tr>
                <td colspan="3" align="center">
                    <table width="40%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="35">&nbsp;</td></tr>
                        <tr>
                            <td align="center">
                                <table width="100%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                                    <tr><td height="1">&nbsp;</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

    </table>
    <!-- Fin bloque nobr firmas -->


</body>

</html>