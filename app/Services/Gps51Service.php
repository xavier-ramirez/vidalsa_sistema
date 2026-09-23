<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Posición de los GPS de la plataforma GPS51 (gps51.com) SIN la ventana de GPS51 y SIN la
 * cuenta de la empresa.
 *
 * Cada equipo guarda en LINK_GPS un enlace COMPARTIDO de GPS51 que trae un `authcode`. Con ese
 * código, la API pública de GPS51 entrega los datos del dispositivo: es exactamente lo que pide
 * la propia página compartida de GPS51 al abrirse (comprobado el 14-09-2026):
 *   · sharetracklastposition  {authcode, lastquerypositiontime:0} → última posición + vigencia.
 *   · poibatchwithauthcode    ?authcode=…  {points:[{lat,lon}]}   → dirección escrita.
 *
 * GPS51 es LENTO y atiende pocas consultas a la vez (medido: ~2 s cada una; 20 en paralelo
 * tardan ~8 s; 130 de golpe → la mayoría rechazadas). Por eso las posiciones se piden en tandas
 * chicas (LOTE) y se guardan en caché compartida: el mapa las va completando por partes.
 *
 * El enlace compartido VENCE (campo `expire`): vencido, GPS51 deja de dar datos y hay que generar
 * uno nuevo en GPS51 y cargarlo en el equipo.
 *
 * Los authcode NUNCA salen al navegador: el mapa recibe solo la posición ya normalizada.
 */
class Gps51Service
{
    private const API = 'https://gps51.com/webapi';

    /** Consultas por tanda: el mapa manda como mucho 2 tandas a la vez (≈20 simultáneas, lo que
        GPS51 aguanta sin rechazar). */
    public const LOTE = 10;

    /** Tandas a GPS51 a la vez en TODO el servidor, y cuánto espera una tanda su turno (ver conCupo). */
    private const CUPOS = 2;
    private const ESPERA_CUPO = 20;

    /** Segundos que se reutiliza una posición (la capa del mapa se refresca con este mismo ritmo). */
    private const TTL_POSICION = 120;

    /** Segundos que se reutiliza una dirección (una coordenada no cambia de dirección). */
    private const TTL_DIRECCION = 86400;

    /** Segundos que se guarda la ÚLTIMA posición conocida (ver ultimasConocidas). */
    private const TTL_ULTIMA = 86400;

    /** Coordenadas por consulta de direcciones (ver direcciones(); 120 se probaron sin problema). */
    private const DIRECCIONES_POR_CONSULTA = 100;

    /** Última señal más reciente que esto = el equipo está "en línea". */
    private const EN_LINEA_MS = 10 * 60 * 1000;

    /** Recuadro de Venezuela: un GPS que reporta fuera (p. ej. la posición de fábrica, en China)
        no se pinta. */
    private const VENEZUELA = ['lat_min' => 0.6, 'lat_max' => 12.6, 'lng_min' => -73.4, 'lng_max' => -59.8];

    /** El authcode de un enlace compartido de GPS51, o null si el enlace no es de GPS51. */
    public static function authcode(?string $link): ?string
    {
        if (!$link || stripos($link, 'gps51') === false) {
            return null;
        }
        return preg_match('/[?&]authcode=([a-f0-9]{16,64})/i', $link, $m) ? strtolower($m[1]) : null;
    }

    /**
     * Posiciones que ya están en caché (no consulta a GPS51).
     *
     * @param  string[]  $authcodes
     * @return array<string, array>  solo los authcode con posición guardada
     */
    public static function enCache(array $authcodes): array
    {
        $codigos = array_values(array_unique(array_filter($authcodes)));
        if (!$codigos) {
            return [];
        }

        // Cache::many y NO un get() por equipo: la caché de la app vive en la BD (CACHE_STORE=
        // database), asi que preguntar de a uno eran ~130 SELECT en cada apertura del mapa; many()
        // los resuelve con un solo WHERE key IN (...).
        $claves = [];
        foreach ($codigos as $ac) {
            $claves[self::clavePosicion($ac)] = $ac;
        }
        $guardadas = Cache::many(array_keys($claves));

        $out = [];
        foreach ($guardadas as $clave => $valor) {
            if (is_array($valor)) {
                $out[$claves[$clave]] = $valor;
            }
        }
        return $out;
    }

