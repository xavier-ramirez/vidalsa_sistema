<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
</head>

<body>

    {{-- Titulo "ACTA DE TRASLADO DE EQUIPOS" y N° de operacion ahora viven en
         el cabezote (ActaTrasladoPDF::Header → casilleros central y "Codigo:").
         El body arranca directo con el parrafo introductorio. --}}
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

        // Renglon "Lugar, fecha" (estilo "MATURIN 26/03/2026" del formato Word):
        // usa el campo ZONA del frente de ORIGEN — texto libre que el usuario llena
        // en el formulario de Frentes (ej: "MATURÍN"). Si el frente aún no tiene
        // ZONA cargada, cae al NOMBRE del frente para no dejarlo vacío.
        $lugarOrigen = trim($frenteOrigen->ZONA ?? '');
        if ($lugarOrigen === '') {
            $lugarOrigen = trim($frenteOrigen->NOMBRE_FRENTE ?? '');
        }
    @endphp

    <!-- ===================== LUGAR Y FECHA ===================== -->
    @if($lugarOrigen !== '')
    <!-- Separador Encabezado/Lugar (14px) — aire entre el cabezote y el renglon -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="14">&nbsp;</td></tr></table>
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="right" style="font-size: 10pt; font-weight: bold;">{{ strtoupper($lugarOrigen) }}, {{ $fechaActa }}</td>
        </tr>
    </table>
    <!-- Separador Lugar/Cuerpo (16px) — aire entre el renglon y el parrafo -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="16">&nbsp;</td></tr></table>
    @endif

    <!-- ===================== CUERPO DEL TEXTO ===================== -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="justify" style="font-size: 10pt; line-height: 1.5;">Por medio del presente documento, {{ $labelOrigen }}
                <b>{{ strtoupper($frenteOrigen->NOMBRE_FRENTE ?? 'OFICINA PRINCIPAL') }}</b> de la
                CONSTRUCTORA VIDALSA 27, C.A., deja constancia formal del despacho y traslado de los equipos
                detallados a continuación hacia {{ $labelDestino }}
                <b>{{ strtoupper($frenteDestino->NOMBRE_FRENTE ?? 'DESTINO DESCONOCIDO') }}</b>@if($ubicacionDestino), ubicado en
                {{ strtoupper($ubicacionDestino) }}@endif.
            </td>
        </tr>
    </table>

    <!-- Separador Cuerpo / Tabla (4px) -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td height="4">&nbsp;</td>
        </tr>
    </table>

    {{-- ===================== TABLA DE EQUIPOS =====================
     Renderizada NATIVAMENTE con TCPDF::Cell() en el controller (no HTML).
     Razon: el renderizador HTML de TCPDF re-calcula anchos del thead repetido
     al saltar de pagina, lo que causa desalineacion en tablas largas. La API
     nativa Cell() dibuja en mm exactos con coordenadas fijas, garantizando
     que el header de la pagina 2+ caiga exacto sobre las columnas del tbody.
     =============================================================== --}}
    <!--EQUIPOS_TABLE_PLACEHOLDER-->

    <!-- ===================== BLOQUE COMPLETO DE FIRMAS =====================
     nobr="true" en la tabla maestra: garantiza que todo el bloque de firmas
     NUNCA quede partido entre dos páginas. TCPDF lo mueve completo a la siguiente.

     ORDEN DE FIRMAS:
       RESP_1 → SOLICITADO  (Coord. Mecánica Liviana o Pesada según equipo)
       RESP_2 → ELABORADO   (Dpto. Transporte y Logística — solo Patio Maturín)
       RESP_3 → REVISADO    (Sub-Gerente — solo Patio Maturín)
       RESP_4 → APROBADO    (Gerente)
       + RECIBIDO POR (destino, siempre al final)

     PATIO MATURÍN (frente origen con PATIO/SEDE/TALLER/ALMACEN en el nombre):
       Muestra grid 2×2: [Solicitado | Elaborado] / [Revisado | Aprobado] + Recibido
     OTROS PROYECTOS:
       Muestra máximo 2 firmas + Recibido
