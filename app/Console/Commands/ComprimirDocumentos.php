<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistorialDocumentosController;
use App\Models\CompresionPdf;
use App\Models\DocumentoAnexo;
use App\Services\CompresorPdf;
use App\Services\GoogleDriveService;
use App\Support\EnlacesDocumentos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Comprime, de pocos en pocos, los PDF que pesan de mas: todos los que se pueden subir en
 * equipos (sus seis documentos y las correcciones anexas) y en auxiliares (propiedad y
 * certificado). Lo corre el programador de tareas de madrugada (routes/console.php).
 *
 *   php artisan docs:comprimir                 5 archivos, los mas pesados que falten
 *   php artisan docs:comprimir --simular       lo mismo pero sin subir ni cambiar nada
 *   php artisan docs:comprimir --solo=<id>     solo ese archivo de Drive (pruebas)
 *
 * Por cada ARCHIVO de Drive (un mismo PDF puede estar enlazado en varias filas), en este
 * orden, y si CUALQUIER paso falla se deja como estaba (queda anotado en
 * compresion_pdf_registro y se sigue con el siguiente):
 *   1. Bajarlo de Drive y comprobar que llego completo.
 *   2. Saltarlo si tiene firma digital (comprimir la invalidaria).
 *   3. Comprimirlo con Ghostscript y comprobar que conserva las paginas, que no aparece
 *      ni una palabra nueva (la señal de texto dañado: se veria igual pero buscar y copiar
 *      fallarian) y que lo que se ve no cambia (comparacion pagina a pagina).
 *   4. Saltarlo si no ahorra al menos AHORRO_MIN.
 *   5. Subirlo como lo sube la app (carpeta raiz, mismo nombre) y volver a bajarlo para
 *      comprobarlo byte a byte.
 *   6. Cambiar el enlace en TODAS las filas que siguen apuntando al viejo, con sus
 *      correcciones anexas (EnlacesDocumentos::cambiar). Una fila que se reemplazo
 *      mientras tanto no se pisa; si no queda ninguna, el comprimido se retira.
 *   7. Mandar el viejo a la PAPELERA de Drive, nunca borrarlo del todo, y solo si ya
 *      ninguna fila lo usa.
 *
 * Como el enlace cambia en la misma base que usa la app, no hay ningun momento en que un
 * documento apunte a un archivo que ya no existe. Pero SOLO si esta es la base del
 * servidor: ver EnlacesDocumentos::esBaseDelServidor().
 */
class ComprimirDocumentos extends Command
{
    protected $signature = 'docs:comprimir
                            {--lote=5 : Cuantos archivos procesar en esta pasada}
                            {--min-kb=1000 : Solo los PDF que pesen mas que esto}
                            {--simular : Baja, comprime y valida, pero no sube ni cambia nada}
                            {--solo= : Procesar solo este ID de Drive}';

    protected $description = 'Comprime los PDF de documentos que pesan de mas (de pocos en pocos, sin tocar los ya procesados).';

    /** Ahorro minimo para que valga la pena reemplazar el documento. */
    private const AHORRO_MIN = 0.20;

    /**
     * Cuanto puede cambiar lo que se ve (peor pagina, %; ver CompresorPdf::diferenciaVisual).
     * La perdida normal del JPEG al 75 % da 1-2 %; por encima de esto algo visible cambio.
     */
    private const CAMBIO_VISUAL_MAX = 5.0;

    /** Un documento con error se reintenta en otras noches, hasta este numero de veces. */
    private const MAX_ERRORES = 3;

    /** Descanso minimo entre el fin de un lote y el comienzo del siguiente. */
    private const PAUSA_ENTRE_LOTES_S = 60;

    /** Claves de cache: "esta noche ya no queda nada" y "cuando termino el ultimo lote". */
    private const NADA_ESTA_NOCHE = 'docs_comprimir_nada';
    private const FIN_ULTIMO_LOTE = 'docs_comprimir_fin';

