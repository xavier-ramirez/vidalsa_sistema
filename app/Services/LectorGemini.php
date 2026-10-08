<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Segundo lector de documentos: Gemini (Google) mirando el PDF ENTERO, no su texto.
 *
 * El lector de siempre es LectorDocumentoPdf: OCR de Drive + reglas escritas. Es gratis y
 * resuelve la mayoría, pero se le atraganta lo de siempre: escaneos torcidos, formatos nuevos y
 * los caracteres que se confunden (O/0, H/N, S/8). Medido el 22-09-2026 con 40 documentos
 * reales: de los que el sistema daba por ilegibles o "de otro vehículo", Gemini leyó bien el
 * serial y la placa, y sacó fechas y número donde el OCR no encontró nada.
 *
 * AQUÍ NO SE DECIDE NADA. Este servicio solo LEE y devuelve lo que vio; quién aplica sigue
 * siendo CorrectorFichaDocumento (revisión) o la carga masiva, que lo leído por la IA NUNCA lo
 * enlaza sola (se queda para revisar), con sus mismas puertas: placa y serial no se pisan, un
 * PDF de otro vehículo no se aplica.
 *
 * UN SOLO MODELO para todo (la carga masiva y la revisión de la noche): el de cupo grande
 * (services.gemini.modelo, ~500 al día en el plan gratis). Hubo un segundo modelo "para los
 * difíciles" con 20 al día; se agotaba enseguida y se quitó (29-09-2026).
 *
 * Ritmo (plan GRATIS de Gemini): un documento a la vez, con espera entre uno y otro para no
 * pasar del tope por minuto, y un tope al día. Si se acaba el cupo, devuelve null y el sistema
 * sigue como si la IA no existiera. Sin clave (`GEMINI_API_KEY`) tampoco pasa nada: null.
 */
class LectorGemini
{
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** Tope del PDF que se manda. La API no admite mucho más de 20 MB por consulta. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    /** Cuánto se espera por el candado antes de rendirse: si hay cola, mejor seguir sin IA. */
    private const ESPERA_CANDADO = 20;

    /** Donde Google cuenta el día del cupo gratis (renueva a medianoche de allí). */
    private const ZONA_CUPO = 'America/Los_Angeles';

    /** Lo que se le pide que devuelva. Mismos nombres que usa el resto de la app. */
    private const INSTRUCCION = <<<'TXT'
Eres el lector de documentos de una flota de equipos en Venezuela. Te doy UN documento en PDF:
título de propiedad del INTT, póliza de seguro, ROTC (certificado de circulación), providencia
RACDA o documento de embarque (Bill of Lading, "BL"). Devuelve SOLO un JSON con esta forma exacta:

{
 "tipo_documento": "titulo|poliza|rotc|racda|embarque|otro",
 "placa": "",
 "serial_carroceria": "",
 "serial_motor": "",
 "titular": "",
 "numero_documento": "",
 "fecha_emision": "AAAA-MM-DD o ''",
 "fecha_vencimiento": "AAAA-MM-DD o ''",
 "aseguradora": "",
 "vehiculos": [{"placa": "", "serial": ""}],
 "seguro": true,
 "nota": ""
}

Reglas:
- Escribe EXACTAMENTE lo que está impreso. No adivines, no completes y no corrijas.
- Si un dato no está o no se lee con certeza, déjalo vacío. Vacío es mejor que inventado.
- "seguro": false si el escaneo no permite leer con certeza la placa o el serial.
- Los seriales confunden O con 0, I con 1, S con 5 y B con 8: míralos con lupa.
- En un título del INTT, "serial_carroceria" es el N.I.V. (el serial de 17). La casilla
  "Serial Carrocería" suele decir N/A: eso NO es el serial; usa el N.I.V.
- En un título del INTT, "numero_documento" es el número de 12 cifras de arriba (el mismo que
  va en la línea "AAAAMMDD/../../1/1/<número>/..." del pie), no el "N° de Autorización".
- "vehiculos": si el documento ampara VARIOS (póliza de flota, ROTC de flota, providencia
  RACDA, BL), pon todos los que nombra con su placa y su serial (en un BL, el VIN).
- En un RACDA, "numero_documento" es el N° de la PROVIDENCIA ADMINISTRATIVA (no el registro RACDA
  "03-04-RTSMDP-...") y "vehiculos" son SOLO las placas de la lista de unidades autorizadas (el
  "código de validación" del pie no es una placa).
- En un BL, "numero_documento" es el "B/L NO." y "fecha_emision" la de "Place and date of issue". Si es de uno solo, deja la lista
  vacía y usa los campos de arriba.
- Un documento colectivo NO es ilegible: rellena igual las fechas, el número y el titular.
- "nota": una frase en español con lo que no se pudo leer, si algo faltó.
TXT;