-->
    @php
        // Firmas ya resueltas en el controller (MovilizacionController::extractFirmasActa
        // o el override manual de la vista previa) — FUENTE ÚNICA DE VERDAD. Aquí sólo
        // se renderizan; el layout (1 firma / grid 2×2 de Patio / otros) sigue igual.
        $firmasList = $firmas ?? [];
        $totalFirmas = count($firmasList);

        // ── Detección de Patio Maturín (define el layout del bloque de firmas) ──
        $isPatio = $isResguardoOrigen;
    @endphp

    <table width="100%" border="0" cellpadding="0" cellspacing="0" nobr="true">

        <!-- Separador Tabla / Firmas (20px) dentro del bloque nobr -->
        <tr>
            <td colspan="3" height="20">&nbsp;</td>
        </tr>

        @if($totalFirmas === 0)
            {{-- ── Sin responsables configurados → solo Recibido Por ── --}}
            <tr>
                <td colspan="3" align="center">
                    <table width="40%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="35">&nbsp;</td></tr>
                        <tr>
                            <td>
                                <table width="100%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ____________________</td></tr>
                                    <tr><td height="1">&nbsp;</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ____________________</td></tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

        @elseif($totalFirmas === 1)
            {{-- ── 1 firma: izquierda | RECIBIDO POR derecha ── --}}
            @php $f = $firmasList[0]; @endphp
            <tr>
                {{-- Firma única (izquierda) --}}
                <td width="45%" align="center" valign="bottom">
                    <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>{{ $f['label'] }}</b></td></tr>
                        <tr><td height="30">&nbsp;</td></tr>
                        <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                        <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($f['car']) }}</b></td></tr>
                        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($f['nom']) }}</td></tr>
                        <tr>
                            <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                @if(!empty($f['ced']))
                                    @php
                                        $cedNum = preg_replace('/[^0-9]/', '', $f['ced']);
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

                {{-- Espacio central --}}
                <td width="10%"></td>

                {{-- RECIBIDO POR (derecha) --}}
                <td width="45%" align="center" valign="bottom">
                    <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="25">&nbsp;</td></tr>
                        <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ____________________</td></tr>
                        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ____________________</td></tr>
                    </table>
                </td>
            </tr>

        @elseif($isPatio && $totalFirmas >= 3)
            {{-- ════════════════════════════════════════════════════════════
                 PATIO MATURÍN — Grid 2×2 con roles diferenciados:
                 Fila 1:  SOLICITADO  |  ELABORADO
                 Fila 2:  REVISADO    |  APROBADO
                 Fila 3:  (centrado)     RECIBIDO POR
                 ════════════════════════════════════════════════════════════ --}}
            @php
                // Grid 2×2 construido desde la lista secuencial filtrada.
                // Posición 0→SOLICITADO, 1→ELABORADO, 2→REVISADO, 3→APROBADO.
                $filaA = [$firmasList[0] ?? null, $firmasList[1] ?? null];
                $filaB = [$firmasList[2] ?? null, $firmasList[3] ?? null];
            @endphp

            {{-- Fila 1 y Fila 2 del grid --}}
            @foreach([$filaA, $filaB] as $fila)
                <tr>
                    @foreach($fila as $f)
                        <td width="45%" align="center" valign="bottom">
                            @if($f)
                                <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td align="center" style="font-size: 9pt;"><b>{{ $f['label'] }}</b></td></tr>
                                    <tr><td height="30">&nbsp;</td></tr>
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($f['car']) }}</b></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($f['nom']) }}</td></tr>
                                    <tr>
                                        <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                            @if(!empty($f['ced']))
                                                @php
                                                    $cedNum = preg_replace('/[^0-9]/', '', $f['ced']);
                                                    $cedFmt = strrev(implode('.', str_split(strrev($cedNum), 3)));
                                                @endphp
                                                C.I.: {{ $cedFmt }}
                                            @else
                                                C.I.: _______________
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                        @if($loop->first)
                            <td width="10%"></td>
                        @endif
                    @endforeach
                </tr>
                <tr><td colspan="3" height="30">&nbsp;</td></tr>
            @endforeach

            {{-- Fila 3 opcional: 5ta firma centrada (cuando totalFirmas = 5) --}}
            @if(isset($firmasList[4]))
                @php $f5 = $firmasList[4]; @endphp
                <tr>
                    <td colspan="3" align="center">
                        <table width="40%" align="center" border="0" cellpadding="0" cellspacing="0">
                            <tr><td align="center" style="font-size: 9pt;"><b>{{ $f5['label'] }}</b></td></tr>
                            <tr><td height="30">&nbsp;</td></tr>
                            <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                            <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($f5['car']) }}</b></td></tr>
                            <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($f5['nom']) }}</td></tr>
                            <tr>
                                <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                    @if(!empty($f5['ced']))
                                        @php
                                            $cedNum5 = preg_replace('/[^0-9]/', '', $f5['ced']);
                                            $cedFmt5 = strrev(implode('.', str_split(strrev($cedNum5), 3)));
                                        @endphp
                                        C.I.: {{ $cedFmt5 }}
                                    @else
                                        C.I.: _______________
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr><td colspan="3" height="30">&nbsp;</td></tr>
            @endif

            {{-- Fila siguiente: RECIBIDO POR centrado --}}
            <tr>
                <td colspan="3" align="center">
                    <table width="40%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="25">&nbsp;</td></tr>
                        <tr>
                            <td align="center">
                                <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ____________________</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ____________________</td></tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

        @else
            {{-- ════════════════════════════════════════════════════════════
                 OTROS PROYECTOS (no Patio) o Patio con menos de 3 firmas:
                 Máximo 2 firmas en una fila + RECIBIDO POR centrado abajo.
                 ════════════════════════════════════════════════════════════ --}}
            @php
                $firmasOtros = array_slice($firmasList, 0, 2);
                $rowsOtros   = array_chunk($firmasOtros, 2);
            @endphp

            @foreach($rowsOtros as $row)
                <tr>
                    @foreach($row as $f)
                        <td width="45%" align="center" valign="bottom">
                            <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                <tr><td align="center" style="font-size: 9pt;"><b>{{ $f['label'] }}</b></td></tr>
                                <tr><td height="30">&nbsp;</td></tr>
                                <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                <tr><td align="center" style="font-size: 8pt; line-height: 1.5;"><b>{{ strtoupper($f['car']) }}</b></td></tr>
                                <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">{{ strtoupper($f['nom']) }}</td></tr>
                                <tr>
                                    <td align="center" style="font-size: 8pt; line-height: 1.5; color: #333;">
                                        @if(!empty($f['ced']))
                                            @php
                                                $cedNum = preg_replace('/[^0-9]/', '', $f['ced']);
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

                        @if($loop->first && count($row) > 1)
                            <td width="10%"></td>
                        @elseif($loop->first && count($row) == 1)
                            <td width="55%" colspan="2"></td>
                        @endif
                    @endforeach
                </tr>
                <tr><td colspan="3" height="30">&nbsp;</td></tr>
            @endforeach

            {{-- RECIBIDO POR centrado debajo --}}
            <tr>
                <td colspan="3" align="center">
                    <table width="40%" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td align="center" style="font-size: 9pt;"><b>RECIBIDO POR (DESTINO):</b></td></tr>
                        <tr><td height="25">&nbsp;</td></tr>
                        <tr>
                            <td align="center">
                                <table width="85%" align="center" border="0" cellpadding="0" cellspacing="0">
                                    <tr><td style="border-top: 0.5pt solid #000; height: 1px;"></td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ____________________</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ____________________</td></tr>
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