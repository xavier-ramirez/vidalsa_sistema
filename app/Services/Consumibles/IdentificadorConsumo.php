<?php

namespace App\Services\Consumibles;

use App\Casts\MojibakeFix;
use Illuminate\Support\Facades\DB;

/**
 * Decide, para cada despacho leído de un Excel, QUÉ equipo surtió y a QUÉ proyecto (frente)
 * va el consumo. Todo en memoria: los índices se arman una vez por petición (≈1.500 equipos
 * y auxiliares, ≈50 frentes) y luego cada fila se resuelve sin consultas.
 *
 * Equipo, en este orden (gana el primero que dé UN solo equipo):
 *  1. Lo que el usuario ya enseñó (consumibles_alias): «AUTOBUS YUTONG S/P» = tal equipo, o
 *     «LAVADO DE EQUIPOS» = no es un equipo.
 *  2. Placa, serial de chasis o motor, código de patio o etiqueta, o código/serial de un
 *     auxiliar, exactos. O y 0 cuentan igual; los ceros a la izquierda de un número, no.
 *     Si el texto da varios equipos, desempata el tipo que dice el Excel.
 *  3. Los últimos dígitos del serial cuando el tipo coincide: «PAYLOADER 1941» → el payloader
 *     cuyo serial termina en 1941. El tipo se reconoce por sinónimos (FAMILIAS): lo que en
 *     el sistema es MOTONIVELADORA, en el Excel puede venir como PATROL.
 * Si nada da un único equipo, queda «por identificar» con el texto del Excel.
 *
 * Proyecto: el texto de PROYECTO/ACTIVIDAD o de FRENTE (sin lo que va entre paréntesis, que
 * suele ser el campo o la zona: «TRANSVERSALES AYACUCHO ( SAN TOME)»), contra: lo enseñado,
 * el nombre exacto del frente y las REGLAS de abajo.
 */
