<?php
/**
 * Genera las MINIATURAS de los botones de capas de /mapa (arriba-derecha):
 *   public/img/mapa/mini-municipios.png  → botón "Municipios"
 *   public/img/mapa/mini-faja.png        → botón "Faja" (las 4 divisiones, cada una de su color)
 *   public/img/mapa/mini-bloques.png     → botón "Bloques" (bloques petroleros, en gris)
 *
 * Se pre-generan aquí, y NO en el navegador, para que los botones no pesen nada: los geojson
 * de verdad (municipios ≈1 MB, faja ≈35 KB, bloques ≈230 KB) se siguen cargando solo cuando el
 * usuario enciende la capa. Todas usan el MISMO encuadre, así se ven a la misma escala.
 *
 * Los colores de los municipios salen del MISMO algoritmo que el mapa
 * (public/js/maquinaria/mapa_index.js, construirColoresMuni): paleta de 24 tonos + coloreo voraz
 * por adyacencia, así la miniatura enseña exactamente los colores que verá al encender la capa.
 *
 * Solo hay que volver a correrlo si cambian los geojson o la paleta:
 *     php tools/generar_miniaturas_mapa.php
 * (La capa petrolera se descarga con tools/generar_geo_faja.php, que hay que correr ANTES que esto.)
 */

// dirname(__DIR__) = raiz del proyecto: este script vive en tools/, no en la raiz.
$RAIZ          = dirname(__DIR__);
$GEO_MUNI      = $RAIZ . '/public/geo/venezuela-municipios.geojson';
$GEO_ESTADOS   = $RAIZ . '/public/geo/venezuela-estados.geojson';
$GEO_FAJA_POLI = $RAIZ . '/public/geo/faja-poligonal.geojson';
$GEO_FAJA_BLOQ = $RAIZ . '/public/geo/faja-bloques.geojson';
$DIR_SALIDA    = $RAIZ . '/public/img/mapa';
$ANCHO = 256;   // px del PNG final (el botón lo muestra a ~60 px: da margen para pantallas retina)
$SS    = 3;     // supersampling: se dibuja a 3× y se reduce → bordes suaves (GD no antialiasea polígonos)

// Misma paleta que PALETA_MUNI en mapa_index.js (24 tonos).
$PALETA = [
    '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#10b981', '#14b8a6',
    '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
    '#f43f5e', '#92400e', '#b45309', '#78716c', '#64748b', '#334155', '#0f172a', '#65a30d',
];

