<?php
/**
 * Descarga la CAPA PETROLERA (Faja Petrolífera del Orinoco + bloques) y la deja como GeoJSON
 * LOCAL en public/geo/. El mapa la sirve desde NUESTRO servidor: así carga rápido, no depende
 * de que ArcGIS esté disponible ni de la latencia a Esri, y entra en el caché del navegador.
 *
 * Fuente: servicios públicos de ArcGIS Online de la app "Mapa Petrolífero de Vzla" (LSIGMA,
 * org 2jmdYNQsteiDSgjD) — la misma que se ve en
 * https://hub.arcgis.com/apps/af3603ea8e844248a8df4f34024bb60f/explore
 * Los datos son estáticos (bloques 2018): solo hay que volver a correr esto si la fuente cambia.
 *
 *     php generar_geo_faja.php
 *
 * El archivo se ADELGAZA antes de guardarlo (solo los campos que usa el mapa, coordenadas a 5
 * decimales ≈ 1 m y simplificación suave): el original pesa ~1 MB y queda en menos de la mitad.
 */

$BASE = 'https://services8.arcgis.com/2jmdYNQsteiDSgjD/arcgis/rest/services/%s/FeatureServer/0/query'
      . '?where=1%%3D1&outFields=*&outSR=4326&f=geojson';

$CAPAS = [
    [
        'servicio' => 'Poligonal_FPO',
        'salida'   => 'faja-poligonal.geojson',
        // Las 4 divisiones de la Faja: Boyacá, Junín, Ayacucho y Carabobo.
        'campos'   => ['BLOQUE' => 'nombre', 'AREA_HA' => 'area_ha'],
        'tol'      => 0.0005,   // ≈ 55 m: invisible a escala de mapa, pero quita muchísimo peso
    ],
    [
        'servicio' => 'BLOQUES_EM_FPO_2018_F',
        'salida'   => 'faja-bloques.geojson',
        // Bloques petroleros de TODO el país (Faja, Occidente/Lago, Oriente, Costa Afuera):
        // así el mapa dice en qué bloque cae un punto esté donde esté el frente.
        'campos'   => [
            'BLOQUES'    => 'nombre',
            'CAMPO'      => 'campo',
            'ETIQUETA'   => 'etiqueta',
            'EMP_MIXTA'  => 'empresa',
            'PAIS'       => 'pais',
            'CARACTERIS' => 'caracteristica',
            'API'        => 'api',
            'AREA_KM2'   => 'area_km2',
        ],
        'tol'      => 0.0003,   // ≈ 33 m
    ],
];

// ── Simplificación Douglas-Peucker (por anillo) ──
function dpDist($p, $a, $b) {
    $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1];
    if ($dx === 0.0 && $dy === 0.0) { $dx = $p[0] - $a[0]; $dy = $p[1] - $a[1]; return $dx * $dx + $dy * $dy; }
    $t = (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0, min(1, $t));
    $ex = $a[0] + $t * $dx - $p[0]; $ey = $a[1] + $t * $dy - $p[1];
    return $ex * $ex + $ey * $ey;
}
function dp($pts, $tol2) {
    $n = count($pts);
    if ($n < 3) return $pts;
    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $pila = [[0, $n - 1]];
    while ($pila) {
        [$i, $j] = array_pop($pila);
        $maxD = -1; $idx = -1;
        for ($k = $i + 1; $k < $j; $k++) {
            $d = dpDist($pts[$k], $pts[$i], $pts[$j]);
            if ($d > $maxD) { $maxD = $d; $idx = $k; }
        }
        if ($maxD > $tol2 && $idx > 0) { $keep[$idx] = true; $pila[] = [$i, $idx]; $pila[] = [$idx, $j]; }
    }
    $out = [];
    foreach ($pts as $k => $p) if ($keep[$k]) $out[] = $p;
    return $out;
}
// Redondea a 5 decimales (≈ 1 m) y quita puntos repetidos seguidos.
function limpiarAnillo($anillo, $tol) {
    $simp = $tol > 0 ? dp($anillo, $tol * $tol) : $anillo;
    $out = []; $prev = null;
    foreach ($simp as $c) {
        $p = [round($c[0], 5), round($c[1], 5)];
        if ($prev && $p[0] === $prev[0] && $p[1] === $prev[1]) continue;
        $out[] = $p; $prev = $p;
    }
    // Un anillo tiene que cerrar y tener al menos 3 vértices distintos.
    if (count($out) < 4) return null;
    if ($out[0] !== $out[count($out) - 1]) $out[] = $out[0];
    return $out;
}
function limpiarGeometria($g, $tol) {
    $tipo = $g['type'];
    if ($tipo === 'Polygon') {
        $anillos = [];
        foreach ($g['coordinates'] as $a) { $r = limpiarAnillo($a, $tol); if ($r) $anillos[] = $r; }
        return $anillos ? ['type' => 'Polygon', 'coordinates' => $anillos] : null;
    }
    if ($tipo === 'MultiPolygon') {
        $partes = [];
        foreach ($g['coordinates'] as $poly) {
            $anillos = [];
            foreach ($poly as $a) { $r = limpiarAnillo($a, $tol); if ($r) $anillos[] = $r; }
            if ($anillos) $partes[] = $anillos;
        }
        return $partes ? ['type' => 'MultiPolygon', 'coordinates' => $partes] : null;
    }
    return null; // esta capa solo trae polígonos
}

$dir = __DIR__ . '/public/geo';
if (!is_dir($dir)) mkdir($dir, 0777, true);

foreach ($CAPAS as $capa) {
    $url = sprintf($BASE, $capa['servicio']);
    fwrite(STDOUT, "Descargando {$capa['servicio']}…\n");
    $crudo = @file_get_contents($url);
    if (!$crudo) { fwrite(STDERR, "  ERROR: no se pudo descargar {$capa['servicio']}\n"); continue; }
    $gj = json_decode($crudo, true);
    if (empty($gj['features'])) { fwrite(STDERR, "  ERROR: respuesta sin features\n"); continue; }

    $feats = [];
    foreach ($gj['features'] as $f) {
        $geo = limpiarGeometria($f['geometry'] ?? [], $capa['tol']);
        if (!$geo) continue;
        $props = [];
        foreach ($capa['campos'] as $origen => $destino) {
            $v = $f['properties'][$origen] ?? null;
            if (is_string($v)) { $v = trim($v); if ($v === '') continue; }   // la fuente usa " " como vacío
            if ($v === null || $v === 0 || $v === '') continue;              // no se guardan campos vacíos
            if (is_float($v)) $v = round($v, 2);
            $props[$destino] = $v;
        }
        $feats[] = ['type' => 'Feature', 'properties' => $props, 'geometry' => $geo];
    }

    $destino = $dir . '/' . $capa['salida'];
    file_put_contents($destino, json_encode(
        ['type' => 'FeatureCollection', 'features' => $feats],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    printf("  OK: %s — %d elementos, %.0f KB (original %.0f KB)\n",
        $capa['salida'], count($feats), filesize($destino) / 1024, strlen($crudo) / 1024);
}
