<?php

namespace App\Console\Commands;

use App\Events\HuboCambios;
use App\Support\RegistroCambios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vigía del registro de cambios: corre siempre (supervisord) y, en cuanto la tabla `cambios`
 * crece, avisa por Reverb a todos los navegadores (HuboCambios), que ponen al día su copia
 * ya. Así lo hecho en el teléfono se ve en la PC al instante, venga de donde venga el cambio
 * (app, comando artisan o SQL a mano): lo anotan los triggers, no el código PHP.
 *
 * Mira UNA vez para todos (una consulta cada `--cada` ms, por clave primaria) en lugar de que
 * cada navegador pregunte cada pocos segundos.
 *
 * No basta mirar el número más alto: una transacción con un número MENOR puede confirmarse
 * después (ver RegistroCambios::desde) y no lo cambiaría. Por eso la huella es número más alto
 * + cuántas filas hay después de `$base`, una ventana de las últimas VENTANA filas que avanza
 * sola. Al moverla, la misma consulta cuenta también desde la base nueva: ninguna fila nueva
 * queda sin aviso en el cambio de ventana.
 */
class AvisarCambios extends Command
{
    protected $signature = 'cambios:avisar {--cada=300 : Milisegundos entre una mirada y la siguiente}';

    protected $description = 'Avisa en tiempo real (Reverb) cuando el registro de cambios crece';

    public const VENTANA = 2000;

    private ?int $base = null;

    private ?string $huella = null;

    /** Último error anotado en el log: el mismo no se repite en cada vuelta. */
    private ?string $falla = null;

    public function handle(): int
    {
        $cada = max(100, (int) $this->option('cada')) * 1000;
        // La conexión con Reverb se arma ya: si no, el primer aviso esperaría a que se cree.
        rescue(static fn () => Broadcast::connection('reverb'), report: false);

        while (true) {
            if ($this->vuelta() === null) {
                usleep(5_000_000);   // base o Reverb caídos (p. ej. al arrancar el contenedor)
            }
            usleep($cada);
        }
    }

    /**
     * Una mirada: true si avisó, false si no había nada nuevo, null si falló (se reintenta;
     * sin huella, al volver no se avisa de golpe lo que ya estaba).
     */
    public function vuelta(): ?bool
    {
        try {
            $this->base ??= max(0, RegistroCambios::ultimo() - self::VENTANA);
            [$maximo, $total, $desdeNueva, $nuevaBase] = $this->mirar($this->base);

            $aviso = $this->huella !== null && $maximo . ':' . $total !== $this->huella;
            if ($aviso) {
                broadcast(new HuboCambios());
            }
            // La ventana avanza con la misma foto de la tabla: la huella sigue valiendo.
            $this->base   = $nuevaBase;
            $this->huella = $maximo . ':' . $desdeNueva;
            $this->falla  = null;

            return $aviso;
        } catch (Throwable $e) {
            if ($this->falla !== $e->getMessage()) {
                Log::warning('cambios:avisar: ' . $e->getMessage());
                $this->falla = $e->getMessage();
            }
            $this->base = $this->huella = null;
            DB::purge();

            return null;
        }
    }

    /** @return array{0:int,1:int,2:int,3:int} [más alto, filas > base, filas > base nueva, base nueva] */
    private function mirar(int $base): array
    {
        $tope = (int) DB::table(RegistroCambios::TABLA)->max('ID_CAMBIO');
        $nuevaBase = max($base, $tope - self::VENTANA);

        $f = DB::table(RegistroCambios::TABLA)
            ->where('ID_CAMBIO', '>', $base)
            ->selectRaw('MAX(ID_CAMBIO) AS m, COUNT(*) AS c, SUM(CASE WHEN ID_CAMBIO > ? THEN 1 ELSE 0 END) AS r', [$nuevaBase])
            ->first();

        return [(int) $f->m, (int) $f->c, (int) $f->r, $nuevaBase];
    }
}
