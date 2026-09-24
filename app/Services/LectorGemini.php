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
 * siendo CorrectorFichaDocumento (revisión) o el usuario pulsando "Aplicar" (carga masiva),
 * con sus mismas puertas: placa y serial no se pisan, un PDF de otro vehículo no se aplica.
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

    /** Lo que se le pide que devuelva. Mismos nombres que usa el resto de la app. */
    private const INSTRUCCION = <<<'TXT'
Eres el lector de documentos de una flota de equipos en Venezuela. Te doy UN documento en PDF:
título de propiedad del INTT, póliza de seguro, ROTC (certificado de circulación) o providencia
RACDA. Devuelve SOLO un JSON con esta forma exacta:

{
 "tipo_documento": "titulo|poliza|rotc|racda|otro",
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
- "vehiculos": si el documento ampara VARIOS (póliza de flota, ROTC de flota, providencia
  RACDA), pon todos los que nombra con su placa y su serial. Si es de uno solo, deja la lista
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
     * @param  bool    $dificil   true = usar el modelo mejor (cupo diario mucho más chico)
     * @param  int     $intentos  cuántas veces insistir si Gemini dice "espera" (429/503)
     * @return array{tipo:?string,placa:?string,serial:?string,serial_motor:?string,titular:?string,nro:?string,emision:?string,vence:?string,aseguradora:?string,vehiculos:array,seguro:bool,nota:?string,modelo:string}|null
     */
    public function leer(string $pdf, bool $dificil = false, int $intentos = 3): ?array
    {
        try {
            $modelo = $dificil
                ? (string) config('services.gemini.modelo_dificil')
                : (string) config('services.gemini.modelo');

            if (!$this->disponible() || $pdf === '' || $modelo === '' || !$this->hayCupo($modelo)) {
                return null;
            }
            // El PDF viaja DENTRO de la consulta, en base64 (un tercio más grande), y la API
            // no acepta peticiones enormes: uno así se rechazaría con un error que no se puede
            // reintentar, después de habernos comido la memoria de armarlo.
            if (strlen($pdf) > self::MAX_BYTES) {
                Log::info('Gemini: el PDF es demasiado grande para leerlo', ['bytes' => strlen($pdf)]);
                return null;
            }

            $json = $this->consultar($modelo, $pdf, max(1, $intentos));
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
    private function consultar(string $modelo, string $pdf, int $intentos): ?array
    {
        $cuerpo = [
            'contents' => [['parts' => [
                ['text' => self::INSTRUCCION],
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
                $this->gastarCupo($modelo);
                return $r->json();
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
        ];
    }

    /** Los tipos de Gemini → las claves del sistema (LectorDocumentoPdf). */
    private const TIPOS = [
        'titulo' => LectorDocumentoPdf::PROPIEDAD,
        'poliza' => LectorDocumentoPdf::POLIZA,
        'rotc'   => LectorDocumentoPdf::ROTC,
        'racda'  => LectorDocumentoPdf::RACDA,
    ];

    private function vacio(): array
    {
        return ['tipo' => null, 'placa' => null, 'serial' => null, 'serial_motor' => null, 'titular' => null,
                'nro' => null, 'emision' => null, 'vence' => null, 'aseguradora' => null,
                'vehiculos' => [], 'seguro' => false, 'nota' => null];
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

    /**
     * Consultas que quedan hoy de ese modelo (cada uno tiene su propio tope). Sirve para
     * pintarlo en pantalla y para decidir si vale la pena mandar el documento a la IA.
     */
    public function restantesHoy(?string $modelo = null): int
    {
        $modelo = $modelo ?: (string) config('services.gemini.modelo');
        $tope = $modelo === (string) config('services.gemini.modelo_dificil')
            ? (int) config('services.gemini.rpd_dificil', 18)
            : (int) config('services.gemini.rpd', 450);

        return max(0, $tope - (int) Cache::get($this->claveCupo($modelo), 0));
    }

    private function hayCupo(string $modelo): bool
    {
        if ($this->restantesHoy($modelo) > 0) {
            return true;
        }
        Log::info('Gemini: cupo diario agotado; se sigue sin IA', ['modelo' => $modelo]);
        return false;
    }

    private function gastarCupo(string $modelo): void
    {
        $clave = $this->claveCupo($modelo);
        Cache::add($clave, 0, now()->endOfDay()->addMinute());
        Cache::increment($clave);
    }

    /** Una cuenta por modelo y por día; se borra sola al terminar el día. */
    private function claveCupo(string $modelo): string
    {
        return 'gemini_cupo_' . md5($modelo) . '_' . now()->format('Y_m_d');
    }
}
