<?php

namespace App\Services\Consumibles;

use App\Http\Controllers\ConsumiblesController;
use App\Models\ConsumibleImportacion;
use App\Support\CacheVersion;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga de Excel de gasoil en tres pasos: LEER (borrador) → REVISAR (vista previa, enseñar
 * equipos y proyectos) → APLICAR (guardar en consumibles). Nada toca `consumibles` hasta
 * aplicar, y aplicar es todo o nada.
 *
 * Despachos repetidos: los Excel son acumulados (el de septiembre repite abril–agosto) y
 * entre versiones corrigen la cédula, la placa o las dos. Cada despacho lleva tres huellas
 * (con la cédula, con la placa/serial y con la descripción), cada una con su nº de aparición
 * en la hoja: dos tanqueadas iguales el mismo día son #1 y #2, no una repetida. Cómo se
 * combinan para decidir que un despacho ya está: revisar().
 */
class ImportadorCombustible
{
    public function __construct(
        private readonly LectorExcelCombustible $lector = new LectorExcelCombustible(),
    ) {
    }

    // ── 1. Leer ─────────────────────────────────────────────────────────────

    /**
     * @param UploadedFile[] $archivos
     * @throws RuntimeException si ningún archivo trae despachos
     */
    public function crearBorrador(array $archivos, ?int $idUsuario): ConsumibleImportacion
    {
        $ident = new IdentificadorConsumo();
        $hojas = [];
        $errores = [];
        $filas = [];

        foreach ($archivos as $archivo) {
            $nombre = mb_substr($archivo->getClientOriginalName(), 0, 120);
            try {
                $r = $this->lector->leer($archivo->getRealPath(), $nombre);
            } catch (\Throwable $e) {
                $errores[] = "«{$nombre}» no se pudo leer como Excel.";
                continue;
            }
            if ($r['hojas'] === []) {
                $errores[] = "«{$nombre}» no tiene una hoja con columnas FECHA y SALIDA (o VOLUMEN).";
                continue;
            }
            array_push($hojas, ...$r['hojas']);
            foreach ($r['filas'] as $f) {
                $filas[] = $f;
            }
        }

        if ($filas === []) {
            throw new RuntimeException($errores ? implode(' ', $errores) : 'Los archivos no traen despachos de gasoil.');
        }

        $this->ponerHuellas($filas, $ident);

        $imp = new ConsumibleImportacion([
            'ESTADO'     => ConsumibleImportacion::BORRADOR,
            'ARCHIVOS'   => ['hojas' => $hojas, 'errores' => $errores],
            'ID_USUARIO' => $idUsuario,
        ]);
        $imp->guardarFilas($filas);
        $imp->save();

        // Lecturas que nadie confirmó ni descartó: no cargaron nada y sus filas ocupan espacio.
        ConsumibleImportacion::where('ESTADO', ConsumibleImportacion::BORRADOR)
            ->where('created_at', '<', now()->subDays(7))->delete();

        return $imp;
    }

    /**
     * Tres huellas por fila, cada una con su nº de aparición en la hoja (en el orden del Excel):
     *  C = fecha|litros|cédula, E = fecha|litros|placa o serial, D = fecha|litros|descripción.
     */
    private function ponerHuellas(array &$filas, IdentificadorConsumo $ident): void
    {
        $vistas = [];
        $numerar = function (string $hoja, string $k) use (&$vistas): string {
            $vistas[$hoja][$k] = ($vistas[$hoja][$k] ?? 0) + 1;

            return "{$k}#{$vistas[$hoja][$k]}";
        };
        foreach ($filas as &$f) {
            $base = $f['fecha'] . '|' . number_format($f['litros'], 2, '.', '');
            $hoja = $f['archivo'] . '|' . $f['hoja'];
            $desc = substr(TextoConsumo::claveId($f['desc'] ?? ($f['cat']['equipo'] ?? '')), 0, 60);
            $f['huella_ci'] = $f['ci'] !== '' ? $numerar($hoja, "C|{$base}|{$f['ci']}") : null;
            $f['huella_eq'] = $numerar($hoja, "E|{$base}|" . ($ident->identidad($f) ?: '-'));
            $f['huella_desc'] = $desc !== '' ? $numerar($hoja, "D|{$base}|{$desc}") : null;
        }
        unset($f);
    }