    /** ¿Hay clave? Sin ella el sistema trabaja como siempre, sin IA. */
    public function disponible(): bool
    {
        return trim((string) config('services.gemini.key')) !== '';
    }

    /**
     * Lee un PDF y devuelve lo que vio, ya normalizado, o null si no se pudo (sin clave, sin
     * cupo, demasiado grande, sin red o respuesta rara). NUNCA lanza excepción ni deja que
     * suba una de dentro: el que llama sigue su camino como si la IA no existiera.
     *
     * @param  string  $pdf       contenido binario del PDF
     * @param  int     $intentos  cuántas veces insistir si Gemini dice "espera" (429/503)
     * @param  ?string $esperado  el documento que se está buscando (clave de LectorDocumentoPdf):
     *                            la carga masiva lo dice porque el usuario eligió el tipo. Si el
     *                            archivo trae varios, se leen los datos de ESE; si no lo trae,
     *                            Gemini contesta el tipo que sí es. La revisión de la noche no
     *                            lo pasa.
     * @return array{tipo:?string,placa:?string,serial:?string,serial_motor:?string,titular:?string,nro:?string,emision:?string,vence:?string,aseguradora:?string,vehiculos:array,seguro:bool,nota:?string,otro:bool,modelo:string}|null
     */
    public function leer(string $pdf, int $intentos = 3, ?string $esperado = null): ?array
    {
        try {
            $modelo = $this->modelo();

            if (!$this->disponible() || $pdf === '' || $modelo === '' || !$this->hayCupo()) {
                return null;
            }
            // El PDF viaja DENTRO de la consulta, en base64 (un tercio más grande), y la API
            // no acepta peticiones enormes: uno así se rechazaría con un error que no se puede
            // reintentar, después de habernos comido la memoria de armarlo.
            if (strlen($pdf) > self::MAX_BYTES) {
                Log::info('Gemini: el PDF es demasiado grande para leerlo', ['bytes' => strlen($pdf)]);
                return null;
            }

            $json = $this->consultar($modelo, $pdf, max(1, $intentos), $esperado);
            if ($json === null) {
                return null;
            }
            $leido = $this->normalizar($json);
            $leido['modelo'] = $modelo;
            return $leido;
        } catch (\Throwable $e) {
            // Aquí solo se llega si falla algo de la casa (la caché del cupo, el candado...):
            // nunca puede tumbar una carga masiva ni la revisión de la noche.
            Log::warning('Gemini: no se pudo leer', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * La consulta, con el ritmo del plan gratis: un documento a la vez en TODO el servidor
     * (candado) y una espera entre uno y otro. Si Gemini contesta "demasiadas consultas" o
     * "saturado", se reintenta; cualquier otro fallo se anota y se devuelve null.
     */
    private function consultar(string $modelo, string $pdf, int $intentos, ?string $esperado = null): ?array
    {
        $cuerpo = [
            'contents' => [['parts' => [
                ['text' => self::INSTRUCCION . $this->loQueSeBusca($esperado)],
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdf)]],
            ]]],
            // temperatura 0 = la lectura más literal posible; el JSON viene ya formado.
            'generationConfig' => ['temperature' => 0, 'response_mime_type' => 'application/json'],
        ];
        // La clave va en la CABECERA y no en la dirección: si la petición falla, el mensaje del
        // error lleva la dirección entera y acabaría escrita en storage/logs/laravel.log.
        $url = self::API . '/' . $modelo . ':generateContent';
        $espera = (int) ceil(60 / max(1, (int) config('services.gemini.rpm', 15)));

        for ($intento = 1; $intento <= $intentos; $intento++) {
            // Un documento a la vez en TODO el servidor. El candado se suelta tras la espera del
            // ritmo, así el siguiente no puede consultar antes de tiempo. Si hay cola por
            // delante, no se encola más: se devuelve null y el sistema sigue sin IA.
            $candado = Cache::lock('gemini_lectura', 180);
            try {
                $candado->block(self::ESPERA_CANDADO);
            } catch (\Throwable $e) {
                return null;
            }
            try {
                $r = Http::timeout((int) config('services.gemini.timeout', 75))
                    ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                    ->asJson()->post($url, $cuerpo);
            } catch (\Throwable $e) {
                Log::warning('Gemini: no respondió', ['modelo' => $modelo, 'error' => $e->getMessage()]);
                return null;
            } finally {
                $this->esperar($espera);
                $candado->release();
            }

            if ($r->successful()) {
                $this->gastarCupo();
                return $r->json();
            }
            // 429 por el tope DEL DÍA (o un modelo con cupo 0 en este plan): esperar no lo arregla
            // hasta mañana. Se da el cupo local por gastado para que los siguientes PDF no vuelvan
            // a preguntar, esperar y fallar uno por uno.
            if ($r->status() === 429 && preg_match('/PerDay|limit:\s*0\b/i', $r->body())) {
                Log::info('Gemini: Google dice que se acabó el cupo de hoy; se sigue sin IA', ['modelo' => $modelo]);
                $this->agotarCupo();
                return null;
            }
            // 429 = se pasó del tope por minuto; 503 = el modelo está saturado. Se reintenta.
            if (!in_array($r->status(), [429, 503], true)) {
                Log::warning('Gemini: respuesta de error', ['modelo' => $modelo, 'status' => $r->status(), 'cuerpo' => mb_substr($r->body(), 0, 300)]);
                return null;
            }
            if ($intento < $intentos) $this->esperar(min(20, 5 * $intento));
        }
        Log::warning('Gemini: sin respuesta', ['modelo' => $modelo, 'intentos' => $intentos]);
        return null;
    }

