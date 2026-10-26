<?php

namespace App\Console\Commands;

use App\Events\HuboCambios;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
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
 * + cuántas filas hay después de `$base`, una ventana de las últimas `$ventana` filas que
 * avanza sola. Al moverla, la misma consulta cuenta también desde la base nueva: ninguna fila
 * nueva queda sin aviso en el cambio de ventana.
 *
 * Mira además el ACCESO de los usuarios (Usuario::CAMPOS_ACCESO): cambiarle el frente o el
 * nivel a alguien no pasa por `cambios`, pero cambia lo que su copia debe tener (la firma).
 *
 * Y cada LATIDO_SEG manda un latido: el navegador solo se da por «en vivo» (y deja de
 * preguntar cada 15 s) mientras le llegan. Si este vigía se cae, dejan de llegar y todos
 * vuelven solos a preguntar cada 15 s.
 */
class AvisarCambios extends Command
{
    protected $signature = 'cambios:avisar {--cada=300 : Milisegundos entre una mirada y la siguiente}';

    protected $description = 'Avisa en tiempo real (Reverb) cuando el registro de cambios crece';

    public const VENTANA = 2000;

    public const LATIDO_SEG = 25;

    private ?int $base = null;

    private ?string $huella = null;

    private int $ultimoLatido = 0;

    /** Último error anotado en el log: el mismo no se repite en cada vuelta. */
    private ?string $falla = null;

    /** @param int $ventana Filas que abarca la huella (las pruebas usan una chica). */
    public function __construct(private int $ventana = self::VENTANA)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cada = max(100, (int) $this->option('cada')) * 1000;
        // La conexión con Reverb se arma ya: si no, el primer aviso esperaría a que se cree.
        rescue(static fn () => Broadcast::connection('reverb'), report: false);

        while (true) {
            $bien = $this->vuelta() !== null;
            if ($bien && time() - $this->ultimoLatido >= self::LATIDO_SEG) {
                $this->latir();
            }
            usleep($bien ? $cada : 5_000_000);   // base o Reverb caídos (p. ej. al arrancar el contenedor)
        }
    }

    /**
     * Una mirada: true si avisó, false si no había nada nuevo, null si falló. La huella y la
     * ventana se conservan: si lo que falló fue el aviso, la vuelta siguiente lo repite, y si
     * fue la base, al volver se avisa una vez lo que cambió mientras tanto.
     */
    public function vuelta(): ?bool
    {
        try {
            $this->base ??= max(0, RegistroCambios::ultimo() - $this->ventana);
            [$maximo, $total, $desdeNueva, $nuevaBase] = $this->mirar($this->base);
            $acceso = $this->accesos();

            $aviso = $this->huella !== null && "{$maximo}:{$total}:{$acceso}" !== $this->huella;
            if ($aviso) {
                broadcast(new HuboCambios());
                $this->ultimoLatido = time();   // un aviso también dice «sigo vivo»
            }
            // La ventana avanza con la misma foto de la tabla: la huella sigue valiendo.
            $this->base   = $nuevaBase;
            $this->huella = "{$maximo}:{$desdeNueva}:{$acceso}";
            $this->falla  = null;

            return $aviso;
        } catch (Throwable $e) {
            $this->anotarFalla($e);
            // Solo si lo que falló es la base se reconecta; un aviso que no salió no la toca.
            if ($e instanceof QueryException || $e instanceof PDOException) {
                DB::purge();
            }

            return null;
        }
    }

    /** «Sigo vivo». Si no sale, el navegador vuelve a preguntar cada 15 s: no hace falta más. */
    public function latir(): bool
    {
        $this->ultimoLatido = time();
        try {
            broadcast(new HuboCambios(latido: true));

            return true;
        } catch (Throwable $e) {
            $this->anotarFalla($e);

            return false;
        }
    }

    private function anotarFalla(Throwable $e): void
    {
        if ($this->falla !== $e->getMessage()) {
            Log::warning('cambios:avisar: ' . $e->getMessage());
            $this->falla = $e->getMessage();
        }
    }

    /** @return array{0:int,1:int,2:int,3:int} [más alto, filas > base, filas > base nueva, base nueva] */
    private function mirar(int $base): array
    {
        $tope = (int) DB::table(RegistroCambios::TABLA)->max('ID_CAMBIO');
        $nuevaBase = max($base, $tope - $this->ventana);

        $f = DB::table(RegistroCambios::TABLA)
            ->where('ID_CAMBIO', '>', $base)
            ->selectRaw('MAX(ID_CAMBIO) AS m, COUNT(*) AS c, SUM(CASE WHEN ID_CAMBIO > ? THEN 1 ELSE 0 END) AS r', [$nuevaBase])
            ->first();

        return [(int) $f->m, (int) $f->c, (int) $f->r, $nuevaBase];
    }

    /** Suma de control del acceso de todos los usuarios: cambia si se edita, crea o borra uno. */
    private function accesos(): string
    {
        $campos = implode(', ', array_merge(['ID_USUARIO'], Usuario::CAMPOS_ACCESO));
        $f = DB::table('usuarios')
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', {$campos}))), 0) AS s")
            ->first();

        return $f->n . '/' . $f->s;
    }
}