    // ── 2. Revisar ──────────────────────────────────────────────────────────

    /**
     * Resuelve cada fila (equipo, frente, ¿ya cargada?) y arma el resumen de la vista previa.
     * Se recalcula en cada apertura: lo enseñado o un equipo recién registrado cuentan ya.
     *
     * Un despacho YA ESTÁ (en la base: «cargado»; en otro archivo de esta carga: «repetido»)
     * si coincide, en el mismo proyecto (o uno de los dos sin proyecto):
     *  - la huella de cédula, o
     *  - la de placa/serial, o
     *  - la de descripción Y la cédula es casi la misma (≤2 dígitos distintos, o vacía en un
     *    lado): entre versiones corrigen a la vez cédula y placa («ci 18820659 / sin serial»
     *    → «ci 18820652 / 2270»).
     * Una huella igual en OTRO proyecto no es el mismo despacho: el mismo chofer puede sacar
     * 40 L el mismo día en dos tanques distintos.
     */
    public function revisar(ConsumibleImportacion $imp): array
    {
        $ident = new IdentificadorConsumo();
        $filas = $imp->filas();
        $hojas = $imp->ARCHIVOS['hojas'] ?? [];

        // Qué columna trae el proyecto en cada hoja (FRENTE en Agua Salada, PROYECTO en las demás).
        $porHoja = [];
        foreach ($filas as $i => $f) {
            $porHoja[$f['archivo'] . '|' . $f['hoja']][] = $i;
        }
        $principal = [];
        foreach ($porHoja as $k => $idx) {
            $principal[$k] = $ident->columnaPrincipal(array_map(fn ($i) => $filas[$i], $idx));
        }

        // Prioridad: el archivo más reciente primero (trae las correcciones de cédulas y placas).
        $hasta = [];
        foreach ($hojas as $h) {
            $hasta[$h['archivo']] = max($hasta[$h['archivo']] ?? '', (string) $h['hasta']);
        }
        $orden = array_keys($filas);
        usort($orden, fn ($a, $b) => [$hasta[$filas[$b]['archivo']] ?? '', $a] <=> [$hasta[$filas[$a]['archivo']] ?? '', $b]);

        [$enBase, $ocupadas] = $this->huellasEnBase($filas);
        $enCarga = ['C' => [], 'E' => [], 'D' => []];

        $res = [];
        foreach ($orden as $i) {
            $f = $filas[$i];
            $eq = $ident->resolverEquipo($f);
            $fr = $ident->resolverFrente($f, $principal[$f['archivo'] . '|' . $f['hoja']]);
            $yo = ['fr' => (int) $fr['id_frente'], 'ci' => $f['ci']];

            $estado = 'nuevo';
            if ($this->yaEsta($f, $yo, $enBase)) {
                $estado = 'cargado';
            } elseif ($this->yaEsta($f, $yo, $enCarga)) {
                $estado = 'repetido';
            }

            // Lo que se guarda en las columnas UNIQUE: la huella tal cual; si ya la tiene un
            // despacho de OTRO proyecto, con «@frente» detrás (al comparar se quita).
            $guardar = ['C' => null, 'E' => null];
            if ($estado === 'nuevo') {
                foreach (['C' => $f['huella_ci'], 'E' => $f['huella_eq'], 'D' => $f['huella_desc']] as $t => $h) {
                    if ($h === null) {
                        continue;
                    }
                    $enCarga[$t][$h][] = $yo;
                    if ($t === 'D') {
                        continue;
                    }
                    $valor = isset($ocupadas[$t][$h]) ? "{$h}@{$yo['fr']}" : $h;
                    if (!isset($ocupadas[$t][$valor])) {
                        $ocupadas[$t][$valor] = true;
                        $guardar[$t] = $valor;
                    }
                }
            }
            $res[$i] = ['estado' => $estado, 'equipo' => $eq, 'frente' => $fr, 'huella_ci' => $guardar['C'], 'huella_eq' => $guardar['E']];
        }
        ksort($res);

        return [
            'filas'     => $filas,
            'res'       => $res,
            'resumen'   => $this->resumir($filas, $res, $hojas, $ident),
            'frentes'   => $ident->frentes(),
        ];
    }

