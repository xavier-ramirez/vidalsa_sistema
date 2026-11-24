<?php

namespace App\Services\Consumibles;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee un Excel de despacho de gasoil, sea cual sea su formato, y devuelve los despachos
 * (salidas de tanque) en una forma única.
 *
 * El formato NO se decide por el nombre del archivo: en cada hoja se busca la fila de
 * encabezado (una celda que empiece por FECHA y otra con SALIDA o VOLUMEN en las primeras
 * 15 filas) y cada columna se reconoce por sinónimos. Así entran los tres que hay hoy:
 *  - Control de combustible (Agua Salada, Carabobo, Veladero): FECHA | CARACTERÍSTICAS DEL
 *    VEHÍCULO | PLACA/SERIAL | RESPONSABLE | C.I. | ENTRADA | SALIDA | … | FRENTE | PROYECTO.
 *  - Gerencia Ambiente: con COD («COD45») que se busca en la hoja «LISTADOS DE MÁQUINAS».
 *  - Ventas Patio El Tigre: la fecha en tres columnas (día | mes | año) y la placa dentro
 *    del texto («VOLQUETA JAC PLACA: A45AF4Y»).
 * Una hoja sin ese encabezado (dashboards, tablas dinámicas, catálogos) se ignora.
 *
 * Lo que NO es un despacho no sale como fila, se cuenta aparte: recargas del tanque
 * (ENTRADA sin SALIDA), «INICIO DE MES», filas de total/subtotal y filas sin litros.
 */
class LectorExcelCombustible
{
    /** Sinónimos de encabezado → campo. Se prueban en el orden de ORDEN (ci antes que resp:
     *  «C. I. DEL RESPONSABLE» también contiene «RESPONSABLE»). */
    private const SINONIMOS = [
        'codreq'   => ['CODIGO REQUIRIENTE'],
        'nota'     => ['NRO DE NOTA', 'NOTA DE DESPACHO'],
        'entrada'  => ['ENTRADA'],
        'salida'   => ['SALIDA', 'VOLUMEN', 'CANTIDAD', 'LITROS DESPACHADOS', 'LITROS SURTIDOS'],
        'ci'       => ['C. I.', 'C.I.', 'CEDULA'],
        'resp'     => ['NOMBRE DEL RESPONSABLE', 'NOMBRE Y APELLIDO', 'RESPONSABLE', 'OPERADOR', 'CONDUCTOR'],
        'ident'    => ['PLACA/SERIAL', 'PLACA / SERIAL', 'IDENTIFICADOR'],
        'equipo'   => ['CARACTERISTICAS DEL VE', 'VEHICULO / EQUIPO', 'VEHICULO/EQUIPO', 'DESCRIPCION'],
        'fecha'    => ['FECHA'],
        'frente'   => ['FRENTE'],
        'proyecto' => ['PROYECTO', 'ACTIVIDAD'],
        'chofer'   => ['CHOFER'],
        'placa'    => ['PLACA'],
        'serial'   => ['SERIAL'],
    ];

    private const MAX_COLUMNAS = 60;
    private const MAX_FILAS = 20000;

    /** Mes escrito (es/en) → número, para fechas de texto como «01-ago-26» (una tabla pegada). */
    private const MESES = ['ENE' => 1, 'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'APR' => 4, 'MAY' => 5,
        'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'AUG' => 8, 'SEP' => 9, 'SET' => 9, 'OCT' => 10, 'NOV' => 11,
        'DIC' => 12, 'DEC' => 12];

    /** Una fecha a más de esto de la mediana de su hoja no sirve de referencia para las vecinas. */
    private const DIAS_CONFIABLE = 150;

    /**
     * @return array{archivo:string, hojas:array<int,array>, filas:array<int,array>}
     */
    public function leer(string $ruta, string $nombre): array
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $libro = $lector->load($ruta);