    /**
     * Última posición de cada authcode: la de caché o, si no hay, consultando a GPS51 en paralelo.
     * Pensado para tandas de LOTE: el que llama no debe pasar muchos más (GPS51 los rechazaría).
     *
     * @param  string[]  $authcodes
     * @return array<string, array|null>  authcode → posición normalizada (ver normalizar()), o
     *                                    null si GPS51 no respondió.
     */
    public static function posiciones(array $authcodes): array
    {
        $authcodes = array_values(array_unique(array_filter($authcodes)));
        $out = self::enCache($authcodes);
        $faltan = array_values(array_diff($authcodes, array_keys($out)));
        if (!$faltan) {
            return $out;
        }

        $respuestas = self::conCupo(fn () => Http::pool(fn (Pool $pool) => array_map(
            fn ($ac) => $pool->as($ac)->timeout(15)->asJson()->post(
                self::API . '?action=sharetracklastposition&serverid=0',
                ['authcode' => $ac, 'lastquerypositiontime' => 0]
            ),
            $faltan
        )));
        $porGuardar = $ultimas = [];
        foreach ($faltan as $ac) {
            $r = $respuestas[$ac] ?? null;
            $json = ($r instanceof Response && $r->successful()) ? $r->json() : null;
            // Sin respuesta útil —red caída, HTTP de error, cuerpo que no es JSON o un status de
            // error de GPS51 (así frena las ráfagas)— NO se guarda ni se da el enlace por malo: se
            // reintenta en la siguiente vuelta. Un enlace vencido de verdad llega con status 0 e
            // isvalid 0, y ese sí se guarda (ver normalizar()).
            if (!is_array($json) || ($json['status'] ?? -1) !== 0) {
                $out[$ac] = null;
                continue;
            }
            $pos = self::normalizar($json);
            $porGuardar[self::clavePosicion($ac)] = $pos;
            $ultimas[self::claveUltima($ac)] = $pos;
            $out[$ac] = $pos;
        }
        // Una sola escritura para toda la tanda (misma razon que enCache: la cache es una tabla).
        if ($porGuardar) {
            Cache::putMany($porGuardar, self::TTL_POSICION);
            Cache::putMany($ultimas, self::TTL_ULTIMA);   // copia de larga vida (ver ultimasConocidas)
        }

        return $out;
    }

    /**
     * La ÚLTIMA posición conocida de cada authcode (hasta TTL_ULTIMA de vieja), sin consultar a
     * GPS51. Para el Excel del mapa: la posición "fresca" dura TTL_POSICION y, si caducó, pedir
     * de nuevo 130 equipos a GPS51 tarda más que la petición. `en_linea` se recalcula con la
     * hora de la última señal: el valor guardado era el de cuando se consultó.
     *
     * @param  string[]  $authcodes
     * @return array<string, array>
     */
    public static function ultimasConocidas(array $authcodes): array
    {
        $claves = [];
        foreach (array_unique(array_filter($authcodes)) as $ac) {
            $claves[self::claveUltima($ac)] = $ac;
        }
        $out = [];
        $ahoraMs = (int) round(microtime(true) * 1000);
        foreach ($claves ? Cache::many(array_keys($claves)) : [] as $clave => $pos) {
            if (!is_array($pos)) continue;
            if (!empty($pos['ok'])) {
                $pos['en_linea'] = !empty($pos['ultima_senal']) && ($ahoraMs - $pos['ultima_senal']) < self::EN_LINEA_MS;
            }
            $out[$claves[$clave]] = $pos;
        }
        return $out;
    }

