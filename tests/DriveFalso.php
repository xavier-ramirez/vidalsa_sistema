<?php

namespace Tests;

use App\Services\GoogleDriveService;

/**
 * GoogleDriveService real con la API de Google cambiada por un doble en memoria: corre
 * todo el código propio (uploadFile, subirPdf con su copia local...) y nada sale a la red.
 * Se instala como el singleton que devuelve getInstance(). Usarlo junto a
 * Storage::fake('local'), o las copias locales caerían en el disco de verdad.
 */
class DriveFalso extends GoogleDriveService
{
    /** true = la API falla como si no hubiera red. */
    public bool $caido = false;

    private object $api;

    public function __construct()
    {
        $this->api = new class {
            public object $files;

            public function __construct()
            {
                $this->files = new class {
                    public int $creados = 0;
                    public bool $caido = false;

                    public function create($metadata, $opciones)
                    {
                        if ($this->caido) {
                            throw new \RuntimeException('Drive caído (simulado)');
                        }
                        return (object) ['id' => 'falso-' . (++$this->creados)];
                    }
                };
            }
        };
    }

    public static function instalar(): self
    {
        $drive = new self();
        self::ponerSingleton($drive);

        return $drive;
    }

    /** Llamarlo en tearDown: el singleton es estático y contaminaría el resto de tests. */
    public static function quitar(): void
    {
        self::ponerSingleton(null);
    }

    private static function ponerSingleton(?GoogleDriveService $drive): void
    {
        (new \ReflectionProperty(GoogleDriveService::class, 'instance'))->setValue(null, $drive);
    }

    /** Cuántos archivos se han "subido". */
    public function subidos(): int
    {
        return $this->api->files->creados;
    }

    public function getDrive()
    {
        $this->api->files->caido = $this->caido;

        return $this->api;
    }

    public function getRootFolderId()
    {
        return 'carpeta-falsa';
    }
}