    /**
     * El párrafo que se añade a la instrucción cuando se sabe qué documento se busca. Sin
     * mentirle: se le pide que CONFIRME, y que diga el tipo verdadero si el archivo es otro.
     */
    private function loQueSeBusca(?string $esperado): string
    {
        $palabra = array_flip(self::TIPOS)[$esperado] ?? null;
        if (!$palabra) return '';

        return "\n\nSe está buscando un documento de tipo \"$palabra\". Si el archivo lo contiene (aunque traiga "
             . "otros documentos), pon tipo_documento = \"$palabra\" y lee SUS datos. Si no lo contiene, pon en "
             . "tipo_documento el tipo que realmente es (\"otro\" si no es ninguno de los cuatro) y di en \"nota\" qué es.";
    }

    /** La respuesta de Gemini → los mismos nombres que usa la app. */
    private function normalizar(array $json): array
    {
        $texto = '';
        foreach (data_get($json, 'candidates.0.content.parts', []) as $p) {
            $texto .= $p['text'] ?? '';
        }
        $d = json_decode($texto, true);
        if (!is_array($d)) {
            Log::warning('Gemini: la respuesta no era JSON', ['texto' => mb_substr($texto, 0, 200)]);
            return $this->vacio();
        }
        $txt = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        $vehiculos = [];
        foreach ((array) ($d['vehiculos'] ?? []) as $v) {
            $placa = $txt($v['placa'] ?? null);
            $serial = $txt($v['serial'] ?? null);
            if ($placa || $serial) {
                $vehiculos[] = ['placa' => $placa, 'serial' => $serial];
            }
        }

        return [
            'tipo'         => self::TIPOS[strtolower((string) ($d['tipo_documento'] ?? ''))] ?? null,
            'placa'        => $txt($d['placa'] ?? null),
            'serial'       => $txt($d['serial_carroceria'] ?? null),
            'serial_motor' => $txt($d['serial_motor'] ?? null),
            'titular'      => $txt($d['titular'] ?? null),
            'nro'          => $txt($d['numero_documento'] ?? null),
            'emision'      => $this->fecha($d['fecha_emision'] ?? null),
            'vence'        => $this->fecha($d['fecha_vencimiento'] ?? null),
            'aseguradora'  => $txt($d['aseguradora'] ?? null),
            'vehiculos'    => $vehiculos,
            'seguro'       => (bool) ($d['seguro'] ?? false),
            'nota'         => $txt($d['nota'] ?? null),
            // "otro" es una respuesta (no es ninguno de los cuatro), no un hueco: la carga
            // masiva la usa para no asociar un PDF que no es lo que se eligió.
            'otro'         => strtolower(trim((string) ($d['tipo_documento'] ?? ''))) === 'otro',
        ];
    }

