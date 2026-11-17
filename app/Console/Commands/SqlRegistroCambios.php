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
    protected $signature = 'cambios:sql
        {--estado : Solo dice si los triggers están creados}
        {--asegurar : Crea los que falten o cambiaron (lo corre el arranque del contenedor)}';

    protected $description = 'SQL de los triggers del registro de cambios (para phpMyAdmin)';

    public function handle(): int
    {
        if ($this->option('asegurar')) {
            // Nunca devuelve error: el arranque del contenedor no debe pararse por esto.
            try {
                $r = RegistroCambios::asegurar();
            } catch (\Throwable $e) {
                $r = ['estado' => 'fallos', 'detalle' => ['error' => $e->getMessage()]];
            }
            $this->line(match ($r['estado']) {
                'al_dia'      => 'Registro de cambios ACTIVO (triggers al día).',
                'creados'     => 'Registro de cambios ACTIVO: ' . count($r['detalle']) . ' triggers creados ' . reset($r['detalle']) . '.',
                'sin_permiso' => 'Faltan triggers: modo de respaldo. Sin permiso para crearlos:',
                'invisible'   => 'Triggers creados, pero sigue el modo de respaldo:',
                default       => 'Faltan triggers: modo de respaldo. Fallaron:',
            });
            if (! in_array($r['estado'], ['al_dia', 'creados'], true)) {
                foreach ($r['detalle'] as $quien => $msg) {
                    $this->line("  - {$quien}: " . mb_substr($msg, 0, 300));
                }
            }

            return self::SUCCESS;
        }

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