class IdentificadorConsumo
{
    /**
     * Familias de tipo de equipo: palabra(s) que aparecen en el Excel → tipos del sistema
     * (tipo_equipos.nombre normalizado; «CAMION*» = todos los que empiezan por CAMION) y
     * tipos de auxiliar (equipos_auxiliares.TIPO).
     */
    public const FAMILIAS = [
        'MOTONIVELADORA' => [['PATROL', 'MOTONIVELADORA', 'MOTONIVEL', 'MOTO NIVELADORA', 'NIVELADORA', 'GRADER'], ['MOTONIVELADORA'], []],
        'PAYLOADER'      => [['PAYLOADER', 'PAY LOADER', 'PAYLOAD', 'CARGADOR', 'CARGADORA', 'PALA'], ['PAYLOADER'], []],
        'EXCAVADORA'     => [['EXCAVADORA', 'JUMBO', 'MINIEXCAVADORA', 'MINI EXCAVADORA'], ['EXCAVADORA', 'MINI EXCAVADORAS'], []],
        'RETRO'          => [['RETRO', 'RETROEXCAVADORA', 'RETROEXCAVADOR'], ['RETROEXCAVADORA'], []],
        'ORUGA'          => [['BULLDOZER', 'BULDOZER', 'BULDOCER', 'ORUGA', 'D6', 'D7', 'D8', 'SD16', 'SD22', 'SD32'], ['TRACTOR DE ORUGA'], []],
        'CAMIONETA'      => [['CAMIONETA', 'PICK UP', 'PICKUP', 'HILUX', 'TRITON'], ['CAMIONETA'], []],
        'VOLTEO'         => [['VOLTEO', 'VOLQUETA', 'VOLTEOS', 'DUMPER'], ['VOLTEO', 'VOLTEO HIDROJET', 'VOLTEO HIDROJECT', 'BATEA/VOLQUETA'], []],
        'CHUTO'          => [['CHUTO', 'TRACTO CAMION', 'TRACTOCAMION', 'GANDOLA'], ['CHUTO', 'CHUTO CON BRAZO 16 TON'], []],
        'BATEA'          => [['BATEA'], ['BATEA', 'BATEA/SILOS', 'BATEA/VOLQUETA', 'REMOLQUE/BATEA'], []],
        'BUS'            => [['AUTOBUS', 'BUS', 'MINIBUS', 'MINI BUS', 'BUSETA', 'COASTER', 'ENCAVA', 'YUTONG', 'VAN'], ['AUTOBUS', 'VAN'], []],
        'GRUA'           => [['GRUA', 'TELESCOPICA', 'TELECOPICA'], ['CAMION GRUA', 'GRUA REMOLQUE', 'GRUA DE TERCEROS'], []],
        'SIDEBOOM'       => [['SIDEBOOM', 'SIDE BOOM', 'TIENDE TUBO', 'TIENDETUBO'], ['SIDEBOOM'], []],
        'COMPACTADOR'    => [['VIBRO', 'VIBROCOMPACTADORA', 'COMPACTADORA', 'COMPACTADOR', 'ROLO', 'APLANADORA'], ['VIBROCOMPACTADORA', 'COMPACTADORA'], []],
        'CISTERNA'       => [['CISTERNA'], ['CAMION CISTERNA', 'MAQUINA CISTERNA'], []],
        'CAMA_BAJA'      => [['CAMA BAJA', 'LOWBOY', 'LOW BOY'], ['CAMA BAJA', 'LOWBOY'], []],
        'PLATAFORMA'     => [['PLATAFORMA'], ['CAMION PLATAFORMA', 'CAMION PLATF/BARANDA', 'CAMION PLATF/ESTRUC/HIERRO', 'CAMION PLATAF/B HIDRAULICO', 'PLATAFORMA / BRAZO HIDRAULICO'], []],
        'CAMION'         => [['CAMION', 'CMION', 'CAMIONES'], ['CAMION*'], []],
        'AMBULANCIA'     => [['AMBULANCIA'], ['AMBULANCIA'], []],
        'VACUUM'         => [['VACUUM', 'VACCUM'], ['VACUUM'], []],
        'CUADRILLERA'    => [['CUADRILLERA'], ['CUADRILLERA'], []],
        'TRACTOR'        => [['TRACTOR AGRICOLA'], ['TRACTOR AGRICOLA'], []],
        'SOLDADORA'      => [['MAQUINA DE SOLDAR', 'SOLDADORA', 'SOLDAR'], ['CAMION DE SOLDADURA'], ['MAQUINA_DE_SOLDAR']],
        'GENERADOR'      => [['GENERADOR', 'PLANTA ELECTRICA'], ['GENERADOR DIESEL'], ['PLANTA_ELECTRICA']],
        'COMPRESOR'      => [['COMPRESOR'], [], ['COMPRESOR']],
        'LUMINARIA'      => [['LUMINARIA', 'TORRE DE ILUMINACION', 'TORRE ILUMINACION'], ['LUMINARIA'], ['LUMINARIA']],
        'HIDROJET'       => [['HIDROJET', 'HIDROJECT'], ['CAMION HIDROJET', 'VOLTEO HIDROJET', 'VOLTEO HIDROJECT'], ['HIDROJET']],
        'MONTACARGA'     => [['MONTACARGA', 'MONTACARGAS'], [], ['MONTACARGA']],
        'MANLIFT'        => [['MANLIFT'], [], ['MANLIFT']],
    ];