    /** Los tipos de Gemini → las claves del sistema (LectorDocumentoPdf). */
    private const TIPOS = [
        'titulo' => LectorDocumentoPdf::PROPIEDAD,
        'poliza' => LectorDocumentoPdf::POLIZA,
        'rotc'   => LectorDocumentoPdf::ROTC,
        'racda'  => LectorDocumentoPdf::RACDA,
        'embarque' => CargaMasivaDocumentos::EMBARQUE,
    ];

    private function vacio(): array
    {
        return ['tipo' => null, 'placa' => null, 'serial' => null, 'serial_motor' => null, 'titular' => null,
                'nro' => null, 'emision' => null, 'vence' => null, 'aseguradora' => null,
                'vehiculos' => [], 'seguro' => false, 'nota' => null, 'otro' => false];
    }

    /** "2027-04-08" tal cual; cualquier otra cosa (o una fecha imposible) se descarta. */
    private function fecha(?string $v): ?string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** La espera del ritmo. Aparte para que las pruebas la puedan anular y no dormir de verdad. */
    protected function esperar(int $segundos): void
    {
        sleep($segundos);
    }

    // ── Cupo diario ───────────────────────────────────────────────────────────────

    /** El modelo que se usa para todo (ver la cabecera de la clase). */
    private function modelo(): string
    {
        return (string) config('services.gemini.modelo');
    }

    /** Consultas que quedan hoy. Sirve para decidir si vale la pena mandar el documento a la IA. */
    public function restantesHoy(): int
    {
        return max(0, (int) config('services.gemini.rpd', 450) - (int) Cache::get($this->claveCupo(), 0));
    }

    private function hayCupo(): bool
    {
        if ($this->restantesHoy() > 0) {
            return true;
        }
        Log::info('Gemini: cupo diario agotado; se sigue sin IA', ['modelo' => $this->modelo()]);
        return false;
    }

    private function gastarCupo(): void
    {
        $clave = $this->claveCupo();
        Cache::add($clave, 0, $this->finDelDiaDeGoogle());
        Cache::increment($clave);
    }

    /** El cupo de hoy, dado por gastado (Google ya dijo que no hay más). */
    private function agotarCupo(): void
    {
        Cache::put($this->claveCupo(), (int) config('services.gemini.rpd', 450), $this->finDelDiaDeGoogle());
    }

    /**
     * Una cuenta por modelo y por día DE GOOGLE: el cupo se renueva a medianoche del Pacífico
     * (las 3:00 en Venezuela). Contado con el día de aquí, un cupo agotado entre las 00:00 y las
     * 03:00 se habría dado por gastado también el día siguiente entero.
     */
    private function claveCupo(): string
    {
        return 'gemini_cupo_' . md5($this->modelo()) . '_' . now(self::ZONA_CUPO)->format('Y_m_d');
    }

    private function finDelDiaDeGoogle(): \Carbon\CarbonInterface
    {
        return now(self::ZONA_CUPO)->endOfDay()->addMinute();
    }

}
