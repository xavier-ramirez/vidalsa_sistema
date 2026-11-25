<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Deja en el disco del servidor la copia de cada foto de producto y sus miniaturas (w120 de
 * la tabla de Almacén, w300 del Catálogo), para que el proxy las sirva de ahí y nadie espere
 * a Drive al abrirlas. Solo baja lo que falta, y se salta lo que Drive dijo hace poco que
 * no existe. Lo corre el programador cada noche (routes/console.php) y se puede lanzar a
 * mano: `php artisan fotos:preparar` (o `--id=` para una sola foto).
 */
class PrepararFotosProductos extends Command
{
    protected $signature = 'fotos:preparar {--id=* : Solo estas fotos (ID de Drive)}';

    protected $description = 'Copia en el servidor las fotos de productos y sus miniaturas (para que abran sin ir a Drive)';

    /** Tamaños de miniatura que piden las pantallas (almacen_local.js celdaFoto, catálogo). */
    public const MINIATURAS = ['w120', 'w300'];

    public function handle(): int
    {
        $ids = $this->option('id')
            ? collect($this->option('id'))
            : DB::table('productos_inventario')
                ->whereNotNull('FOTO')->where('FOTO', 'like', '/storage/google/%')
                ->pluck('FOTO')
                ->map(fn ($u) => preg_match('#^/storage/google/([A-Za-z0-9_-]+)#', $u, $m) ? $m[1] : null);
        $ids = $ids->filter()->unique()
            ->reject(fn ($id) => Cache::has('gdrive_no_existe_' . $id))
            ->values();

        $listas = 0;
        $fallas = [];
        foreach ($ids as $id) {
            if (GoogleDriveService::prepararCopiaLocal($id, null, self::MINIATURAS)) {
                $listas++;
            } else {
                $fallas[] = $id;
            }
        }

        $this->info("Fotos listas en el servidor: $listas de " . $ids->count() . '.');
        if ($fallas) {
            $this->warn('Sin copia (ver el log): ' . implode(', ', $fallas));
        }
        return self::SUCCESS;
    }
}