    public function handle(CompresorPdf $compresor): int
    {
        $simular = (bool) $this->option('simular');
        $solo    = $this->option('solo');

        // --simular no cambia nada; --solo es para probar con un archivo de prueba.
        [$esServidor, $motivo] = EnlacesDocumentos::esBaseDelServidor();
        if (!$simular && !$solo && !$esServidor) {
            $this->error("No se cambian documentos en este equipo: $motivo. Usa --simular.");
            return self::FAILURE;
        }
        if (!$solo && !$simular) {
            if (Cache::get(self::NADA_ESTA_NOCHE)) {
                return self::SUCCESS;   // ya se comprobo que no queda nada: ni se pregunta a Drive
            }
            $fin = Cache::get(self::FIN_ULTIMO_LOTE);
            if ($fin && time() - $fin < self::PAUSA_ENTRE_LOTES_S) {
                return self::SUCCESS;   // descanso entre lotes: la siguiente pasada del minuto sigue
            }
        }
        if (!$compresor->disponible()) {
            $this->error('Ghostscript no esta disponible (GHOSTSCRIPT_BIN).');
            Log::warning('docs:comprimir: Ghostscript no esta disponible; no se hizo nada.');
            return self::FAILURE;
        }

        $drive = GoogleDriveService::getInstance();
        $candidatos = $this->candidatos($drive, (int) $this->option('min-kb') * 1024, $solo);

        if (!$candidatos) {
            $this->info('Nada que comprimir.');
            if (!$solo && !$simular) {
                // Hasta la mañana: las demas pasadas de esta noche terminan al instante.
                Cache::put(self::NADA_ESTA_NOCHE, true, now()->addHours(8));
            }
            return self::SUCCESS;
        }

        $tmp = 'compresion_tmp/' . uniqid();
        Storage::disk('local')->makeDirectory($tmp);
        $dir = Storage::disk('local')->path($tmp);

        try {
            foreach (array_slice($candidatos, 0, max(1, (int) $this->option('lote'))) as $doc) {
                $this->procesar($doc, $drive, $compresor, $dir, $simular);
            }
        } finally {
            Storage::disk('local')->deleteDirectory($tmp);
            if (!$solo && !$simular) {
                Cache::put(self::FIN_ULTIMO_LOTE, time(), now()->addHours(8));
            }
        }
        return self::SUCCESS;
    }

