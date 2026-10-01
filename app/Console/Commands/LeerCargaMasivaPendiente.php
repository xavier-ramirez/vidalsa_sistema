<?php

namespace App\Console\Commands;

use App\Services\CargaMasivaDocumentos;
use App\Support\ColaCargaMasiva;
use Illuminate\Console\Command;

/**
 * Respaldo de la lectura en segundo plano de la carga masiva: lee lo que se quedo en la fila
 * (ColaCargaMasiva) si el lector que arranca tras la subida no llego a terminar (el servidor se
 * reinicio a medias, por ejemplo). Lo lanza el programador cada minuto, solo si hay algo.
 */
class LeerCargaMasivaPendiente extends Command
{
    protected $signature = 'docs:carga-masiva-pendientes';
    protected $description = 'Lee los PDF de la carga masiva que quedaron en la fila sin leer';

    public function handle(CargaMasivaDocumentos $servicio): int
    {
        $this->info('Leidos: ' . ColaCargaMasiva::leer($servicio));
        return self::SUCCESS;
    }
}
