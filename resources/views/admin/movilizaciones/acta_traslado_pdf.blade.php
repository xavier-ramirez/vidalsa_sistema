<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: helvetica;
            font-size: 10pt;
            color: #000;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        /* Misma estrategia que reports/documentos_vencidos_pdf.blade.php (que NO se desalinea):
           - CSS-driven (no inline align/style en cada celda).
           - border-collapse: collapse en la tabla.
           - page-break-inside: avoid en <tr> obliga a TCPDF a mantener cada fila
             entera en una pagina, evitando los recalculos de ancho que causan
             que el thead repetido se desalinee con el tbody continuado. */
        .equipos-table {
            width: 100%;
            border-collapse: collapse;
        }
        .equipos-table th {
            background-color: #e6f2ff;
            border: 0.5pt solid #000;
            font-weight: bold;
            text-align: center;
            padding: 5px;
            font-size: 8.5pt;
        }
        .equipos-table td {
            border: 0.5pt solid #000;
            text-align: center;
            padding: 5px;
            font-size: 8.5pt;
        }
        .equipos-table tr {
            page-break-inside: avoid;
        }
    </style>
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

    <!-- Separador Cuerpo / Tabla (14px) -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td height="14">&nbsp;</td>
        </tr>
    </table>

    <!-- ===================== TABLA DE EQUIPOS =====================
     Mismo patron que reports/documentos_vencidos_pdf (que se renderiza correcto
     en multiples paginas). El secreto:
       1) <style> CSS con clase .equipos-table — NO inline styles por celda.
       2) border-collapse: collapse en la tabla.
       3) page-break-inside: avoid en <tr> — TCPDF respeta esta regla CSS3 y
          NO parte filas a la mitad. Esto evita los recalculos de ancho que
          desalinean el thead repetido con el tbody continuado.
       4) Anchos en %, pero al no partirse las filas el thead siempre queda
          alineado con el tbody en cada pagina.
       5) thead con cellpadding via CSS coincide con tbody — alturas estables.
    =========================================================== -->
    <table class="equipos-table" cellpadding="4">
        <thead>
            <tr>
                <th width="5%">N°</th>
                <th width="50%">DESCRIPCIÓN / EQUIPO</th>
                <th width="20%">MARCA</th>
                <th width="25%">SERIAL / PLACA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($equipos as $index => $item)
                <tr>
                    <td width="5%">{{ $index + 1 }}</td>
                    <td width="50%">{{ strtoupper($item->tipo->nombre ?? 'SIN TIPO') }}</td>
                    <td width="20%">{{ strtoupper($item->MARCA ?? '---') }}</td>
                    <td width="25%">{{ strtoupper($item->SERIAL_CHASIS ?? '---') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

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
        // ── Categorías de los equipos en el acta ──────────────────────────────
        // Equipos Auxiliares (CATEGORIA_FLOTA null o '') → FLOTA LIVIANA.
        // Operador ?: captura null Y string vacío.
        $categoriesInActa = $equipos->pluck('CATEGORIA_FLOTA')->map(function($cat) {
            return $cat ?: 'FLOTA LIVIANA';
        })->unique()->filter()->values()->toArray();

        if (empty($categoriesInActa)) {
            $categoriesInActa = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        }

        // ── Leer y filtrar responsables (slots 1–4) ───────────────────────────
        // Sólo se incluye un slot si:
        //   a) Tiene nombre real (no placeholder)
        //   b) Su RESP_N_EQU está vacío (aplica a todos) O coincide con la
        //      categoría de los equipos del acta.
        // IMPORTANTE: el array resultante ($firmasList) es plano; las etiquetas
        // se asignan POR ORDEN DE APARICIÓN tras el filtro, NO por slot BD.
        // Esto permite que si RESP_1=Liviana y RESP_2=Pesada, el que pasa el
        // filtro siempre obtenga la etiqueta SOLICITADO correctamente.
        $labelsByResp = [
            1 => 'SOLICITADO:',
            2 => 'SOLICITADO:',
            3 => 'ELABORADO:',
            4 => 'REVISADO:',
            5 => 'APROBADO:'
        ];

        $firmasFiltradas = [];
        for ($i = 1; $i <= 5; $i++) {
            $nom = trim($frenteOrigen->{"RESP_{$i}_NOM"} ?? '');
            $car = trim($frenteOrigen->{"RESP_{$i}_CAR"} ?? 'RESPONSABLE');
            $equ = trim($frenteOrigen->{"RESP_{$i}_EQU"} ?? '');
            $ced = trim($frenteOrigen->{"RESP_{$i}_CED"} ?? '');

            $isPlaceholder = empty($nom)
                || strtolower($nom) === 'nombre y apellido'
                || strtolower($nom) === 'por definir'
                || strtolower($nom) === 'n/a';

            if ($isPlaceholder) continue;

            $pasaFiltro = $equ === '' || in_array($equ, $categoriesInActa);
            if ($pasaFiltro) {
                $firmasFiltradas[] = [
                    'nom' => $nom, 
                    'car' => $car, 
                    'ced' => $ced, 
                    'label' => $labelsByResp[$i]
                ];
            }
        }

        // Ya no asignamos etiquetas secuenciales
        $firmasList = $firmasFiltradas;
        $totalFirmas = count($firmasList);

        // ── Detección de Patio Maturín ─────────────────────────────────────────
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
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                                    <tr><td height="1">&nbsp;</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
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
                        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                        <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
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
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Cédula: ___________________________</td></tr>
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
                                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.5;">Nombre: ___________________________</td></tr>
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