    /** ¿La fila coincide con algo de $hay (C|E|D → huella → [['fr','ci'], …])? Ver revisar(). */
    private function yaEsta(array $f, array $yo, array $hay): bool
    {
        $mismoProyecto = fn (array $o) => $o['fr'] === 0 || $yo['fr'] === 0 || $o['fr'] === $yo['fr'];
        foreach (['C' => $f['huella_ci'], 'E' => $f['huella_eq']] as $t => $h) {
            foreach ($h !== null ? ($hay[$t][$h] ?? []) : [] as $o) {
                if ($mismoProyecto($o)) {
                    return true;
                }
            }
        }
        foreach ($f['huella_desc'] !== null ? ($hay['D'][$f['huella_desc']] ?? []) : [] as $o) {
            if ($mismoProyecto($o) && self::cedulaParecida($o['ci'], $yo['ci'])) {
                return true;
            }
        }

        return false;
    }

    /** Misma cédula salvo un error de tipeo (≤2 dígitos), o vacía en uno de los dos lados. */
    private static function cedulaParecida(string $a, string $b): bool
    {
        return $a === '' || $b === '' || levenshtein($a, $b) <= 2;
    }

    /**
     * Huellas de lo que ya está en la base en el rango de fechas de la carga: las guardadas
     * por cargas anteriores y, para lo cargado a mano (sin huella), C y E calculadas igual.
     * @return array{0: array, 1: array} [C|E|D → huella → [['fr','ci'], …],
     *         C|E → valor guardado => true (lo que ya ocupa las columnas UNIQUE)]
     */
    private function huellasEnBase(array $filas): array
    {
        $fechas = array_column($filas, 'fecha');
        $hay = ['C' => [], 'E' => [], 'D' => []];
        $ocupadas = ['C' => [], 'E' => []];
        if ($fechas === []) {
            return [$hay, $ocupadas];
        }
        $q = DB::table('consumibles')->where('TIPO_CONSUMIBLE', 'GASOIL')
            ->whereBetween('FECHA', [min($fechas), max($fechas)])
            ->orderBy('ID_CONSUMIBLE')
            ->select('FECHA', 'CANTIDAD', 'RESP_CI', 'IDENTIFICADOR', 'ID_FRENTE', 'ID_IMPORTACION', 'HUELLA_CI', 'HUELLA_EQ', 'HUELLA_DESC');
        $vistas = [];
        foreach ($q->cursor() as $c) {
            $o = ['fr' => (int) $c->ID_FRENTE, 'ci' => TextoConsumo::digitos($c->RESP_CI ?? '')];
            if ($c->ID_IMPORTACION !== null) {
                foreach (['C' => $c->HUELLA_CI, 'E' => $c->HUELLA_EQ, 'D' => $c->HUELLA_DESC] as $t => $h) {
                    if ($h === null) {
                        continue;
                    }
                    $hay[$t][explode('@', $h)[0]][] = $o;
                    if ($t !== 'D') {
                        $ocupadas[$t][$h] = true;
                    }
                }
                continue;
            }
            $base = substr((string) $c->FECHA, 0, 10) . '|' . number_format((float) $c->CANTIDAD, 2, '.', '');
            if ($o['ci'] !== '') {
                $k = "C|{$base}|{$o['ci']}";
                $vistas[$k] = ($vistas[$k] ?? 0) + 1;
                $hay['C']["{$k}#{$vistas[$k]}"][] = $o;
            }
            $k = "E|{$base}|" . (substr(TextoConsumo::claveId($c->IDENTIFICADOR), 0, 60) ?: '-');
            $vistas[$k] = ($vistas[$k] ?? 0) + 1;
            $hay['E']["{$k}#{$vistas[$k]}"][] = $o;
        }

        return [$hay, $ocupadas];
    }