    /**
     * ARCHIVOS de Drive (no filas) que pasan del umbral y que el registro no tiene ya por
     * procesados, del mas pesado al mas liviano. El tamaño se pide a Drive de UNA vez (la
     * lista de todos los PDF), no archivo por archivo.
     *
     * Por archivo y no por fila: un mismo PDF puede estar enlazado en varias filas (hay 13
     * auxiliares con el mismo documento de propiedad). Procesandolo por fila, el primero lo
     * comprimia y lo mandaba a la papelera mientras los demas seguian apuntandole, y cada uno
     * subia su propia copia comprimida. Asi se comprime una vez y se cambian todas.
     */
    private function candidatos(GoogleDriveService $drive, int $minBytes, ?string $solo): array
    {
        $tamanos = [];
        $token = null;
        do {
            $r = $drive->getDrive()->files->listFiles([
                'q' => "trashed=false and mimeType='application/pdf'",
                'fields' => 'nextPageToken, files(id,size)', 'pageSize' => 1000,
                'supportsAllDrives' => true, 'includeItemsFromAllDrives' => true, 'pageToken' => $token,
            ]);
            foreach ($r->getFiles() as $f) $tamanos[$f->getId()] = (int) $f->getSize();
            $token = $r->getNextPageToken();
        } while ($token);

        // Ya procesados: comprimidos o saltados (por su ID viejo o el nuevo). Los errores
        // se reintentan en otras noches hasta MAX_ERRORES.
        $hechos = CompresionPdf::whereIn('ESTADO', [CompresionPdf::COMPRIMIDO, CompresionPdf::SALTADO])
            ->get(['DRIVE_ID_VIEJO', 'DRIVE_ID_NUEVO'])
            ->flatMap(fn ($r) => [$r->DRIVE_ID_VIEJO, $r->DRIVE_ID_NUEVO])->filter()->flip();
        $errores = CompresionPdf::where('ESTADO', CompresionPdf::ERROR)
            ->groupBy('DRIVE_ID_VIEJO')->selectRaw('DRIVE_ID_VIEJO, COUNT(*) n')->pluck('n', 'DRIVE_ID_VIEJO');

        $lista = [];
        foreach (EnlacesDocumentos::DOCUMENTOS as [$tabla, $col, $prefijo, $nombre]) {
            foreach ($this->filasCon($tabla, $col) as $f) {
                $id = DocumentoAnexo::driveIdDeLink($f->link);
                if (!$id || !isset($tamanos[$id])) continue;          // no esta en Drive (o en la papelera)
                if (isset($hechos[$id]) || ($errores[$id] ?? 0) >= self::MAX_ERRORES) continue;
                if ($solo ? $id !== $solo : $tamanos[$id] <= $minBytes) continue;
                // La PRIMERA fila que lo usa da el nombre del archivo nuevo y la etiqueta; las
                // demas solo suman seriales (el enlace se cambia en todas: EnlacesDocumentos::cambiar).
                $lista[$id] ??= [
                    'id' => $id, 'bytes' => $tamanos[$id],
                    'tabla' => $tabla, 'col' => $col, 'fila' => $f->fila,
                    // Las correcciones llevan su tipo en el nombre (correccion_poliza_...).
                    'prefijo' => $tabla === 'documento_anexos' ? $prefijo . $f->tipo . '_' : $prefijo,
                    'nombre'  => $tabla === 'documento_anexos' ? "$nombre ({$f->tipo})" : $nombre,
                    'seriales' => [],
                ];
                $lista[$id]['seriales'][] = $f->serial;
            }
        }
        foreach ($lista as &$g) {
            $n = count($g['seriales']);
            $g['serial'] = mb_substr($g['seriales'][0] . ($n > 1 ? ' (+' . ($n - 1) . ' mas)' : ''), 0, 80);
        }
        unset($g);
        usort($lista, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        return $lista;
    }

    /** Filas de $tabla con un enlace a Drive en $col: fila, link, serial (y tipo en anexos). Solo de equipos/auxiliares vivos. */
    private function filasCon(string $tabla, string $col)
    {
        if ($tabla === 'equipos_auxiliares') {
            return DB::table('equipos_auxiliares as a')->whereNull('a.deleted_at')->where("a.$col", 'like', '/storage/google/%')
                ->select('a.ID_AUXILIAR as fila', "a.$col as link", DB::raw("COALESCE(NULLIF(a.SERIAL,''), a.CODIGO_INTERNO) as serial"))
                ->get();
        }
        $alias = $tabla === 'documentacion' ? 'd' : 'x';
        $q = DB::table("$tabla as $alias")->join('equipos as e', 'e.ID_EQUIPO', '=', "$alias.ID_EQUIPO")
            ->whereNull('e.deleted_at')->where("$alias.$col", 'like', '/storage/google/%');
        return $tabla === 'documentacion'
            ? $q->select('d.ID_EQUIPO as fila', "d.$col as link", 'e.SERIAL_CHASIS as serial')->get()
            : $q->select('x.ID_ANEXO as fila', "x.$col as link", 'e.SERIAL_CHASIS as serial', 'x.TIPO_DOC as tipo')->get();
    }

    private function procesar(array $d, GoogleDriveService $drive, CompresorPdf $gs, string $dir, bool $simular): void
    {
        $etiqueta = "{$d['nombre']} {$d['serial']}";
        $original = "$dir/{$d['id']}.pdf";
        $comprimido = "$dir/{$d['id']}_c.pdf";
        $idNuevo = null;
        $anotar = function (string $estado, ?string $motivo, ?int $despues = null) use ($d, &$idNuevo, $simular, $etiqueta) {
            $this->line(sprintf('  %-45s %6.2f MB -> %s  %s%s', $etiqueta, $d['bytes'] / 1048576,
                $despues ? sprintf('%.2f MB', $despues / 1048576) : '   -   ', $estado, $motivo ? " ($motivo)" : ''));
            if ($simular) return;
            CompresionPdf::create([
                'TABLA' => $d['tabla'], 'COLUMNA' => $d['col'], 'FILA_ID' => $d['fila'],
                'DOCUMENTO' => $d['nombre'], 'SERIAL' => $d['serial'],
                'DRIVE_ID_VIEJO' => $d['id'], 'DRIVE_ID_NUEVO' => $idNuevo,
                'BYTES_ANTES' => $d['bytes'], 'BYTES_DESPUES' => $despues,
                'ESTADO' => $estado, 'MOTIVO' => $motivo, 'ORIGEN' => 'noche',
            ]);
        };

        try {
            // 1. Bajar y comprobar que llego completo
            $salida = fopen($original, 'wb');
            $stream = $drive->getStreamById($d['id']);
            while (!$stream->eof()) fwrite($salida, $stream->read(1048576));
            fclose($salida);
            if (filesize($original) !== $d['bytes']) {
                throw new \RuntimeException('la descarga llego incompleta (' . filesize($original) . " de {$d['bytes']} bytes)");
            }

            // 2-4. Validaciones
            if ($gs->tieneFirmaDigital($original)) {
                $anotar(CompresionPdf::SALTADO, 'tiene firma digital: comprimirlo la invalidaria');
                return;
            }
            $gs->comprimir($original, $comprimido);
            $bytesNuevo = filesize($comprimido);
            if ($gs->paginas($comprimido) !== $gs->paginas($original)) {
                $anotar(CompresionPdf::SALTADO, 'la version comprimida cambiaba el numero de paginas');
                return;
            }
            if ($gs->palabrasNuevas($original, $comprimido) > 0) {
                $anotar(CompresionPdf::SALTADO, 'tiene texto seleccionable que la compresion dañaria', $bytesNuevo);
                return;
            }
            $cambioVisual = $gs->diferenciaVisual($original, $comprimido);
            if ($cambioVisual > self::CAMBIO_VISUAL_MAX) {
                $anotar(CompresionPdf::SALTADO, sprintf('la version comprimida se veia distinta (%.1f %%)', $cambioVisual), $bytesNuevo);
                return;
            }
            if ($bytesNuevo > $d['bytes'] * (1 - self::AHORRO_MIN)) {
                $anotar(CompresionPdf::SALTADO, sprintf('solo ahorraba %d %%', round(100 * (1 - $bytesNuevo / $d['bytes']))), $bytesNuevo);
                return;
            }
            if ($simular) {
                $anotar('simulado', null, $bytesNuevo);
                return;
            }

            // 5. Subir como la app y comprobar lo subido byte a byte
            $subido = $drive->uploadFile($drive->getRootFolderId(), new \SplFileInfo($comprimido), $d['prefijo'] . time() . '.pdf', 'application/pdf');
            if (!$subido || empty($subido->id)) throw new \RuntimeException('Drive no devolvio un ID al subir');
            $idNuevo = $subido->id;
            if (md5($drive->getStreamById($idNuevo)->getContents()) !== md5_file($comprimido)) {
                throw new \RuntimeException('lo que quedo en Drive no coincide con lo subido');
            }

            // 6. Cambiar el enlace en TODAS las filas que siguen usando el viejo, en una sola
            //    transaccion.
            $cambiadas = DB::transaction(fn () => EnlacesDocumentos::cambiar($d['id'], $idNuevo));
            if ($cambiadas === 0) {
                $this->aPapelera($drive, $idNuevo);   // el nuestro sobra: no se usa
                $idNuevo = null;
                $anotar(CompresionPdf::SALTADO, 'el documento se reemplazo mientras se comprimia; no se toco');
                return;
            }

            // Desde aqui el documento YA usa el comprimido: pase lo que pase, eso no se
            // deshace (el catch de abajo no debe mandar a la papelera un archivo en uso).
            // Lo que falle se anota como detalle de un documento comprimido.
            $pendiente = [];
            if ($cambiadas > 1) {
                $pendiente[] = "lo usaban $cambiadas filas y se cambiaron todas";
            }
            try {
                DashboardController::bumpDataVersion();
                HistorialDocumentosController::bumpDataVersion();
            } catch (\Throwable $e) {
                $pendiente[] = 'no se refrescaron las caches (se refrescan solas)';
            }
            // 7. El viejo, a la papelera (la copia local del proxy se olvida). Solo si ya
            //    nadie lo usa: una fila fuera de las conocidas no debe quedarse sin archivo.
            try {
                if (EnlacesDocumentos::sigueEnUso($d['id'])) {
                    $pendiente[] = 'el viejo sigue enlazado en otra fila: NO se mando a la papelera';
                } else {
                    GoogleDriveService::olvidarCopiaLocal($d['id']);
                    $this->aPapelera($drive, $d['id']);
                }
            } catch (\Throwable $e) {
                $pendiente[] = 'el viejo no se pudo mandar a la papelera: ' . mb_substr($e->getMessage(), 0, 120);
            }
            $anotar(CompresionPdf::COMPRIMIDO, $pendiente ? 'Comprimido; ' . implode('; ', $pendiente) : null, $bytesNuevo);
        } catch (\Throwable $e) {
            // Si ya se habia subido el comprimido pero la BD no se cambio, se retira: sobra.
            if ($idNuevo) {
                try { $this->aPapelera($drive, $idNuevo); } catch (\Throwable $ignorado) {}
                $idNuevo = null;
            }
            Log::warning("docs:comprimir: {$etiqueta} ({$d['id']}): " . $e->getMessage());
            $anotar(CompresionPdf::ERROR, mb_substr($e->getMessage(), 0, 250));
        } finally {
            @unlink($original);
            @unlink($comprimido);
        }
    }

    private function aPapelera(GoogleDriveService $drive, string $id): void
    {
        $drive->getDrive()->files->update($id, new \Google\Service\Drive\DriveFile(['trashed' => true]), ['supportsAllDrives' => true]);
    }
}
