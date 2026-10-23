<?php

namespace App\Console\Commands;

use App\Support\RegistroCambios;
use Illuminate\Console\Command;

/**
 * Imprime los CREATE TRIGGER del registro de cambios, para pegarlos en phpMyAdmin cuando el
 * usuario de la aplicación no tiene permiso para crearlos (la migración lo avisa en el log).
 * Un DROP y un CREATE por línea, sin comentarios: phpMyAdmin junta las líneas al pegar.
 */
class SqlRegistroCambios extends Command
{
    protected $signature = 'cambios:sql {--estado : Solo dice si los triggers están creados}';

    protected $description = 'SQL de los triggers del registro de cambios (para phpMyAdmin)';

    public function handle(): int
    {
        if ($this->option('estado')) {
            $this->line(RegistroCambios::activo() ? 'Registro de cambios ACTIVO.' : 'Faltan triggers: modo de respaldo.');

            return self::SUCCESS;
        }

        foreach (RegistroCambios::sentencias('mysql') as $nombre => $sql) {
            $this->line("DROP TRIGGER IF EXISTS {$nombre};");
            $this->line($sql . ';');
        }

        return self::SUCCESS;
    }
}