    /**
     * Reglas de proyecto: texto del Excel (normalizado) → nombre del frente. Se prueban
     * después de lo enseñado y del nombre exacto. Van por NOMBRE, no por ID, para que sirvan
     * igual en el servidor; si el frente no existe, la regla no hace nada.
     */
    public const REGLAS_FRENTE = [
        ['/AGUA SALADA/', "TUBERIA DE 12'' AGUA SALADA"],
        ['/VELADERO/', "TUBERIA DE 30'' VELADERO TRAMO I"],
        ['/\bCOMOR\b|SANEADO/', 'COMOR FASE III'],
        ['/DRAGADO/', 'DRAGADO FASE II'],
        ['/CORTA ?FUEGO.*CARABOBO/', 'CORTAFUEGO CARABOBO FASE II'],
        ['/CORTA ?FUEGO/', 'CORTAFUEGO AYACUCHO FASE II'],
        ['/TRANSVERSALES? .*CARABOBO/', 'TRANSVERSALES CARABOBO'],
        ['/TRANSVERSALES? .*AYACUCHO/', 'TRANSVERSALES AYACUCHO'],
        ['/SINOVENSA/', 'ALQUILER SINOVENSA'],
        ['/\bXP\b.*CARABOBO/', 'XP CARABOBO'],
        ['/ORINOCO OIL/', 'ORINOCO OILS'],
        ['/CHUTO.*BATEA/', 'CHUTO + BATEA'],
        ['/ESTACION.*VALVULA/', 'ESTACION DE VALVULAS'],
        ['/PATIO (1|I|UNO) EL TIGRE|PATIO EL TIGRE/', 'PATIO I EL TIGRE'],
        ['/PATIO MATURIN/', 'PATIO MATURIN'],
        ['/OLEODUCTO .*30 ?X ?14/', 'OLEODUCTO DE 30X14'],
        ['/TRASEGADO/', 'TRASEGADO DE CRUDO'],
        ['/PLANTA DE ASFALTO/', 'PLANTA DE ASFALTO'],
    ];

    private const PLACA_ROTULADA = '/PLAC[A]?\W*([A-Z0-9]{5,8})\b/';
    private const SERIAL_ROTULADO = '/SERIAL\W*([A-Z0-9]{3,20})\b/';
    private const PLACAS = [
        '/\b([A-Z]\d{2}[A-Z]{2}\d[A-Z])\b/',     // A45AF4Y
        '/\b([A-Z]{2}\d{2}[A-Z]{2}\d[A-Z])\b/',  // AA12BC3D
        '/\b([A-Z]{2}\d{3}[A-Z]{2})\b/',         // AB449EW
        '/\b(\d{2}[A-Z]{2}\d[A-Z]{2})\b/',       // 07AB6EG
        '/\b([A-Z]\d{4}[A-Z]{2})\b/',            // A1893AG
    ];
    private const BASURA = ['', '0', '00', '000', 'SN', 'SP', 'SS', 'NA', 'SINPLACA', 'SINSERIAL', 'NOTIENE', 'N0', 'X'];

    /** claveId → ['E:12' => 'placa', 'A:3' => 'aux_serial', …] */
    private array $indice = [];
    /** nivel (0 = serial de chasis o de auxiliar, 1 = serial de motor) → últimos 4 dígitos → [ref => claveId] */
    private array $sufijos = [[], []];
    /** ref → ['tipo' => nombre normalizado, 'aux' => bool, 'etiqueta' => texto] */
    private array $unidades = [];
    /** nombre de frente compacto → ID_FRENTE */
    private array $frentesPorNombre = [];
    /** ID_FRENTE → nombre */
    private array $nombresFrente = [];
    private array $aliasEquipo = [];
    private array $aliasFrente = [];
    /** token de modelo («950G», «140H») → [tipo normalizado => true] de los equipos con ese modelo */
    private array $tiposPorModelo = [];
    /** palabras conocidas para corregir errores de tipeo («PAYLODER», «TRANSVERSLES») */
    private array $vocabTipos = [];
    private array $vocabFrentes = [];

    public function __construct()
    {
        $this->cargarEquipos();
        $this->cargarFrentes();
        $this->cargarAlias();
    }

    // ── Carga de índices ────────────────────────────────────────────────────

