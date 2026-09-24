<?php

namespace Tests\Feature;

use App\Services\GoogleDriveService;
use Tests\MySqlTestCase;

/**
 * Un documento reemplazado va a la PAPELERA de Google Drive, nunca se borra para siempre.
 *
 * Por qué importa: `files->delete` de Drive es definitivo (salta la papelera y no hay forma
 * de recuperar el archivo). Si alguien reemplaza un documento por error —una carga masiva con
 * "reemplazar" marcado de más, una foto cambiada sin querer— el PDF bueno se perdía para
 * todos. En la papelera queda 30 días y se restaura con un clic.
 *
 * Esta prueba vigila que no vuelva a aparecer un borrado permanente por ninguna vía: no llama
 * a Google, mira el código.
 */
class DrivePapeleraTest extends MySqlTestCase
{
    /**
     * El UNICO sitio donde borrar para siempre es correcto: la copia que el OCR crea para
     * leer un PDF y tira al terminar. Son archivos de usar y tirar que la propia app acaba de
     * fabricar; mandarlos a la papelera la llenaria de miles de copias basura. Ningun
     * documento de una ficha pasa por ahi.
     */
    private const COPIAS_DEL_OCR = 'app/Services/LectorDocumentoPdf.php';

    /** Los sitios que retiran un archivo de Drive tienen que usar la papelera. */
    public function test_nadie_llama_al_borrado_permanente_de_drive(): void
    {
        $este = basename(__FILE__);
        $culpables = [];

        // Con barras normales en las dos partes: en Windows base_path() las trae invertidas.
        $raiz = str_replace('\\', '/', base_path()) . '/';

        foreach ($this->archivosPhp(base_path('app')) as $ruta) {
            $relativa = str_replace($raiz, '', str_replace('\\', '/', $ruta));
            if ($relativa === self::COPIAS_DEL_OCR) continue;

            foreach (file($ruta) as $n => $linea) {
                // files->delete(...) es el borrado DEFINITIVO de la API de Drive. La papelera
                // se pone con files->update(..., ['trashed' => true]).
                if (preg_match('/files\s*->\s*delete\s*\(/', $linea)) {
                    $culpables[] = $relativa . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $culpables,
            "Un archivo de Drive no se puede borrar para siempre; usa enviarAPapelera(). Ver $este:\n"
            . implode("\n", $culpables));
    }

    public function test_el_servicio_ofrece_la_papelera_y_no_el_borrado(): void
    {
        $this->assertTrue(method_exists(GoogleDriveService::class, 'enviarAPapelera'));
        $this->assertFalse(method_exists(GoogleDriveService::class, 'deleteFile'),
            'deleteFile borraba para siempre: quedó sustituido por enviarAPapelera.');
    }

    /** @return \Generator<string> */
    private function archivosPhp(string $dir): \Generator
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') yield $archivo->getPathname();
        }
    }
}