    private function resumir(array $filas, array $res, array $hojas, IdentificadorConsumo $ident): array
    {
        $tot = ['nuevo' => [0, 0.0], 'cargado' => [0, 0.0], 'repetido' => [0, 0.0]];
        $meses = $porFrente = $sinFrente = $sinEquipo = $corregidas = $porHoja = [];
        $identificados = $noEquipo = 0;

        foreach ($res as $i => $r) {
            $f = $filas[$i];
            $tot[$r['estado']][0]++;
            $tot[$r['estado']][1] += $f['litros'];
            $hoja = $f['archivo'] . '|' . $f['hoja'];
            $porHoja[$hoja][$r['estado']] = ($porHoja[$hoja][$r['estado']] ?? 0) + 1;
            if ($r['estado'] !== 'nuevo') {
                continue;
            }
            $mes = substr($f['fecha'], 0, 7);
            $meses[$mes][0] = ($meses[$mes][0] ?? 0) + 1;
            $meses[$mes][1] = ($meses[$mes][1] ?? 0) + $f['litros'];

            if ($r['frente']['id_frente'] !== null) {
                $id = $r['frente']['id_frente'];
                $porFrente[$id][0] = ($porFrente[$id][0] ?? 0) + 1;
                $porFrente[$id][1] = ($porFrente[$id][1] ?? 0) + $f['litros'];
            } else {
                $k = $r['frente']['clave'];
                $sinFrente[$k] ??= ['clave' => $k, 'filas' => 0, 'litros' => 0.0, 'archivo' => $f['archivo']];
                $sinFrente[$k]['filas']++;
                $sinFrente[$k]['litros'] += $f['litros'];
            }

            $e = $r['equipo'];
            if ($e['estado'] === 'CONFIRMADO') {
                $identificados++;
            } elseif ($e['estado'] === 'NO_EQUIPO') {
                $noEquipo++;
            } else {
                $k = $e['clave'];
                $sinEquipo[$k] ??= ['clave' => $k, 'filas' => 0, 'litros' => 0.0, 'aviso' => $e['etiqueta'], 'frente' => $ident->nombreFrente($r['frente']['id_frente'])];
                $sinEquipo[$k]['filas']++;
                $sinEquipo[$k]['litros'] += $f['litros'];
            }
            foreach ($f['avisos'] as $a) {
                $corregidas[] = ['origen' => "{$f['archivo']} · {$f['hoja']} · fila {$f['fila']}", 'aviso' => $a];
            }
        }

        ksort($meses);
        uasort($porFrente, fn ($a, $b) => $b[1] <=> $a[1]);
        $porLitros = fn ($a, $b) => $b['litros'] <=> $a['litros'];
        uasort($sinFrente, $porLitros);
        uasort($sinEquipo, $porLitros);

        return [
            'totales'       => $tot,
            'meses'         => $meses,
            'por_frente'    => $porFrente,
            'sin_frente'    => array_values($sinFrente),
            'sin_equipo'    => array_values($sinEquipo),
            'identificados' => $identificados,
            'no_equipo'     => $noEquipo,
            'corregidas'    => $corregidas,
            'por_hoja'      => $porHoja,   // «archivo|hoja» → nuevo/cargado/repetido
            'hojas'         => $hojas,
        ];
    }

    // ── Enseñar ─────────────────────────────────────────────────────────────

    /**
     * Guarda lo que el usuario asignó en la revisión. Vale para esta carga y las siguientes.
     * @param array<string,int>    $frentes clave → ID_FRENTE (un frente eliminado se ignora)
     * @param array<string,string> $equipos clave → 'E:12' | 'A:3' | 'NO' (si el equipo ya no
     *                                       existe, se borra lo enseñado para esa clave)
     */
    public function ensenar(array $frentes, array $equipos, ?int $idUsuario): int
    {
        $n = 0;
        $ahora = now();
        DB::transaction(function () use ($frentes, $equipos, $idUsuario, $ahora, &$n) {
            foreach ($frentes as $clave => $id) {
                $clave = TextoConsumo::norm($clave);
                if ($clave === '') {
                    continue;
                }
                if (!DB::table('frentes_trabajo')->where('ID_FRENTE', $id)->whereNull('deleted_at')->exists()) {
                    continue;
                }
                $this->guardarAlias('FRENTE', $clave, ['ID_FRENTE' => (int) $id, 'ID_EQUIPO' => null, 'ID_AUXILIAR' => null, 'NO_ES_EQUIPO' => false], $idUsuario, $ahora);
                $n++;
            }
            foreach ($equipos as $clave => $ref) {
                $clave = mb_substr(TextoConsumo::norm($clave), 0, 191);
                if ($clave === '') {
                    continue;
                }
                $datos = $this->datosAliasEquipo((string) $ref);
                if ($datos === null) {
                    DB::table('consumibles_alias')->where(['TIPO' => 'EQUIPO', 'TEXTO' => $clave])->delete();
                    continue;
                }
                $this->guardarAlias('EQUIPO', $clave, $datos + ['ID_FRENTE' => null], $idUsuario, $ahora);
                $n++;
            }
        });

        return $n;
    }