// ── Utilidades de color (equivalentes a colorHash / hexToRgb / colorDist del JS) ──
function normEstado($s) {
    $n = mb_strtoupper(trim((string) $s), 'UTF-8');
    $n = strtr($n, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $n = preg_replace('/^BOLIVARIANO\s+/', '', $n);
    $n = preg_replace('/^ESTADO\s+/', '', $n);
    return $n === 'VARGAS' ? 'LA GUAIRA' : $n;
}
function muniKey($estado, $municipio) {
    return normEstado($estado) . '|' . mb_strtoupper(trim((string) $municipio), 'UTF-8');
}
// Hash estable por nombre: idéntico al del JS (h = h*31 + charCode, sobre UTF-16).
function colorHash($nombre, $paleta) {
    $h = 0;
    $u16 = mb_convert_encoding((string) $nombre, 'UTF-16LE', 'UTF-8');
    for ($i = 0, $n = strlen($u16); $i < $n; $i += 2) {
        $code = ord($u16[$i]) | (ord($u16[$i + 1]) << 8);
        $h = ($h * 31 + $code) % 4294967296;
    }
    return $paleta[$h % count($paleta)];
}
function hexToRgb($h) {
    $h = ltrim($h, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}
function colorDist($a, $b) {
    $rm = ($a[0] + $b[0]) / 2; $dr = $a[0] - $b[0]; $dg = $a[1] - $b[1]; $db = $a[2] - $b[2];
    return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
}

function cargarGeo($ruta) {
    $g = is_file($ruta) ? json_decode(file_get_contents($ruta), true) : null;
    return (is_array($g) && !empty($g['features'])) ? $g['features'] : null;
}
// Anillos EXTERIORES de cada feature ([[lng,lat], …]). Los huecos no se dibujan: a este tamaño
// no se ven, y así el relleno de cada polígono queda de una sola pieza.
function anillos($geometry) {
    if (empty($geometry['type'])) return [];
    $partes = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
    $out = [];
    foreach ($partes as $p) { if (!empty($p[0])) $out[] = $p[0]; }
    return $out;
}

// ── Colores por municipio: grafo de adyacencia (vértice compartido) + voraz Welsh-Powell ──
function coloresMunicipios($features, $paleta) {
    $GRID = 1e4;   // 4 decimales ≈ 11 m, igual que el JS
    $puntos = []; $vecinos = []; $nombreDe = [];
    foreach ($features as $f) {
        $p = $f['properties'] ?? [];
        if (empty($p['municipio'])) continue;
        $clave = muniKey($p['estado'] ?? '', $p['municipio']);
        $nombreDe[$clave] = $p['municipio'];
        if (!isset($vecinos[$clave])) $vecinos[$clave] = [];
        foreach (anillos($f['geometry']) as $anillo) {
            foreach ($anillo as $c) { $puntos[round($c[0] * $GRID) . '_' . round($c[1] * $GRID)][$clave] = 1; }
        }
    }
    foreach ($puntos as $ns) {
        $ns = array_keys($ns);
        if (count($ns) < 2) continue;
        for ($i = 0; $i < count($ns); $i++)
            for ($j = $i + 1; $j < count($ns); $j++) { $vecinos[$ns[$i]][$ns[$j]] = 1; $vecinos[$ns[$j]][$ns[$i]] = 1; }
    }
    $nodos = array_keys($vecinos);
    usort($nodos, function ($a, $b) use ($vecinos) {
        return (count($vecinos[$b]) <=> count($vecinos[$a])) ?: strcmp($a, $b);
    });
    $rgbPaleta = array_map('hexToRgb', $paleta);
    $asign = [];
    foreach ($nodos as $n) {
        $usados = [];
        foreach (array_keys($vecinos[$n]) as $v) { if (isset($asign[$v])) $usados[] = hexToRgb($asign[$v]); }
        if (!$usados) { $asign[$n] = colorHash($nombreDe[$n], $paleta); continue; }
        $mejorIdx = 0; $mejorDist = -1;
        foreach ($rgbPaleta as $idx => $c) {
            $dmin = INF;
            foreach ($usados as $u) $dmin = min($dmin, colorDist($c, $u));
            if ($dmin > $mejorDist) { $mejorDist = $dmin; $mejorIdx = $idx; }
        }
        $asign[$n] = $paleta[$mejorIdx];
    }
    return $asign;
}

// ── Proyección (Web Mercator, la de Leaflet) ──
// Ambos ejes en RADIANES: x = lng, y = mercator(lat). Mezclar grados con el y de Mercator
// (que ya sale en radianes) aplastaría el mapa.
function mercX($lng) { return deg2rad($lng); }
function mercY($lat) { return log(tan(M_PI / 4 + deg2rad($lat) / 2)); }

/** Lienzo compartido: mismo encuadre para todas las miniaturas. */
class Lienzo {
    public $im, $W, $H, $anchoFinal, $ss;
    private $minX, $maxY, $esc, $cache = [];

    public function __construct($bbox, $anchoFinal, $ss) {
        [$minX, $minY, $maxX, $maxY] = $bbox;
        $this->minX = $minX; $this->maxY = $maxY;
        $this->anchoFinal = $anchoFinal; $this->ss = $ss;
        $this->W = $anchoFinal * $ss;
        $this->H = (int) round($this->W * ($maxY - $minY) / ($maxX - $minX));
        $this->esc = $this->W / ($maxX - $minX);
        $this->im = imagecreatetruecolor($this->W, $this->H);
        imagealphablending($this->im, false);
        imagesavealpha($this->im, true);
        imagefilledrectangle($this->im, 0, 0, $this->W, $this->H, imagecolorallocatealpha($this->im, 0, 0, 0, 127)); // fondo transparente
        imagealphablending($this->im, true);
        imagesetthickness($this->im, max(1, (int) round($ss * 0.6)));
    }
    /** $alpha: 0 = opaco, 127 = invisible (escala de GD). */
    public function color($hex, $alpha = 0) {
        $k = $hex . '_' . $alpha;
        if (!isset($this->cache[$k])) {
            [$r, $g, $b] = hexToRgb($hex);
            $this->cache[$k] = imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
        }
        return $this->cache[$k];
    }
    /** Dibuja los anillos exteriores de unas features: relleno + borde (opcionales). */
    public function pintar($features, $relleno, $borde = null, $alphaRelleno = 0, $alphaBorde = 0) {
        foreach ($features as $f) {
            $hex = is_callable($relleno) ? $relleno($f) : $relleno;
            foreach (anillos($f['geometry'] ?? []) as $anillo) {
                $pts = [];
                foreach ($anillo as $c) {
                    $pts[] = (mercX($c[0]) - $this->minX) * $this->esc;
                    $pts[] = ($this->maxY - mercY($c[1])) * $this->esc;
                }
                if (count($pts) < 6) continue;
                if ($hex) imagefilledpolygon($this->im, $pts, $this->color($hex, $alphaRelleno));
                if ($borde) imagepolygon($this->im, $pts, $this->color($borde, $alphaBorde));
            }
        }
    }
    /** Reduce a tamaño final (= antialiasing) y guarda el PNG. */
    public function guardar($ruta) {
        $out = imagecreatetruecolor($this->anchoFinal, (int) round($this->H / $this->ss));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefilledrectangle($out, 0, 0, imagesx($out), imagesy($out), imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $this->im, 0, 0, 0, 0, imagesx($out), imagesy($out), $this->W, $this->H);
        imagepng($out, $ruta, 9);
        printf("  OK: %s (%d×%d, %.1f KB)\n", basename($ruta), imagesx($out), imagesy($out), filesize($ruta) / 1024);
    }
}

$municipios = cargarGeo($GEO_MUNI);
if (!$municipios) { fwrite(STDERR, "No se pudo leer $GEO_MUNI\n"); exit(1); }

// Encuadre COMPARTIDO por todas las miniaturas = extensión de los municipios (el país completo).
$bbox = [INF, INF, -INF, -INF];
foreach ($municipios as $f) {
    foreach (anillos($f['geometry']) as $anillo) {
        foreach ($anillo as $c) {
            $x = mercX($c[0]); $y = mercY($c[1]);
            $bbox[0] = min($bbox[0], $x); $bbox[2] = max($bbox[2], $x);
            $bbox[1] = min($bbox[1], $y); $bbox[3] = max($bbox[3], $y);
        }
    }
}
if (!is_dir($DIR_SALIDA)) mkdir($DIR_SALIDA, 0777, true);

// ── 1) Miniatura de MUNICIPIOS: cada municipio con su color real ──
echo "Miniatura de municipios…\n";
$colores = coloresMunicipios($municipios, $PALETA);
$lienzo = new Lienzo($bbox, $ANCHO, $SS);
$lienzo->pintar($municipios, function ($f) use ($colores, $PALETA) {
    $p = $f['properties'] ?? [];
    $clave = muniKey($p['estado'] ?? '', $p['municipio'] ?? '');
    return $colores[$clave] ?? colorHash($p['municipio'] ?? '', $PALETA);
}, '#ffffff', 0, 40); // hilo blanco: separa municipios del mismo tono
$lienzo->guardar($DIR_SALIDA . '/mini-municipios.png');

// ── 2) y 3) Miniaturas de la capa petrolera: son DOS botones distintos en el mapa ──
// Los colores tienen que ser los mismos que usa mapa_index.js: cada división de la Faja con su
// color (COLOR_AREA_FAJA) y los bloques en gris (estiloBloque).
$COLOR_AREA_FAJA = ['BOYACA' => '#15803d', 'JUNIN' => '#1d4ed8', 'AYACUCHO' => '#a21caf', 'CARABOBO' => '#000000'];
$estados  = cargarGeo($GEO_ESTADOS);
$fajaPoli = cargarGeo($GEO_FAJA_POLI);
$fajaBloq = cargarGeo($GEO_FAJA_BLOQ);
if (!$fajaPoli || !$fajaBloq) {
    fwrite(STDERR, "  Faltan los geojson de la faja: corre antes 'php tools/generar_geo_faja.php'\n");
    exit(1);
}

echo "Miniatura de la Faja…\n";
$lienzo = new Lienzo($bbox, $ANCHO, $SS);
if ($estados) $lienzo->pintar($estados, '#475569', '#94a3b8');   // país de fondo, apagado
$lienzo->pintar($fajaPoli, function ($f) use ($COLOR_AREA_FAJA) {
    $n = normEstado($f['properties']['nombre'] ?? '');
    return $COLOR_AREA_FAJA[$n] ?? '#c2410c';
}, '#000000');   // borde negro, igual que en el mapa
$lienzo->guardar($DIR_SALIDA . '/mini-faja.png');

echo "Miniatura de los bloques…\n";
$lienzo = new Lienzo($bbox, $ANCHO, $SS);
if ($estados) $lienzo->pintar($estados, '#475569', '#94a3b8');
// Gris más claro que el del mapa a propósito: aquí el relleno va OPACO sobre el país oscuro,
// mientras que en el mapa es translúcido sobre el satélite. La impresión final es la misma.
$lienzo->pintar($fajaBloq, '#94a3b8', '#e2e8f0');
$lienzo->guardar($DIR_SALIDA . '/mini-bloques.png');