    /**
     * Respuesta de sharetracklastposition → lo que pinta el mapa.
     *   ok=false + motivo: enlace_invalido (GPS51 lo rechaza), enlace_vencido, sin_posicion.
     *   ok=true con fuera_de_venezuela=true: el GPS reporta fuera del país (no se pinta).
     * Unidades de GPS51: speed en m/h, totaldistance en m, masteroil/auxoil en centésimas de litro
     * (-1 = sin sensor); updatetime/expire en milisegundos.
     */
    public static function normalizar(array $j): array
    {
        if (($j['status'] ?? -1) !== 0) {
            return ['ok' => false, 'motivo' => 'enlace_invalido'];
        }
        if ((int) ($j['isvalid'] ?? 1) !== 1) {
            return ['ok' => false, 'motivo' => 'enlace_vencido'];
        }
        $r = $j['records'][0] ?? null;
        $lat = (float) ($r['callat'] ?? 0);
        $lng = (float) ($r['callon'] ?? 0);
        if (!$r || ($lat == 0.0 && $lng == 0.0)) {
            return ['ok' => false, 'motivo' => 'sin_posicion'];
        }

        // Estado en inglés, p. ej. "ACC On 2H54M/Voltage 27.9V" (el otro campo viene en chino).
        $estado = (string) ($r['strstatusen'] ?? '');
        $acc = preg_match('/ACC\s*(On|Off)/i', $estado, $m) ? strcasecmp($m[1], 'On') === 0 : null;
        $accTiempo = preg_match('/ACC\s*(?:On|Off)\s+(\d+[DHMS](?:\d+[DHMS])*)/i', $estado, $m) ? strtolower($m[1]) : null;
        $voltaje = preg_match('/Voltage\s*([\d.]+)\s*V/i', $estado, $m) ? (float) $m[1] : null;

        $litros = fn ($campo) => (isset($r[$campo]) && $r[$campo] >= 0) ? round($r[$campo] / 100, 1) : null;
        $principal = $litros('masteroil');
        $auxiliar = $litros('auxoil');

        $ultima = (int) ($r['updatetime'] ?? 0);
        $ahoraMs = (int) round(microtime(true) * 1000);
        $v = self::VENEZUELA;

        return [
            'ok'          => true,
            'lat'         => $lat,
            'lng'         => $lng,
            'fuera_de_venezuela' => $lat < $v['lat_min'] || $lat > $v['lat_max'] || $lng < $v['lng_min'] || $lng > $v['lng_max'],
            'velocidad'   => round(((float) ($r['speed'] ?? 0)) / 1000, 1),
            'rumbo'       => (int) ($r['course'] ?? 0),
            'km_total'    => round(((float) ($r['totaldistance'] ?? 0)) / 1000, 1),
            'combustible' => [
                'principal' => $principal,
                'auxiliar'  => $auxiliar,
                'total'     => ($principal === null && $auxiliar === null) ? null : round(($principal ?? 0) + ($auxiliar ?? 0), 1),
            ],
            'acc'          => $acc,
            'acc_tiempo'   => $accTiempo,
            'voltaje'      => $voltaje,
            'ultima_senal' => $ultima ?: null,
            'en_linea'     => $ultima > 0 && ($ahoraMs - $ultima) < self::EN_LINEA_MS,
            'dispositivo'  => $j['devicename'] ?? null,
            'vence'        => isset($j['expire']) ? (int) $j['expire'] : null,
        ];
    }

    /** Dirección escrita de una coordenada (la misma que muestra GPS51), o null si no la hay. */
    public static function direccion(string $authcode, float $lat, float $lng): ?string
    {
        return self::direcciones($authcode, [[$lat, $lng]])[self::claveDireccion($lat, $lng)] ?? null;
    }