    private function guardarAlias(string $tipo, string $clave, array $datos, ?int $idUsuario, $ahora): void
    {
        $donde = ['TIPO' => $tipo, 'TEXTO' => $clave];
        $datos += ['ID_USUARIO' => $idUsuario, 'updated_at' => $ahora];
        if (DB::table('consumibles_alias')->where($donde)->exists()) {
            DB::table('consumibles_alias')->where($donde)->update($datos);
        } else {
            DB::table('consumibles_alias')->insert($donde + $datos + ['created_at' => $ahora]);
        }
    }

    /** 'E:12' / 'A:3' / 'NO' → columnas del alias; null si no vale (o es '' = quitar). */
    private function datosAliasEquipo(string $ref): ?array
    {
        if ($ref === 'NO') {
            return ['ID_EQUIPO' => null, 'ID_AUXILIAR' => null, 'NO_ES_EQUIPO' => true];
        }
        if (preg_match('/^E:(\d+)$/', $ref, $m) && DB::table('equipos')->where('ID_EQUIPO', $m[1])->whereNull('deleted_at')->exists()) {
            return ['ID_EQUIPO' => (int) $m[1], 'ID_AUXILIAR' => null, 'NO_ES_EQUIPO' => false];
        }
        if (preg_match('/^A:(\d+)$/', $ref, $m) && DB::table('equipos_auxiliares')->where('ID_AUXILIAR', $m[1])->whereNull('deleted_at')->exists()) {
            return ['ID_EQUIPO' => null, 'ID_AUXILIAR' => (int) $m[1], 'NO_ES_EQUIPO' => false];
        }

        return null;
    }

    // ── 3. Aplicar / deshacer ───────────────────────────────────────────────

