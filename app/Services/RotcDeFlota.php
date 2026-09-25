<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * El ROTC de FLOTA del INTT entero ("CERTIFICACION DE ROTC ... EQUIPOS VIDALSA"), partido por
 * equipo.
 *
 * Como viene (el de 03/07/2026, 34 paginas):
 *   · la PORTADA (pag. 1), una imagen sin texto;
 *   · la TABLA de la flota (pags. 2 a 18): cabecera con la emision y el vencimiento de la hoja
 *     y una fila por vehiculo (278), con su placa, su serial y su vencimiento;
 *   · los CERTIFICADOS de circulacion (pags. 19 a 34), TRES por pagina, uno por vehiculo. Solo
 *     traen certificado los vehiculos de esa renovacion (48 de los 278).
 *
 * Lo que va en la ficha de cada equipo es SU parte, como se venia armando a mano: la portada,
 * la hoja de la tabla donde esta su fila y SU certificado recortado de la pagina (si el
 * documento lo trae). Asi el visor enseña lo de ese equipo y la revision de la noche lee su
 * fila y su certificado, sin los de los otros 277.
 *
 * Todo con Ghostscript (el mismo de la compresion, ver CompresorPdf), en modo SAFER y
 * abriendole solo el archivo que toca: el PDF llega de fuera y se abre EN EL SERVIDOR.
 */
class RotcDeFlota
{
    /** Tope por llamada a Ghostscript: leer las 34 paginas tarda ~2 s; esto es un cuelgue. */
    private const TIEMPO = 120;

    /**
     * Margen del recorte alrededor del certificado, en puntos: arriba, sobre el rotulo
     * "CERTIFICADO DE CIRCULACION..."; abajo, bajo "VIGENTES (NORMAS...)". Menos que la
     * distancia a la linea de guiones que separa un certificado del siguiente (~9 puntos).
     */
    private const MARGEN_ARRIBA = 9;
    private const MARGEN_ABAJO = 6;

    private string $bin;

    public function __construct(?string $bin = null)
    {
        $this->bin = $bin ?? (string) config('services.compresion_pdf.ghostscript', 'gs');
    }

    public function disponible(): bool
    {
        return (new CompresorPdf($this->bin))->disponible();
    }

    /**
     * Lee el PDF y dice donde esta cada cosa, o null si no es un ROTC de flota con texto (un
     * escaneo, un ROTC de un solo vehiculo, otro documento).
     *
     * @return array{portada: int[], emision: ?string, vence: ?string,
     *               filas: array<int, array{placa: string, serial: string, vence: string, pagina: int}>,
     *               certificados: array<int, array{placa: string, serial: string, emision: ?string, vence: ?string, pagina: int, recorte: ?array}>}|null
     */
    public function leer(string $pdf): ?array
    {
        $paginas = $this->paginasConLineas($pdf);
        $cajas = $this->cajas($pdf);

        $flota = ['portada' => [], 'emision' => null, 'vence' => null, 'filas' => [], 'certificados' => []];
        $primeraTabla = null;
        foreach ($paginas as $n => $lineas) {
            $texto = implode("\n", array_column($lineas, 'texto'));
            if (preg_match('/FLOTA\s+VEHICULAR/u', $texto)) {
                $primeraTabla ??= $n;
                $this->leerTabla($n, $lineas, $texto, $flota);
            } elseif (preg_match('/CERTIFICADO\s+DE\s+CIRCULACI/u', $texto)) {
                $this->leerCertificados($n, $lineas, $cajas[$n] ?? null, $flota);
            }
        }
        // Menos de dos filas no es una flota: es un ROTC de un vehiculo y va entero, como siempre.
        if ($primeraTabla === null || count($flota['filas']) < 2) return null;

        // La portada: lo que va antes de la tabla y no es ni tabla ni certificado.
        foreach (array_keys($paginas) as $n) {
            if ($n < $primeraTabla) $flota['portada'][] = $n;
        }
        return $flota;
    }

    /**
     * Arma en $destino la parte de UN equipo: la portada, la hoja de la tabla con su fila y su
     * certificado recortado (o la pagina entera del certificado si no se pudo medir donde esta).
     * $certificado es el de leer(), o null si el documento no trae el de este equipo.
     */
    public function parte(string $pdf, array $flota, int $paginaTabla, ?array $certificado, string $destino): void
    {
        $tmp = [];
        try {
            $hojas = array_values(array_unique(array_merge($flota['portada'], [$paginaTabla])));
            sort($hojas);
            $tmp[] = $primera = $destino . '.hojas.pdf';
            $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
                '-sDEVICE=pdfwrite', '-sPageList=' . implode(',', $hojas), '-sOutputFile=' . $primera, $pdf]);

            if (!$certificado) {
                rename($primera, $destino);
                return;
            }