    /**
     * Dirección escrita de VARIAS coordenadas: las guardadas salen de la caché y las demás van en
     * una sola consulta a GPS51 por cada DIRECCIONES_POR_CONSULTA (poibatchwithauthcode acepta una
     * lista; medido el 22-09-2026: 120 puntos en ~3,5 s, lo mismo que uno solo). La respuesta NO
     * respeta el orden de la lista y a veces omite alguno: cada dirección se empareja por la
     * coordenada que GPS51 devuelve, y las que faltan se piden una vez más.
     * Sirve cualquier authcode VIGENTE (no tiene que ser el del equipo de esa coordenada).
     *
     * @param  array<int, array{0: float, 1: float}>  $puntos  [[lat, lng], ...]
     * @return array<string, string>  claveDireccion(lat, lng) → dirección (solo las que se obtuvieron)
     */
    public static function direcciones(string $authcode, array $puntos): array
    {
        $porClave = [];
        foreach ($puntos as [$lat, $lng]) {
            $porClave[self::claveDireccion($lat, $lng)] = [(float) $lat, (float) $lng];
        }
        if (!$porClave) {
            return [];
        }
        $out = array_filter(Cache::many(array_keys($porClave)), 'is_string');

        for ($intento = 0; $intento < 2; $intento++) {
            $faltan = array_diff_key($porClave, $out);
            if (!$faltan) {
                break;
            }
            $nuevas = [];
            foreach (array_chunk($faltan, self::DIRECCIONES_POR_CONSULTA, true) as $tanda) {
                try {
                    $r = Http::timeout(30)->asJson()->post(
                        self::API . '?action=poibatchwithauthcode&authcode=' . urlencode($authcode) . '&serverid=0',
                        ['points' => array_values(array_map(fn ($p) => ['lat' => $p[0], 'lon' => $p[1]], $tanda))]
                    );
                } catch (\Throwable $e) {
                    continue;
                }
                $enOrden = array_keys($tanda);
                foreach (array_values((array) ($r->successful() ? data_get($r->json(), 'points', []) : [])) as $i => $p) {
                    $dir = trim((string) data_get($p, 'address', ''));
                    // Sin coordenada en la respuesta se toma la del mismo puesto en la lista.
                    $clave = (data_get($p, 'lat') !== null && data_get($p, 'lon') !== null)
                        ? self::claveDireccion((float) data_get($p, 'lat'), (float) data_get($p, 'lon'))
                        : ($enOrden[$i] ?? null);
                    if ($dir !== '' && isset($tanda[$clave])) {
                        $nuevas[$clave] = $dir;
                    }
                }
            }
            if (!$nuevas) {
                break;   // GPS51 no dio ninguna: repetir no va a cambiar nada
            }
            Cache::putMany($nuevas, self::TTL_DIRECCION);   // una escritura por vuelta (la caché es una tabla)
            $out += $nuevas;
        }

        return $out;
    }

    /** Clave de caché de la dirección de una coordenada (a 4 decimales, ~11 m). */
    public static function claveDireccion(float $lat, float $lng): string
    {
        return 'gps51_dir_' . round($lat, 4) . '_' . round($lng, 4);
    }

    /**
     * Ejecuta una tanda contra GPS51 solo si hay cupo en TODO el servidor (CUPOS tandas a la vez):
     * el tope de tandas del mapa es por pestaña, y varias pestañas o usuarios juntos saturarían a
     * GPS51. Si el cupo sigue ocupado tras ESPERA_CUPO segundos devuelve null (= sin respuesta:
     * el mapa lo reintenta en la siguiente vuelta).
     */
    private static function conCupo(callable $tanda)
    {
        $inicio = microtime(true);
        do {
            for ($i = 0; $i < self::CUPOS; $i++) {
                // 60 s de vida: si el proceso muere con el candado tomado, se libera solo.
                $candado = Cache::lock('gps51_cupo_' . $i, 60);
                if ($candado->get()) {
                    try {
                        return $tanda();
                    } finally {
                        $candado->release();
                    }
                }
            }
            usleep(250000);
        } while (microtime(true) - $inicio < self::ESPERA_CUPO);

        return null;
    }

    private static function clavePosicion(string $authcode): string
    {
        return 'gps51_pos_' . $authcode;
    }

    private static function claveUltima(string $authcode): string
    {
        return 'gps51_ult_' . $authcode;
    }
}