    /**
     * Guarda los despachos NUEVOS de los meses elegidos. Todo o nada; la revisión se rehace
     * dentro de la transacción (nada de lo que mande el navegador se usa como dato).
     * @param string[]|null $meses 'AAAA-MM'; null = todos
     * @return array{insertados:int, litros:float}
     */
    public function aplicar(ConsumibleImportacion $imp, ?array $meses): array
    {
        return $this->deUnoEnUno(fn () => DB::transaction(function () use ($imp, $meses) {
            $imp = ConsumibleImportacion::whereKey($imp->getKey())->lockForUpdate()->firstOrFail();
            if ($imp->ESTADO !== ConsumibleImportacion::BORRADOR) {
                throw new RuntimeException('Esta carga ya fue aplicada o descartada.');
            }
            $rev = $this->revisar($imp);
            $ahora = now();
            $lote = [];
            $insertados = 0;
            $litros = 0.0;

            foreach ($rev['res'] as $i => $r) {
                $f = $rev['filas'][$i];
                if ($r['estado'] !== 'nuevo' || ($meses !== null && !in_array(substr($f['fecha'], 0, 7), $meses, true))) {
                    continue;
                }
                $e = $r['equipo'];
                $notas = array_filter([
                    $e['estado'] === 'NO_EQUIPO' ? 'No es un equipo (enseñado en la carga).' : null,
                    $e['estado'] === 'SIN_MATCH' && $e['etiqueta'] ? $e['etiqueta'] . '.' : null,
                    $f['nota'] ? "Nota de despacho {$f['nota']}." : null,
                    ...$f['avisos'],
                ]);
                $lote[] = [
                    'FECHA'           => $f['fecha'],
                    'IDENTIFICADOR'   => $e['clave'] !== '' ? $e['clave'] : null,
                    'RESP_NOMBRE'     => $f['resp'] !== null ? mb_substr($f['resp'], 0, 150) : null,
                    'RESP_CI'         => $f['ci'] !== '' ? substr($f['ci'], 0, 20) : null,
                    'CANTIDAD'        => $f['litros'],
                    'ORIGEN'          => mb_substr("{$f['archivo']} · {$f['hoja']} · fila {$f['fila']}", 0, 255),
                    'ACTIVIDAD'       => $this->actividad($f),
                    'TIPO_CONSUMIBLE' => 'GASOIL',
                    'UNIDAD'          => 'LITROS',
                    'ID_FRENTE'       => $r['frente']['id_frente'],
                    'ID_EQUIPO'       => $e['id_equipo'],
                    'ID_AUXILIAR'     => $e['id_auxiliar'],
                    'ESTADO_EQUIPO'   => $e['estado'] === 'SIN_MATCH' ? 'SIN_MATCH' : 'CONFIRMADO',
                    'NOTAS'           => $notas ? implode(' ', $notas) : null,
                    'ID_IMPORTACION'  => $imp->ID_IMPORTACION,
                    'HUELLA_CI'       => $r['huella_ci'],
                    'HUELLA_EQ'       => $r['huella_eq'],
                    'HUELLA_DESC'     => $f['huella_desc'],
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ];
                $insertados++;
                $litros += $f['litros'];
                if (count($lote) === 500) {
                    DB::table('consumibles')->insert($lote);
                    $lote = [];
                }
            }
            if ($lote !== []) {
                DB::table('consumibles')->insert($lote);
            }

            $archivos = $imp->ARCHIVOS;
            $archivos['resumen'] = [
                'totales' => $rev['resumen']['totales'],
                'meses'   => $meses,
            ];
            $imp->ARCHIVOS = $archivos;
            $imp->ESTADO = ConsumibleImportacion::APLICADA;
            $imp->INSERTADOS = $insertados;
            $imp->LITROS = round($litros, 2);
            $imp->APLICADA_EN = $ahora;
            $imp->guardarFilas(null);
            $imp->save();

            DB::afterCommit(fn () => CacheVersion::bump(ConsumiblesController::DATA_VER_KEY));

            return ['insertados' => $insertados, 'litros' => round($litros, 2)];
        }));
    }

    /** Proyecto/actividad y frente/zona tal como venían en el Excel. */
    private function actividad(array $f): ?string
    {
        $partes = array_values(array_unique(array_filter([$f['proyecto'], $f['frente']])));

        return $partes ? mb_substr(implode(' · ', $partes), 0, 255) : null;
    }

    /** Borra los consumos de una carga aplicada. @return int filas borradas */
    public function deshacer(ConsumibleImportacion $imp): int
    {
        return $this->deUnoEnUno(fn () => DB::transaction(function () use ($imp) {
            $imp = ConsumibleImportacion::whereKey($imp->getKey())->lockForUpdate()->firstOrFail();
            if ($imp->ESTADO !== ConsumibleImportacion::APLICADA) {
                throw new RuntimeException('Solo se puede deshacer una carga aplicada.');
            }
            $n = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->delete();
            $imp->ESTADO = ConsumibleImportacion::DESHECHA;
            $imp->save();
            DB::afterCommit(fn () => CacheVersion::bump(ConsumiblesController::DATA_VER_KEY));

            return $n;
        }));
    }

    /**
     * Aplicar y deshacer, de a uno en todo el sistema. Lo «nuevo» se decide comparando con lo
     * que ya está en la base: dos cargas guardándose a la vez (dos usuarios con los mismos
     * archivos) se verían vacías la una a la otra y meterían el mismo despacho dos veces.
     */
    private function deUnoEnUno(callable $trabajo): mixed
    {
        try {
            return Cache::lock('consumibles:importar', 300)->block(60, $trabajo);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Otra carga de Excel se está guardando en este momento. Intenta de nuevo en un minuto.');
        }
    }
}