        try {
            $catalogo = $this->catalogoCodigos($libro);
            $hojas = [];
            $filas = [];
            foreach ($libro->getWorksheetIterator() as $hoja) {
                [$filaEnc, $mapa] = $this->encabezado($hoja);
                if ($filaEnc === null) {
                    continue;
                }
                $r = $this->leerHoja($hoja, $filaEnc, $mapa, $catalogo, $nombre);
                $hojas[] = $r['resumen'];
                foreach ($r['filas'] as $f) {
                    $filas[] = $f;
                }
            }
        } finally {
            $libro->disconnectWorksheets();
        }

        return ['archivo' => $nombre, 'hojas' => $hojas, 'filas' => $filas];
    }

    // ── Encabezado ──────────────────────────────────────────────────────────

    private function campoDe(string $h): ?string
    {
        if ($h === '') {
            return null;
        }
        if (str_starts_with($h, 'COD2')) {
            return 'cod2';
        }
        if ($h === 'COD') {
            return 'cod';
        }
        // La hora o el kilometraje de la salida no son los litros («HORA DE SALIDA», «KM SALIDA»).
        if (preg_match('/^(HORA|KM|KILOMETRAJE|ODOMETRO)\b/', $h)) {
            return null;
        }
        if (str_starts_with($h, 'NUMERO DE VEHICULO')) {
            return 'numveh';   // nº correlativo del Patio: no identifica al equipo
        }
        // Encabezados cortos que solo valen como celda entera («LITROS» sí; «LITROS EN TANQUE»
        // o «DESCRIPCION», que contiene «CI», no; ni «PLACA DEL VEHICULO», que es la placa).
        $exacto = ['LITROS' => 'salida', 'LTS' => 'salida', 'LTS.' => 'salida', 'LT' => 'salida', 'CI' => 'ci', 'C I' => 'ci',
            'EQUIPO' => 'equipo', 'VEHICULO' => 'equipo'];
        if (isset($exacto[$h])) {
            return $exacto[$h];
        }
        foreach (self::SINONIMOS as $campo => $claves) {
            foreach ($claves as $k) {
                if (str_contains($h, $k)) {
                    return $campo;
                }
            }
        }

        return null;
    }

    /** @return array{0:?int,1:array<string,int>} fila del encabezado y campo → columna */
    private function encabezado(Worksheet $hoja): array
    {
        $maxCol = $this->ultimaColumna($hoja);
        for ($r = 1; $r <= 15; $r++) {
            $mapa = $titulo = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $h = TextoConsumo::norm($this->valor($hoja, $c, $r));
                if ($h === '') {
                    continue;
                }
                $campo = $this->campoDe($h);
                if ($campo !== null && !isset($mapa[$campo])) {
                    $mapa[$campo] = $c;
                    $titulo[$campo] = $h;
                }
            }
            if ($this->esEncabezadoDeDespachos($mapa, $titulo)) {
                return [$r, $mapa];
            }
        }

        return [null, []];
    }

    /**
     * Una columna que EMPIEZA por FECHA y OTRA de litros («FECHA DE SALIDA» sola es la salida).
     * Si los litros no se llaman SALIDA/VOLUMEN (CANTIDAD, LITROS…), además una columna que
     * identifique al equipo: una hoja de resumen o de tanque «FECHA | LITROS» no son despachos.
     *
     * @param array<string,int>    $mapa
     * @param array<string,string> $titulo
     */
    private function esEncabezadoDeDespachos(array $mapa, array $titulo): bool
    {
        if (!isset($mapa['fecha'], $mapa['salida']) || !str_starts_with($titulo['fecha'], 'FECHA')) {
            return false;
        }

        return str_contains($titulo['salida'], 'SALIDA') || str_contains($titulo['salida'], 'VOLUMEN')
            || array_intersect_key($mapa, array_flip(['ident', 'placa', 'serial', 'equipo'])) !== [];
    }

    /**
     * Catálogo COD → equipo de la Gerencia Ambiente (hoja con ITEM | … | SERIAL | PLACA).
     * @return array<string,array{equipo:?string,serial:?string,placa:?string}>
     */
    private function catalogoCodigos(Spreadsheet $libro): array
    {
        $catalogo = [];
        foreach ($libro->getWorksheetIterator() as $hoja) {
            $maxCol = $this->ultimaColumna($hoja);
            for ($r = 1; $r <= 10; $r++) {
                $cols = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $h = TextoConsumo::norm($this->valor($hoja, $c, $r));
                    if (in_array($h, ['ITEM', 'EQUIPO', 'SERIAL', 'PLACA'], true) && !isset($cols[$h])) {
                        $cols[$h] = $c;
                    }
                }
                if (count($cols) < 4) {
                    continue;
                }
                $ultima = min($hoja->getHighestDataRow(), self::MAX_FILAS);
                for ($f = $r + 1; $f <= $ultima; $f++) {
                    $cod = TextoConsumo::clave($this->valor($hoja, $cols['ITEM'], $f));
                    if (!str_starts_with($cod, 'COD')) {
                        continue;
                    }
                    $catalogo[$cod] = [
                        'equipo' => $this->texto($this->valor($hoja, $cols['EQUIPO'], $f)),
                        'serial' => $this->texto($this->valor($hoja, $cols['SERIAL'], $f)),
                        'placa'  => $this->texto($this->valor($hoja, $cols['PLACA'], $f)),
                    ];
                }
                break;
            }
        }

        return $catalogo;
    }

    // ── Hoja ────────────────────────────────────────────────────────────────

    private function leerHoja(Worksheet $hoja, int $filaEnc, array $mapa, array $catalogo, string $archivo): array
    {
        $partida = $this->fechaPartida($hoja, $filaEnc, $mapa['fecha']);
        $ultima = min($hoja->getHighestDataRow(), self::MAX_FILAS);
        $omitidas = ['recarga' => 0, 'litros_recarga' => 0.0, 'inicio' => 0, 'totales' => 0, 'sin_litros' => 0, 'sin_fecha' => 0];
        $sinFecha = [];
        $total = null;          // ['valor' => float, 'formula' => string, 'desde' => ?int, 'hasta' => ?int]
        $salidasPorFila = [];   // fila → litros de TODA fila con salida (para cuadrar contra el rango del total)
        $filas = [];

        for ($r = $filaEnc + 1; $r <= $ultima; $r++) {
            $g = fn (string $k) => isset($mapa[$k]) ? $this->valor($hoja, $mapa[$k], $r) : null;

            $vals = array_map(fn ($c) => $this->valor($hoja, $c, $r), array_values($mapa));
            if (count(array_filter($vals, fn ($v) => $v !== null)) === 0) {
                continue;
            }

            $salida = $this->numero($g('salida'));
            $entrada = $this->numero($g('entrada'));
            $desc = $this->texto($g('equipo'));
            $placa = $this->texto($g('placa'));
            $ident = $this->texto($g('ident'));
            $serial = $this->texto($g('serial'));
            $cod = $this->texto($g('cod'));
            $resp = $this->texto($g('resp'));
            $ci = TextoConsumo::digitos($g('ci') ?? '');
            if ($desc === null && $placa !== null && $ident === null) {
                $desc = $placa;   // Patio El Tigre: la descripción y la placa vienen en la misma celda
            }

            $fechaVal = $partida
                ? [$this->valor($hoja, $mapa['fecha'], $r), $this->valor($hoja, $mapa['fecha'] + 1, $r), $this->valor($hoja, $mapa['fecha'] + 2, $r)]
                : $g('fecha');
            $hayFecha = $partida ? $fechaVal[0] !== null : $fechaVal !== null;
            $identifica = $desc !== null || $ident !== null || $serial !== null || $cod !== null || $ci !== '' || $resp !== null;

            // Total / subtotal: sin fecha y sin nada que identifique un despacho.
            if (!$hayFecha && !$identifica) {
                $omitidas['totales']++;
                $formula = $this->formula($hoja, $mapa['salida'], $r);
                if ($total === null && $salida > 0 && $formula !== null && preg_match('/SUBTOTAL|SUM/i', $formula)) {
                    $total = ['valor' => round($salida, 2), 'formula' => $formula] + $this->rangoFilas($hoja, $formula);
                }
                continue;
            }
            if ($salida > 0) {
                $salidasPorFila[$r] = $salida;
            }

            $textoFila = TextoConsumo::norm(implode(' ', array_filter([$desc, $ident, $placa])));
            if (preg_match('/\bINICIO\b|SALDO ANTERIOR|SALDO INICIAL/', $textoFila)) {
                $omitidas['inicio']++;
                continue;
            }
            if ($entrada > 0 && !($salida > 0)) {
                $omitidas['recarga']++;
                $omitidas['litros_recarga'] += $entrada;
                continue;
            }
            if (!($salida > 0)) {
                $omitidas['sin_litros']++;
                continue;
            }

            $fecha = $partida ? $this->fechaDePartes($fechaVal[0], $fechaVal[1], $fechaVal[2]) : $this->fecha($fechaVal);

            $catCod = $cod !== null ? ($catalogo[TextoConsumo::clave($cod)] ?? null) : null;

            $filas[] = [
                'fecha'     => $fecha['fecha'],
                'partes'    => $fecha['partes'],
                'fecha_raw' => $fecha['raw'],
                'litros'    => round($salida, 2),
                'desc'      => $desc,
                'placa'     => $placa,
                'ident'     => $ident,
                'serial'    => $serial,
                'cod'       => $cod,
                'cat'       => $catCod,
                'resp'      => $resp,
                'ci'        => $ci,
                'proyecto'  => $this->texto($g('proyecto')),
                'frente'    => $this->texto($g('frente')),
                'nota'      => $this->texto($g('nota')),
                'archivo'   => $archivo,
                'hoja'      => $hoja->getTitle(),
                'fila'      => $r,
                'avisos'    => [],
            ];
        }

        $this->repararFechas($filas);

        $validas = [];
        foreach ($filas as $f) {
            if ($f['fecha'] === null) {
                $omitidas['sin_fecha']++;
                $sinFecha[] = $f['fila'];
                continue;
            }
            unset($f['partes']);
            $validas[] = $f;
        }

        $litros = round(array_sum(array_column($validas, 'litros')), 2);
        $resumenTotal = null;
        if ($total !== null) {
            $enRango = $total['desde'] !== null
                ? array_sum(array_filter($salidasPorFila, fn ($fila) => $fila >= $total['desde'] && $fila <= $total['hasta'], ARRAY_FILTER_USE_KEY))
                : array_sum($salidasPorFila);
            $resumenTotal = $total + [
                'suma_rango' => round($enRango, 2),
                'cuadra'     => abs($enRango - $total['valor']) < 0.5,
            ];
        }

        $fechas = array_column($validas, 'fecha');

        return [
            'filas'   => $validas,
            'resumen' => [
                'archivo'   => $archivo,
                'hoja'      => $hoja->getTitle(),
                'formato'   => $partida || isset($mapa['codreq']) ? 'Ventas Patio' : (isset($mapa['cod']) ? 'Gerencia Ambiente' : 'Control de combustible'),
                'filas'     => count($validas),
                'litros'    => $litros,
                'desde'     => $fechas ? min($fechas) : null,
                'hasta'     => $fechas ? max($fechas) : null,
                'total'     => $resumenTotal,
                'omitidas'  => $omitidas,
                'sin_fecha' => array_slice($sinFecha, 0, 30),
                'corregidas'=> count(array_filter($validas, fn ($f) => $f['avisos'] !== [])),
            ],
        ];
    }

    /**
     * Fecha en tres columnas (día | mes | año): el encabezado FECHA ocupa una celda y las
     * dos de al lado quedan vacías; debajo vienen números chicos. Se decide por los DATOS,
     * no por la celda combinada (que con readDataOnly ni se lee).
     */
    private function fechaPartida(Worksheet $hoja, int $filaEnc, int $col): bool
    {
        if ($this->valor($hoja, $col + 1, $filaEnc) !== null || $this->valor($hoja, $col + 2, $filaEnc) !== null) {
            return false;
        }
        $si = $vistas = 0;
        $ultima = min($hoja->getHighestDataRow(), $filaEnc + 40);
        for ($r = $filaEnc + 1; $r <= $ultima && $vistas < 10; $r++) {
            $d = $this->valor($hoja, $col, $r);
            if ($d === null) {
                continue;
            }
            $vistas++;
            $m = $this->valor($hoja, $col + 1, $r);
            $a = $this->valor($hoja, $col + 2, $r);
            if (is_numeric($d) && is_numeric($m) && is_numeric($a)
                && $d >= 1 && $d <= 31 && $m >= 1 && $m <= 12 && $a >= 2000 && $a <= 2100) {
                $si++;
            }
        }

        return $vistas > 0 && $si >= max(1, intdiv($vistas, 2));
    }

    // ── Fechas ──────────────────────────────────────────────────────────────

    /** @return array{fecha:?string, partes:?array, raw:string} */
    private function fecha(mixed $v): array
    {
        if ($v instanceof \DateTimeInterface) {
            return $this->fechaDePartes((int) $v->format('d'), (int) $v->format('m'), (int) $v->format('Y'), $v->format('d/m/Y'));
        }
        if (is_numeric($v) && $v > 20000 && $v < 80000) {
            $d = FechaExcel::excelToDateTimeObject((float) $v);

            return $this->fechaDePartes((int) $d->format('d'), (int) $d->format('m'), (int) $d->format('Y'), $d->format('d/m/Y'));
        }
        $s = trim((string) $v);
        $p = preg_split('/\D+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (count($p) === 2 && strlen($p[1]) === 6) {          // «29/042026»
            $p = [$p[0], substr($p[1], 0, 2), substr($p[1], 2)];
        }
        if (count($p) !== 3) {
            $p = $this->partesDeTexto($s, $p);
        }
        if (count($p) === 3 && strlen($p[0]) === 4 && strlen($p[1]) <= 2 && strlen($p[2]) <= 2) {   // «2026-08-01»
            return $this->fechaDePartes((int) $p[2], (int) $p[1], (int) $p[0], $s);
        }
        if (count($p) === 3 && strlen($p[0]) <= 2 && strlen($p[1]) <= 2) {
            $a = (int) $p[2];
            if (strlen($p[2]) <= 2) {
                $a += 2000;
            }

            return $this->fechaDePartes((int) $p[0], (int) $p[1], $a, $s);
        }

        return ['fecha' => null, 'partes' => null, 'raw' => $s];
    }

    /**
     * Día, mes y año de una fecha de texto que no vino como «d/m/a»: con la hora detrás
     * («01/08/2026 08:30») o con el mes escrito («01-ago-26», «1 de agosto de 2026»). Solo
     * se intenta cuando lo numérico no dio tres partes, para no tocar lo que ya se leía.
     */
    private function partesDeTexto(string $s, array $p): array
    {
        if (count($p) >= 5 && preg_match('/\d{1,2}:\d{2}/', $s)) {
            return array_slice($p, 0, 3);
        }
        $conMes = preg_replace_callback('/(?<![A-Z])(' . implode('|', array_keys(self::MESES)) . ')[A-Z]*\.?/', function ($m) {
            return ' ' . self::MESES[$m[1]] . ' ';
        }, TextoConsumo::norm($s));

        return preg_split('/\D+/', (string) $conMes, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** Día/mes/año sueltos. Un año fuera de rango deja la fecha en null pero guarda las partes para repararla. */
    private function fechaDePartes(mixed $d, mixed $m, mixed $a, ?string $raw = null): array
    {
        $raw ??= "{$d}/{$m}/{$a}";
        if (!is_numeric($d) || !is_numeric($m)) {
            return ['fecha' => null, 'partes' => null, 'raw' => (string) $raw];
        }
        $d = (int) $d;
        $m = (int) $m;
        $a = is_numeric($a) ? (int) $a : 0;
        $anioOk = $a >= 2020 && $a <= (int) date('Y') + 1;
        if ($anioOk && checkdate($m, $d, $a)) {
            return ['fecha' => sprintf('%04d-%02d-%02d', $a, $m, $d), 'partes' => [$d, $m, $a], 'raw' => (string) $raw];
        }

        return ['fecha' => null, 'partes' => [$d, $m, $a], 'raw' => (string) $raw];
    }

    /**
     * Repara fechas con las filas vecinas (las hojas van en orden de fecha):
     *  - año imposible o ilegible («0206», «20236», «2029»): se toma el de la vecina confiable;
     *  - año que se aleja >120 días de las vecinas («2025» entre filas de 2026): ídem si así cae cerca;
     *  - día y mes invertidos (03/02 entre filas de marzo): se invierten si así cae a ≤3 días.
     * «Confiable» = fecha válida a ≤150 días de la mediana de la hoja; así un bloque de filas
     * con el mismo año equivocado no se valida entre sí. Toda corrección queda como aviso.
     */
    private function repararFechas(array &$filas): void
    {
        $validas = array_values(array_filter(array_column($filas, 'fecha')));
        if ($validas === []) {
            return;
        }
        sort($validas);
        $mediana = strtotime($validas[intdiv(count($validas), 2)]);
        $dia = 86400;

        $confiable = [];
        foreach ($filas as $i => $f) {
            if ($f['fecha'] !== null && abs(strtotime($f['fecha']) - $mediana) <= self::DIAS_CONFIABLE * $dia) {
                $confiable[$i] = strtotime($f['fecha']);
            }
        }
        if ($confiable === []) {
            return;
        }

        $n = count($filas);
        $previa = $siguiente = [];
        $ultimo = null;
        for ($i = 0; $i < $n; $i++) {
            $previa[$i] = $ultimo;
            if (isset($confiable[$i])) {
                $ultimo = $confiable[$i];
            }
        }
        $ultimo = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            $siguiente[$i] = $ultimo;
            if (isset($confiable[$i])) {
                $ultimo = $confiable[$i];
            }
        }

        $cerca = function (int $t, array $refs, int $dias) use ($dia): bool {
            foreach ($refs as $ref) {
                if (abs($t - $ref) <= $dias * $dia) {
                    return true;
                }
            }

            return false;
        };
        $hacer = function (int $d, int $m, int $a): ?int {
            return checkdate($m, $d, $a) ? mktime(0, 0, 0, $m, $d, $a) : null;
        };

        foreach ($filas as $i => &$f) {
            if (isset($confiable[$i]) && $f['fecha'] !== null) {
                // Confiable, pero ¿día y mes invertidos? Solo si las DOS vecinas lo contradicen.
                // Vecinas con OTRA fecha: dos filas seguidas con el mismo error no se avalan entre sí.
                $t = $confiable[$i];
                $refs = array_filter([$this->vecinaDistinta($confiable, $i, -1, $n), $this->vecinaDistinta($confiable, $i, 1, $n)]);
                [$d, $m, $a] = $f['partes'];
                if (count($refs) === 2 && !$cerca($t, $refs, 20) && $d <= 12 && $d !== $m) {
                    $inv = $hacer($m, $d, $a);
                    if ($inv !== null && $cerca($inv, $refs, 3)) {
                        $this->corregir($f, $inv, 'día y mes invertidos');
                    }
                }
                continue;
            }
            $refs = array_filter([$previa[$i], $siguiente[$i]]);
            if ($refs === [] || $f['partes'] === null) {
                continue;
            }
            [$d, $m] = $f['partes'];
            foreach ($refs as $ref) {
                $t = $hacer($d, $m, (int) date('Y', $ref));
                if ($t !== null && $cerca($t, $refs, 45)) {
                    $this->corregir($f, $t, 'año corregido');
                    continue 2;
                }
            }
            if ($f['fecha'] !== null) {
                $f['avisos'][] = 'Fecha dudosa: lejos de las filas vecinas';
            }
        }
        unset($f);
    }

    /** La fila confiable más cercana en la dirección $paso cuya fecha NO es la de la fila $i. */
    private function vecinaDistinta(array $confiable, int $i, int $paso, int $n): ?int
    {
        for ($j = $i + $paso; $j >= 0 && $j < $n; $j += $paso) {
            if (isset($confiable[$j]) && $confiable[$j] !== $confiable[$i]) {
                return $confiable[$j];
            }
        }

        return null;
    }

    private function corregir(array &$f, int $t, string $motivo): void
    {
        $nueva = date('Y-m-d', $t);
        $f['avisos'][] = "Fecha «{$f['fecha_raw']}» → " . date('d/m/Y', $t) . " ({$motivo})";
        $f['fecha'] = $nueva;
    }

    // ── Celdas ──────────────────────────────────────────────────────────────

    private function ultimaColumna(Worksheet $hoja): int
    {
        return min(Coordinate::columnIndexFromString($hoja->getHighestDataColumn()), self::MAX_COLUMNAS);
    }

    /** Valor visible de la celda: el resultado guardado si es fórmula; null si está vacía. */
    private function valor(Worksheet $hoja, int $c, int $r): mixed
    {
        if (!$hoja->cellExists([$c, $r])) {
            return null;
        }
        $celda = $hoja->getCell([$c, $r]);
        $v = $celda->getValue();
        if (is_string($v) && str_starts_with($v, '=')) {
            $v = $celda->getOldCalculatedValue();
        }
        if ($v instanceof RichText) {
            $v = $v->getPlainText();
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' ? null : $v;
        }

        return $v;
    }

    private function formula(Worksheet $hoja, int $c, int $r): ?string
    {
        if (!$hoja->cellExists([$c, $r])) {
            return null;
        }
        $v = $hoja->getCell([$c, $r])->getValue();

        return is_string($v) && str_starts_with($v, '=') ? $v : null;
    }

    /**
     * Filas que suma la fórmula del total: «=SUM(G98:G941)» → 98–941; «=SUBTOTAL(109,Tabla1[SALIDA])»
     * → las filas de datos de Tabla1. null = no se pudo saber (se compara contra toda la hoja).
     */
    private function rangoFilas(Worksheet $hoja, string $formula): array
    {
        if (preg_match('/[A-Z]{1,3}\$?(\d+)\s*:\s*\$?[A-Z]{1,3}\$?(\d+)/i', $formula, $m)) {
            return ['desde' => (int) $m[1], 'hasta' => (int) $m[2]];
        }
        if (preg_match('/([A-Za-z_][\w.]*)\[/', $formula, $m)) {
            foreach ($hoja->getTableCollection() as $tabla) {
                if (strcasecmp($tabla->getName(), $m[1]) === 0) {
                    [$ini, $fin] = Coordinate::rangeBoundaries($tabla->getRange());

                    return ['desde' => $ini[1] + 1, 'hasta' => $fin[1]];   // sin la fila de títulos
                }
            }
        }

        return ['desde' => null, 'hasta' => null];
    }

    private function texto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('d/m/Y');
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            $v = (int) $v;
        }
        $s = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $s === '' ? null : mb_substr($s, 0, 250);
    }

    /** «1.234,5» / «1234.5» / «250 LTS» → número. */
    private function numero(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(' ', '', (string) $v);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        $s = str_replace(',', '.', $s);
        if (preg_match('/^-?\d+(\.\d+)?/', $s, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }
}
