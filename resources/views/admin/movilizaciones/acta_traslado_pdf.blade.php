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

        // Formatea una cédula a "C.I.: 12.345.678" (puntos cada 3 dígitos desde la
        // derecha). Si viene vacía, deja la línea en blanco para rellenar a mano.
        // Centraliza la lógica que antes se repetía en cada bloque de firma.
        $fmtCed = function ($ced) {
            if (empty($ced)) return 'C.I.: _______________';
            $n = preg_replace('/[^0-9]/', '', $ced);
            return 'C.I.: ' . strrev(implode('.', str_split(strrev($n), 3)));
        };
    @endphp

    <!-- ===================== LUGAR Y FECHA ===================== -->
    @if($lugarOrigen !== '')
    <!-- Separador Encabezado/Lugar (38px) — aire entre el cabezote y el renglon -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="38">&nbsp;</td></tr></table>
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="right" style="font-size: 10pt; font-weight: bold;">{{ strtoupper($lugarOrigen) }}, {{ $fechaActa }}</td>
        </tr>
    </table>
    <!-- Separador Lugar/Cuerpo (30px) — aire entre el renglon y el parrafo -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td height="30">&nbsp;</td></tr></table>
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

     LAYOUT — UNA sola rejilla de 2 columnas para todos los casos:
     "RECIBIDO POR" NO es un bloque aparte: es una tarjeta más, siempre la última de
     la lista. Así se acomoda al lado del último firmante en vez de bajarse solo a una
     fila propia. Queda solo (centrado) únicamente cuando el total de tarjetas es
     impar, que es justo cuando no hay nadie a quien ponerle al lado:

       0 firmas + recibido = 1 tarjeta  → [recibido centrado]
       1 firma  + recibido = 2 tarjetas → [f1 | recibido]
       2 firmas + recibido = 3 tarjetas → [f1 | f2] / [recibido centrado]
       3 firmas + recibido = 4 tarjetas → [f1 | f2] / [f3 | recibido]
       4 firmas + recibido = 5 tarjetas → [f1 | f2] / [f3 | f4] / [recibido centrado]

     Antes había cuatro ramas (0 / 1 / >=3 / else) que repetían la misma tarjeta y
     colgaban el "RECIBIDO POR" en fila aparte salvo en el caso de 1 firma. Con 3
     firmantes el recibido bajaba solo y dejaba un hueco vacío al lado del tercero.
     El marcado de la tarjeta vive ahora en partials/tarjeta_firma.blade.php.
-->
    @php
        // Firmas ya resueltas en el controller (MovilizacionController::extractFirmasActa
        // o el override manual de la vista previa) — FUENTE ÚNICA DE VERDAD. Aquí sólo
        // se renderizan; el layout lo decide CUÁNTAS hay (ver el detalle arriba).
        $firmasList = $firmas ?? [];

        // El "RECIBIDO POR" entra a la misma lista como una tarjeta más, al final.
        $tarjetas = $firmasList;
        $tarjetas[] = ['recibido' => true];

        // Rejilla de 2 columnas. Si el total es impar, la última (el recibido) va sola.
        $filasFirmas = array_chunk($tarjetas, 2);
    @endphp

    <table width="100%" border="0" cellpadding="0" cellspacing="0" nobr="true">

        <!-- Separador Tabla / Firmas (20px) dentro del bloque nobr -->
        <tr>
            <td colspan="3" height="20">&nbsp;</td>
        </tr>

        @foreach($filasFirmas as $fila)

            @if(count($fila) === 2)
                {{-- Fila completa: dos tarjetas → 45% | 10% de aire | 45%.
                     valign="bottom" alinea las dos líneas de firma a la misma altura. --}}
                <tr>
                    @foreach($fila as $f)
                        <td width="45%" align="center" valign="bottom">
                            @include('admin.movilizaciones.partials.tarjeta_firma', ['f' => $f, 'ancho' => '85%'])
                        </td>
                        @if($loop->first)
                            <td width="10%"></td>
                        @endif
                    @endforeach
                </tr>
            @else
                {{-- Tarjeta suelta (siempre es el RECIBIDO POR, porque va de última):
                     centrada a todo lo ancho, igual que se veía antes. --}}
                <tr>
                    <td colspan="3" align="center">
                        @include('admin.movilizaciones.partials.tarjeta_firma', ['f' => $fila[0], 'ancho' => '40%'])
                    </td>
                </tr>
            @endif

            {{-- Aire entre filas de firmas — no después de la última. --}}
            @if(! $loop->last)
                <tr><td colspan="3" height="30">&nbsp;</td></tr>
            @endif

        @endforeach

    </table>
    <!-- Fin bloque nobr firmas -->


</body>