    private function cargarEquipos(): void
    {
        $placas = DB::table('documentacion')->whereNotNull('PLACA')->where('PLACA', '!=', '')
            ->pluck('PLACA', 'ID_EQUIPO');

        $equipos = DB::table('equipos')
            ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
            ->whereNull('equipos.deleted_at')
            ->select('equipos.ID_EQUIPO', 'equipos.CODIGO_PATIO', 'equipos.NUMERO_ETIQUETA', 'equipos.SERIAL_CHASIS',
                'equipos.SERIAL_DE_MOTOR', 'equipos.MARCA', 'equipos.MODELO', 'tipo_equipos.nombre as tipo')
            ->get();
        foreach ($equipos as $e) {
            $ref = 'E:' . $e->ID_EQUIPO;
            $placa = $placas[$e->ID_EQUIPO] ?? null;
            if (strlen(TextoConsumo::clave($placa)) < 3) {
                $placa = null;   // «........», «S/P»: no es una placa
            }
            $this->unidades[$ref] = [
                'tipo' => TextoConsumo::norm($e->tipo),
                'aux' => false,
                'etiqueta' => trim(implode(' · ', array_filter([
                    TextoConsumo::norm($e->tipo), trim($e->MARCA . ' ' . $e->MODELO), $placa ?: $e->SERIAL_CHASIS ?: $e->CODIGO_PATIO,
                ]))),
            ];
            $this->indexar($ref, $placa, 'placa');
            $this->indexar($ref, $e->SERIAL_CHASIS, 'serial', 0);
            $this->indexar($ref, $e->SERIAL_DE_MOTOR, 'motor', 1);
            $this->indexar($ref, $e->CODIGO_PATIO, 'codigo');
            $this->indexar($ref, $e->NUMERO_ETIQUETA, 'etiqueta');
            foreach (self::tokensModelo($e->MODELO) as $tok) {
                $this->tiposPorModelo[$tok][$this->unidades[$ref]['tipo']] = true;
            }
        }
        foreach (self::FAMILIAS as [$palabras]) {
            foreach ($palabras as $p) {
                foreach (explode(' ', $p) as $w) {
                    $this->vocabTipos[$w] = true;
                }
            }
        }

        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->select('ID_AUXILIAR', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO')->get();
        foreach ($aux as $a) {
            $ref = 'A:' . $a->ID_AUXILIAR;
            $this->unidades[$ref] = [
                'tipo' => TextoConsumo::norm($a->TIPO),
                'aux' => true,
                'etiqueta' => trim(implode(' · ', array_filter([
                    str_replace('_', ' ', (string) $a->TIPO), trim($a->MARCA . ' ' . $a->MODELO), $a->CODIGO_INTERNO ?: $a->SERIAL,
                ]))),
            ];
            $this->indexar($ref, $a->SERIAL, 'serial', 0);
            $this->indexar($ref, $a->CODIGO_INTERNO, 'codigo');
        }
    }

    /** @param ?int $nivelSufijo null = no entra a la búsqueda por final del serial */
    private function indexar(string $ref, ?string $valor, string $modo, ?int $nivelSufijo = null): void
    {
        $k = TextoConsumo::claveId($valor);
        if (strlen($k) < 3 || in_array($k, self::BASURA, true)) {
            return;
        }
        $this->indice[$k][$ref] ??= $modo;
        if (ctype_digit($k) && ltrim($k, '0') !== $k && strlen(ltrim($k, '0')) >= 3) {
            $this->indice[ltrim($k, '0')][$ref] ??= $modo;
        }
        if ($nivelSufijo !== null && preg_match('/(\d{4})$/', $k, $m)) {
            $this->sufijos[$nivelSufijo][$m[1]][$ref] = $k;
        }
    }

    private function cargarFrentes(): void
    {
        $frentes = DB::table('frentes_trabajo')->whereNull('deleted_at')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        foreach ($frentes as $f) {
            $nombre = MojibakeFix::fix($f->NOMBRE_FRENTE);
            $this->nombresFrente[$f->ID_FRENTE] = $nombre;
            $this->frentesPorNombre[TextoConsumo::clave($nombre)] ??= (int) $f->ID_FRENTE;
            foreach (preg_split('/[^A-Z]+/', TextoConsumo::norm($nombre), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $this->vocabFrentes[$w] = true;
            }
        }
        foreach (self::REGLAS_FRENTE as [$re, $nombre]) {
            preg_match_all('/[A-Z]{4,}/', $re . ' ' . $nombre, $m);
            foreach ($m[0] as $w) {
                $this->vocabFrentes[$w] = true;
            }
        }
    }

    /** Tokens de un modelo que lo distinguen: 3+ caracteres con algún dígito («950G», «140H», «320»). */
    private static function tokensModelo(?string $modelo): array
    {
        $out = [];
        foreach (preg_split('/[^A-Z0-9]+/', TextoConsumo::norm($modelo), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (strlen($t) >= 3 && preg_match('/\d/', $t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Corrige errores de tipeo palabra por palabra contra un vocabulario: «PAYLODER» → PAYLOADER,
     * «TRANSVERSLES AYCUCHO» → TRANSVERSALES AYACUCHO. Solo palabras de 5+ letras, misma
     * inicial y a 1 letra de distancia (2 si la palabra tiene 8+), para no confundir palabras cortas.
     */
    private static function corregir(string $texto, array $vocab): string
    {
        return preg_replace_callback('/[A-Z]{5,}/', function ($m) use ($vocab) {
            $w = $m[0];
            if (isset($vocab[$w])) {
                return $w;
            }
            $tope = strlen($w) >= 8 ? 2 : 1;
            $mejor = null;
            $dist = $tope + 1;
            foreach ($vocab as $v => $_) {
                if ($v[0] !== $w[0] || abs(strlen($v) - strlen($w)) > $tope || strlen($v) < 5) {
                    continue;
                }
                $d = levenshtein($w, $v);
                if ($d < $dist) {
                    [$mejor, $dist] = [$v, $d];
                }
            }

            return $mejor ?? $w;
        }, $texto);
    }

    private function cargarAlias(): void
    {
        foreach (DB::table('consumibles_alias')->get() as $a) {
            if ($a->TIPO === 'FRENTE') {
                if ($a->ID_FRENTE && isset($this->nombresFrente[$a->ID_FRENTE])) {
                    $this->aliasFrente[$a->TEXTO] = (int) $a->ID_FRENTE;
                }
            } else {
                $this->aliasEquipo[$a->TEXTO] = $a;
            }
        }
    }

    // ── Equipo ──────────────────────────────────────────────────────────────

    /** Textos que podrían identificar al equipo, del más fiable al menos. */
    public function candidatos(array $f): array
    {
        $c = [];
        foreach (['placa', 'ident', 'serial'] as $k) {
            if (!empty($f[$k])) {
                $t = TextoConsumo::norm($f[$k]);
                preg_match_all(self::PLACA_ROTULADA, $t, $m1);
                preg_match_all(self::SERIAL_ROTULADO, $t, $m2);
                array_push($c, ...$m1[1], ...$m2[1]);
                $c[] = $t;
            }
        }
        if (!empty($f['cat'])) {
            $c[] = (string) ($f['cat']['placa'] ?? '');
            $c[] = (string) ($f['cat']['serial'] ?? '');
        }
        $desc = TextoConsumo::norm($f['desc'] ?? '');
        preg_match_all(self::PLACA_ROTULADA, $desc, $m);
        array_push($c, ...$m[1]);
        foreach (self::PLACAS as $re) {
            preg_match_all($re, $desc, $m);
            array_push($c, ...$m[1]);
        }
        preg_match_all('/#\s*0*(\d{1,5})\b/', $desc, $m);
        array_push($c, ...$m[1]);
        // Al final, para no cambiar el primero (la identidad de la huella, ver identidad()): un
        // IDENTIFICADOR guardado por la carga es «descripción | placa | serial», cada trozo un
        // valor de celda tal cual; y si en Consumibles alguien lo cambia a mano por la placa o el
        // serial completo, el texto entero es ese valor (así lo resuelve «Ejecutar Match»).
        // Solo trozos con algún dígito: «BUS» o «PALA» solos no son una placa ni un código.
        array_push($c, ...preg_grep('/\d/', explode(' | ', $desc)));

        $out = [];
        foreach ($c as $x) {
            $k = TextoConsumo::claveId($x);
            if (strlen($k) >= 3 && !in_array($k, self::BASURA, true) && !isset($out[$k])) {
                $out[$k] = $x;
            }
        }

        return $out;   // claveId → texto original
    }

    /**
     * Identidad estable del equipo para la huella: la placa/serial que trae el Excel; si no
     * trae, la descripción. No depende de la base (un alias nuevo no cambia huellas viejas).
     */
    public function identidad(array $f): string
    {
        $c = $this->candidatos($f);
        $k = $c !== [] ? array_key_first($c) : TextoConsumo::claveId($f['desc'] ?? '');

        return substr($k, 0, 60);
    }

    /**
     * Texto con el que se agrupa (y se enseña) un equipo no identificado. Es también el
     * IDENTIFICADOR que se guarda en el consumo (100 caracteres, el largo de esa columna):
     * así «Ejecutar Match» vuelve a resolverlo con las mismas reglas y los mismos alias.
     */
    public function claveAliasEquipo(array $f): string
    {
        $partes = [];
        foreach (['desc', 'placa', 'ident', 'serial'] as $k) {
            $t = TextoConsumo::norm($f[$k] ?? '');
            if ($t !== '' && !in_array($t, $partes, true)) {
                $partes[] = $t;
            }
        }
        if (!empty($f['cod'])) {
            $partes[] = TextoConsumo::norm($f['cod']);
        }

        return mb_substr(implode(' | ', $partes), 0, 100);
    }

    /**
     * Tipos que el Excel dice que es el equipo: por las palabras de FAMILIAS (con errores de
     * tipeo corregidos: «PAYLODER» = PAYLOADER) y por el modelo («950G» → los tipos de los
     * equipos cuyo modelo es 950G; así un «MONTACARGAS 950G» llega al payloader 950G).
     * @return array{E: string[], A: string[]} tipos de equipo (con «CAMION*» = prefijo) y de auxiliar
     */
    public function tiposPermitidos(array $f): array
    {
        $txt = TextoConsumo::norm(implode(' ', array_filter([$f['desc'] ?? null, $f['placa'] ?? null, $f['ident'] ?? null, $f['cat']['equipo'] ?? null])));
        $txt = ' ' . self::corregir(str_replace(['/', '-', ',', '.', '(', ')', ':'], ' ', $txt), $this->vocabTipos) . ' ';
        $tipos = ['E' => [], 'A' => []];
        foreach (self::FAMILIAS as [$palabras, $deEquipo, $deAux]) {
            foreach ($palabras as $p) {
                if (preg_match('/\b' . preg_quote($p, '/') . '\b/', $txt)) {
                    array_push($tipos['E'], ...$deEquipo);
                    array_push($tipos['A'], ...$deAux);
                    break;
                }
            }
        }
        foreach (self::tokensModelo($txt) as $tok) {
            foreach (array_keys($this->tiposPorModelo[$tok] ?? []) as $t) {
                $tipos['E'][] = $t;
            }
        }

        return ['E' => array_values(array_unique($tipos['E'])), 'A' => array_values(array_unique($tipos['A']))];
    }

    private function esDeTipo(string $ref, array $tipos): bool
    {
        $u = $this->unidades[$ref] ?? null;
        if (!$u) {
            return false;
        }
        foreach ($tipos[$u['aux'] ? 'A' : 'E'] as $t) {
            if (str_ends_with($t, '*') ? str_starts_with($u['tipo'], rtrim($t, '*')) : $u['tipo'] === TextoConsumo::norm($t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{estado:string, id_equipo:?int, id_auxiliar:?int, modo:?string, etiqueta:?string, clave:string}
     *  estado: CONFIRMADO (equipo o auxiliar) | NO_EQUIPO (enseñado) | SIN_MATCH (por identificar)
     */
    public function resolverEquipo(array $f): array
    {
        $clave = $this->claveAliasEquipo($f);
        $res = ['estado' => 'SIN_MATCH', 'id_equipo' => null, 'id_auxiliar' => null, 'modo' => null, 'etiqueta' => null, 'clave' => $clave];

        if (isset($this->aliasEquipo[$clave])) {
            $a = $this->aliasEquipo[$clave];
            if ($a->NO_ES_EQUIPO) {
                return ['estado' => 'NO_EQUIPO', 'modo' => 'enseñado', 'etiqueta' => 'No es un equipo'] + $res;
            }
            $ref = $a->ID_EQUIPO ? 'E:' . $a->ID_EQUIPO : 'A:' . $a->ID_AUXILIAR;
            if (isset($this->unidades[$ref])) {
                return $this->confirmar($res, $ref, 'enseñado');
            }
        }

        $tipos = null;
        $ambiguo = false;
        foreach (array_keys($this->candidatos($f)) as $k) {
            $hits = $this->indice[$k] ?? (ctype_digit($k) ? ($this->indice[ltrim($k, '0')] ?? []) : []);
            if ($hits === []) {
                continue;
            }
            if (count($hits) === 1) {
                return $this->confirmar($res, array_key_first($hits), reset($hits));
            }
            $tipos ??= $this->tiposPermitidos($f);
            $filtrados = array_filter($hits, fn ($ref) => $this->esDeTipo($ref, $tipos), ARRAY_FILTER_USE_KEY);
            if (count($filtrados) === 1) {
                return $this->confirmar($res, array_key_first($filtrados), reset($filtrados));
            }
            $ambiguo = true;
        }

        // Últimos dígitos del serial + tipo. Sin tipo reconocible no se arriesga. Vale un
        // número suelto («PAYLOADER 1941», «#1682») o el final numérico de un código («H3400175»).
        // Primero el serial de chasis; el de motor solo si el de chasis no da ninguno (el 1682
        // del payloader …001682 es también el final del MOTOR de otro payloader).
        $tipos ??= $this->tiposPermitidos($f);
        if ($tipos['E'] !== [] || $tipos['A'] !== []) {
            $txt = TextoConsumo::norm(implode(' ', array_filter([$f['desc'] ?? null, $f['ident'] ?? null, $f['serial'] ?? null, $f['placa'] ?? null])));
            preg_match_all('/(\d{4,8})(?![A-Z0-9])/', $txt, $m);
            foreach (array_unique($m[1]) as $num) {
                foreach ([0 => 'final del serial', 1 => 'final del serial de motor'] as $nivel => $modo) {
                    $hits = array_filter(
                        $this->sufijos[$nivel][substr($num, -4)] ?? [],
                        fn ($serial, $ref) => str_ends_with($serial, $num) && $this->esDeTipo($ref, $tipos),
                        ARRAY_FILTER_USE_BOTH
                    );
                    if (count($hits) === 1) {
                        return $this->confirmar($res, array_key_first($hits), $modo);
                    }
                    if ($hits !== []) {
                        $ambiguo = true;
                        break;
                    }
                }
            }
        }

        if ($ambiguo) {
            $res['etiqueta'] = 'Coincide con varios equipos';
        }

        return $res;
    }

    private function confirmar(array $res, string $ref, string $modo): array
    {
        [$tipo, $id] = explode(':', $ref);
        $res['estado'] = 'CONFIRMADO';
        $res['modo'] = $modo;
        $res['etiqueta'] = $this->unidades[$ref]['etiqueta'];
        if ($tipo === 'E') {
            $res['id_equipo'] = (int) $id;
        } else {
            $res['id_auxiliar'] = (int) $id;
        }

        return $res;
    }

    // ── Proyecto (frente) ───────────────────────────────────────────────────

    /** Trozos de un texto de proyecto en orden de prueba: sin paréntesis, y cada paréntesis. */
    private function trozos(?string $texto): array
    {
        if ($texto === null || trim($texto) === '') {
            return [];
        }

        return array_values(array_unique(array_filter([TextoConsumo::base($texto), ...TextoConsumo::parentesis($texto)])));
    }

    /** Frente de un trozo de texto, o null. */
    public function frenteDeTexto(string $trozo): ?int
    {
        $n = TextoConsumo::norm($trozo);
        if ($n === '') {
            return null;
        }
        if (isset($this->aliasFrente[$n])) {
            return $this->aliasFrente[$n];
        }
        $n = self::corregir($n, $this->vocabFrentes);   // «TRANVERSLES AYCUCHO» → TRANSVERSALES AYACUCHO
        $k = TextoConsumo::clave($n);
        if (isset($this->frentesPorNombre[$k])) {
            return $this->frentesPorNombre[$k];
        }
        foreach (self::REGLAS_FRENTE as [$re, $nombre]) {
            if (preg_match($re, $n)) {
                return $this->frentesPorNombre[TextoConsumo::clave($nombre)] ?? null;
            }
        }

        return null;
    }

    /**
     * @param string $principal 'proyecto' | 'frente': qué columna manda en esa hoja (en Agua
     *        Salada el proyecto está en FRENTE y PROYECTO trae la actividad; en Carabobo es al
     *        revés). La decide el importador por hoja; la otra queda de respaldo.
     * @return array{id_frente:?int, clave:string}
     */
    public function resolverFrente(array $f, string $principal = 'proyecto'): array
    {
        $orden = $principal === 'frente' ? ['frente', 'proyecto'] : ['proyecto', 'frente'];
        foreach ($orden as $col) {
            foreach ($this->trozos($f[$col] ?? null) as $t) {
                $id = $this->frenteDeTexto($t);
                if ($id !== null) {
                    return ['id_frente' => $id, 'clave' => TextoConsumo::norm($t)];
                }
            }
        }
        $clave = '';
        foreach ($orden as $col) {
            $clave = TextoConsumo::base($f[$col] ?? '');
            if ($clave !== '') {
                break;
            }
        }

        return ['id_frente' => null, 'clave' => mb_substr($clave, 0, 191)];
    }

    /** ¿Cuántas filas resuelve cada columna? Para elegir la principal de una hoja. */
    public function columnaPrincipal(array $filas): string
    {
        $p = $fr = 0;
        foreach ($filas as $f) {
            foreach ($this->trozos($f['proyecto'] ?? null) as $t) {
                if ($this->frenteDeTexto($t) !== null) {
                    $p++;
                    break;
                }
            }
            foreach ($this->trozos($f['frente'] ?? null) as $t) {
                if ($this->frenteDeTexto($t) !== null) {
                    $fr++;
                    break;
                }
            }
        }

        return $fr > $p ? 'frente' : 'proyecto';
    }

    public function nombreFrente(?int $id): ?string
    {
        return $id !== null ? ($this->nombresFrente[$id] ?? null) : null;
    }

    /** @return array<int,string> ID_FRENTE → nombre, ordenados */
    public function frentes(): array
    {
        $f = $this->nombresFrente;
        asort($f);

        return $f;
    }
}
