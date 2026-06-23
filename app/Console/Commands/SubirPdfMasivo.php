<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleDriveService;

class SubirPdfMasivo extends Command
{
    protected $signature = 'pdf:subir-masivo
                            {archivo : Ruta absoluta al PDF que se va a subir}
                            {cantidad : Número de veces que se sube (ej: 134)}
                            {--prefijo=racda : Prefijo del nombre del archivo en Drive}';

    protected $description = 'Sube el mismo PDF N veces a Google Drive y devuelve la lista de links generados.';

    public function handle()
    {
        $archivo  = $this->argument('archivo');
        $cantidad = (int) $this->argument('cantidad');
        $prefijo  = $this->option('prefijo');

        // Validar archivo
        if (!file_exists($archivo)) {
            $this->error("❌ El archivo no existe: {$archivo}");
            return 1;
        }

        if (!str_ends_with(strtolower($archivo), '.pdf')) {
            $this->error("❌ El archivo debe ser un PDF.");
            return 1;
        }

        if ($cantidad < 1 || $cantidad > 500) {
            $this->error("❌ La cantidad debe estar entre 1 y 500.");
            return 1;
        }

        $this->info("📤 Subiendo '{$archivo}' a Google Drive {$cantidad} veces...");
        $this->newLine();

        try {
            $driveService = GoogleDriveService::getInstance();
            $folderId     = $driveService->getRootFolderId();
        } catch (\Exception $e) {
            $this->error("❌ Error conectando a Google Drive: " . $e->getMessage());
            return 1;
        }

        $links   = [];
        $errores = 0;
        $bar     = $this->output->createProgressBar($cantidad);
        $bar->start();

        for ($i = 1; $i <= $cantidad; $i++) {
            try {
                $filename  = "{$prefijo}_{$i}_" . time() . ".pdf";

                // Crear un objeto similar a UploadedFile para el servicio
                $tmpFile = new \Illuminate\Http\UploadedFile(
                    $archivo,
                    $filename,
                    'application/pdf',
                    null,
                    true // test mode: no validation
                );

                $driveFile = $driveService->uploadFile($folderId, $tmpFile, $filename, 'application/pdf');

                if (!$driveFile || !isset($driveFile->id)) {
                    throw new \Exception("Drive no retornó un ID válido");
                }

                $link    = '/storage/google/' . $driveFile->id . '?v=' . time();
                $links[] = $link;

                $bar->advance();
                usleep(100000); // 100ms entre subidas para no saturar la API

            } catch (\Exception $e) {
                $errores++;
                $this->newLine();
                $this->warn("⚠️  Error en subida #{$i}: " . $e->getMessage());
                $links[] = "ERROR_SUBIDA_{$i}";
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Guardar resultados en un archivo TXT
        $outputFile = storage_path('app/links_racda_' . date('Y-m-d_His') . '.txt');
        file_put_contents($outputFile, implode("\n", $links));

        $this->info("✅ Completado: " . count($links) . " subidas ({$errores} errores).");
        $this->info("📄 Links guardados en: {$outputFile}");
        $this->newLine();
        $this->line("Primeros 5 links generados:");
        foreach (array_slice($links, 0, 5) as $idx => $l) {
            $this->line("  [" . ($idx + 1) . "] {$l}");
        }
        $this->line("  ...");

        return 0;
    }
}