            $tmp[] = $cert = $destino . '.cert.pdf';
            $r = $certificado['recorte'];
            if ($r) {
                // Una pagina del alto del certificado, con la pagina original corrida hacia abajo
                // para que el certificado caiga dentro: lo demas queda fuera y Ghostscript no lo
                // escribe (ni se ve ni queda su texto, comprobado).
                $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
                    '-sDEVICE=pdfwrite', '-dFirstPage=' . $certificado['pagina'], '-dLastPage=' . $certificado['pagina'],
                    '-dDEVICEWIDTHPOINTS=' . $r['ancho'], '-dDEVICEHEIGHTPOINTS=' . round($r['abajo'] - $r['arriba'], 2),
                    '-dFIXEDMEDIA', '-sOutputFile=' . $cert,
                    '-c', '<</PageOffset [' . round(-$r['x0'], 2) . ' ' . round(-($r['alto'] - $r['abajo']), 2) . ']>> setpagedevice',
                    '-f', $pdf]);
            } else {
                $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
                    '-sDEVICE=pdfwrite', '-sPageList=' . $certificado['pagina'], '-sOutputFile=' . $cert, $pdf]);
            }

            $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $primera . ',' . $cert,
                '-sDEVICE=pdfwrite', '-sOutputFile=' . $destino, $primera, $cert]);
        } finally {
            foreach ($tmp as $f) @unlink($f);
        }
    }

    // ── Leer ──────────────────────────────────────────────────────────────────────

    /** Las filas de una hoja de la tabla, y la emision y el vencimiento de su cabecera. */
    private function leerTabla(int $n, array $lineas, string $texto, array &$flota): void
    {
        $fecha = '(\d{1,2}\/\d{1,2}\/\d{4})';
        if (!$flota['emision'] && preg_match('/Fecha\s*y\s*Hora\s*de\s*Emisi[oó]n:?\s*' . $fecha . '/ui', $texto, $m)) {
            $flota['emision'] = $this->fecha($m[1]);
        }
        if (!$flota['vence'] && preg_match('/Fecha\s*de\s*vencimiento:?\s*' . $fecha . '/ui', $texto, $m)) {
            $flota['vence'] = $this->fecha($m[1]);
        }
        foreach ($lineas as $l) {
            // "1 A00BO0F SINOTRUK ZZ4257V344JB1 2025 CAMION TRACTOR 3 17100 Ton. LZZPCMSCXSJ402366 03/07/2027"
            if (preg_match('/^\d{1,4}\s+([A-Z0-9]{5,8})\s+.+\s([A-Z0-9]{17})\s+' . $fecha . '$/u', $l['texto'], $m)
                && ($vence = $this->fecha($m[3]))) {
                $flota['filas'][] = ['placa' => $m[1], 'serial' => $m[2], 'vence' => $vence, 'pagina' => $n];
            }
        }
    }

    /**
     * Los certificados de una pagina (tres). De cada uno: placa, serial, sus fechas y el
     * rectangulo que lo contiene, desde su rotulo hasta su "VIGENTES (NORMAS...)".
     */
    private function leerCertificados(int $n, array $lineas, ?array $caja, array &$flota): void
    {
        $abierto = null;
        $cerrar = function () use (&$abierto, &$flota, $caja, $n) {
            if ($abierto && $abierto['placa']) {
                $flota['certificados'][] = [
                    'placa' => $abierto['placa'], 'serial' => $abierto['serial'],
                    'emision' => $abierto['emision'], 'vence' => $abierto['vence'], 'pagina' => $n,
                    'recorte' => $this->recorte($abierto['arriba'], $abierto['abajo'], $caja),
                ];
            }
            $abierto = null;
        };

        foreach ($lineas as $i => $l) {
            $t = $l['texto'];
            if (preg_match('/CERTIFICADO\s+DE\s+CIRCULACI/u', $t)) {
                $cerrar();
                $abierto = ['placa' => null, 'serial' => null, 'emision' => null, 'vence' => null,
                            'arriba' => $l['arriba'], 'abajo' => null];
                continue;
            }
            if (!$abierto) continue;
            $siguiente = $lineas[$i + 1]['texto'] ?? '';
            if (!$abierto['placa'] && preg_match('/^Placa\s+Serial/u', $t)
                && preg_match('/^([A-Z0-9]{5,8})\s+([A-Z0-9]{10,25})\b/u', $siguiente, $m)) {
                [$abierto['placa'], $abierto['serial']] = [$m[1], $m[2]];
            }
            if (preg_match('/^Fecha\s+de\s+Emisi[oó]n\s+Fecha\s+de\s+Vencimiento/ui', $t)
                && preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\s+(\d{1,2}\/\d{1,2}\/\d{4})/', $siguiente, $m)) {
                [$abierto['emision'], $abierto['vence']] = [$this->fecha($m[1]), $this->fecha($m[2])];
            }
            if (preg_match('/VIGENTES\s*\(NORMAS/u', $t)) {
                $abierto['abajo'] = $l['abajo'];
                $cerrar();
            }
        }
        $cerrar();
    }

    /**
     * El rectangulo a recortar, en puntos y contando desde ARRIBA de la pagina, o null si no se
     * pudo medir (sin caja, pagina girada, medidas que no cuadran): entonces va la pagina entera.
     */
    private function recorte(?float $arriba, ?float $abajo, ?array $caja): ?array
    {
        if (!$caja || $arriba === null || $abajo === null || $caja['girada']) return null;
        $arriba = max(0, $arriba - self::MARGEN_ARRIBA);
        $abajo = min($caja['alto'], $abajo + self::MARGEN_ABAJO);
        // Un certificado mide ~180 puntos: fuera de eso, algo se leyo mal y mejor la pagina entera.
        if ($abajo - $arriba < 100 || $abajo - $arriba > 400) return null;
        return ['arriba' => $arriba, 'abajo' => $abajo, 'ancho' => $caja['ancho'], 'alto' => $caja['alto'], 'x0' => $caja['x0']];
    }

    /**
     * El texto de cada pagina en lineas, con su altura: [n => [['texto', 'arriba', 'abajo'], ...]].
     * Sale del txtwrite de Ghostscript en XML, que da cada trozo de texto con su posicion (la
     * "y" es la linea base, contada desde arriba); los trozos a la misma altura son una linea.
     */
    private function paginasConLineas(string $pdf): array
    {
        $xml = $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
            '-sDEVICE=txtwrite', '-dTextFormat=0', '-sOutputFile=-', $pdf]);

        $paginas = [];
        preg_match_all('/<page>(.*?)<\/page>/s', $xml, $pags);
        foreach ($pags[1] as $i => $cuerpo) {
            $trozos = [];
            preg_match_all('/<span bbox="([\d.\-]+) ([\d.\-]+) [\d.\-]+ [\d.\-]+"[^>]*size="([\d.]+)">(.*?)<\/span>/s', $cuerpo, $spans, PREG_SET_ORDER);
            foreach ($spans as [, $x, $y, $tam, $chars]) {
                preg_match_all('/ c="([^"]*)"/', $chars, $c);
                $texto = trim(html_entity_decode(implode('', $c[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($texto !== '') $trozos[] = ['x' => (float) $x, 'y' => (float) $y, 'tam' => (float) $tam, 'texto' => $texto];
            }
            // Una linea = los trozos a la misma altura (±2 puntos), de izquierda a derecha.
            usort($trozos, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
            $lineas = [];
            foreach ($trozos as $t) {
                $ultima = count($lineas) - 1;
                if ($ultima >= 0 && abs($lineas[$ultima]['y'] - $t['y']) <= 2) {
                    $lineas[$ultima]['partes'][] = $t;
                } else {
                    $lineas[] = ['y' => $t['y'], 'partes' => [$t]];
                }
            }
            $paginas[$i + 1] = array_map(function ($l) {
                usort($l['partes'], fn ($a, $b) => $a['x'] <=> $b['x']);
                $tam = max(array_column($l['partes'], 'tam'));
                return ['texto' => implode(' ', array_column($l['partes'], 'texto')),
                        'arriba' => $l['y'] - $tam, 'abajo' => $l['y'] + $tam * 0.3];
            }, $lineas);
        }
        return $paginas;
    }

    /** El tamaño de cada pagina (MediaBox) y si esta girada: [n => ['ancho','alto','x0','girada']]. */
    private function cajas(string $pdf): array
    {
        $info = $this->correr(['-q', '-dNODISPLAY', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
            '-dPDFINFO', $pdf], true);

        $cajas = [];
        foreach (preg_split('/\R/', $info) as $l) {
            if (preg_match('/^Page (\d+) MediaBox: \[([\d.\-]+) ([\d.\-]+) ([\d.\-]+) ([\d.\-]+)\]/', trim($l), $m)) {
                $cajas[(int) $m[1]] = [
                    'x0' => (float) $m[2], 'ancho' => (float) $m[4] - (float) $m[2],
                    'alto' => (float) $m[5] - (float) $m[3],
                    'girada' => (bool) preg_match('/Rotate\s*=\s*(90|180|270)/', $l),
                ];
            }
        }
        return $cajas;
    }

    private function fecha(string $v): ?string
    {
        [$d, $m, $a] = array_map('intval', explode('/', $v));
        return checkdate($m, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $m, $d) : null;
    }

    /** Corre Ghostscript y devuelve su salida. PDFINFO escribe en la de errores: $conErrores. */
    private function correr(array $args, bool $conErrores = false): string
    {
        $p = new Process(array_merge([$this->bin], $args));
        $p->setTimeout(self::TIEMPO);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('Ghostscript fallo: ' . mb_substr(trim($p->getErrorOutput() ?: $p->getOutput()), 0, 300));
        }
        return $p->getOutput() . ($conErrores ? "\n" . $p->getErrorOutput() : '');
    }
}
