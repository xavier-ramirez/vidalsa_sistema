<?php

namespace App\Console\Commands;

use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga los movimientos de junio de los filtros desde la hoja "Movimientos Junio
 * 2026" del Excel maestro: una ENTRADA por lo recibido y una SALIDA por cada
 * frente, TODAS con fecha 2026-06-20, hacia el almacén Barcelona. Usa
 * InventarioService (mantiene almacen_stock + saldos). Idempotente: borra los
 * movimientos y el stock de los filtros antes de recargar, así se puede re-correr.
 */
class CargarMovimientosFiltros extends Command
{
    protected $signature   = 'filtros:cargar-movimientos {archivo?} {--fecha=2026-06-20} {--almacen=1}';
    protected $description  = 'Carga entradas (recibido) y salidas por frente de junio para los filtros';

    /** Columna del Excel (nombre de frente) → ID_FRENTE en BD. null = sin frente en BD. */
    private array $frenteMap = [
        'CORTA FUEGOS AYACUCHO'     => 46,
        'ORINOCO OIL'               => 4,
        'VALVULAS'                  => 11,
        'XP'                        => null,
        'CORTA FUEGOS - CARABOBO'   => 47,
        'TRANS. CARABOBO'           => 3,
        'ALQ. MAQUINARIA SINOVENSA' => 13,
        'TRANSV. AYACUCHO'          => 10,
        'CORTA FUEGOS - AYACUCHO'   => 46,
        'OLEODUCTO 30" X 14 KM'     => 9,
        'AGUA SALADA'               => null,
        'CHUTO + BATEA'             => 8,
        'BARCELONA'                 => 15,
    ];

    private function norm(?string $s): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', (string) $s)));
    }

    public function handle(InventarioService $inv): int
    {
        $ruta = $this->argument('archivo')
            ?: 'C:/Users/dell12/Downloads/MAESTRO Filtros - Equivalencias y Equipos.xlsx';
        $fecha      = (string) $this->option('fecha');
        $idAlmacen  = (int) $this->option('almacen');
        $idUsuario  = (int) (DB::table('usuarios')->value('ID_USUARIO'));

        if (!is_file($ruta)) { $this->error("No encontré el archivo: {$ruta}"); return self::FAILURE; }

        $sheet = IOFactory::load($ruta)->getSheetByName('Movimientos Junio 2026')?->toArray();
        if (!$sheet) { $this->error('Falta la hoja "Movimientos Junio 2026".'); return self::FAILURE; }

        // Encabezado: col 0=Código,1=Tipo,2=Nº parte,3=Recibido, luego frentes, Total, Saldo.
        $head = $sheet[0];
        $colFrente = []; // índice de columna => ID_FRENTE|null
        $frentesNoMapeados = [];
        for ($c = 4; $c < count($head) - 2; $c++) {         // entre Recibido y "Total salió"
            $nom = $this->norm($head[$c] ?? '');
            if ($nom === '') continue;
            if (array_key_exists($nom, $this->frenteMap)) {
                $colFrente[$c] = ['id' => $this->frenteMap[$nom], 'nombre' => trim((string) $head[$c])];
            } else {
                $frentesNoMapeados[$nom] = true;
                $colFrente[$c] = ['id' => null, 'nombre' => trim((string) $head[$c])];
            }
        }

        $codToId = ProductoInventario::where('CATEGORIA', 'FILTROS')->pluck('ID_PRODUCTO', 'CODIGO');

        // ── Reset idempotente: borra kardex + stock de los filtros en este almacén ──
        $ids = $codToId->values()->all();
        DB::table('movimientos_inventario')->whereIn('ID_PRODUCTO', $ids)->where('ID_ALMACEN', $idAlmacen)->delete();
        DB::table('almacen_stock')->whereIn('ID_PRODUCTO', $ids)->where('ID_ALMACEN', $idAlmacen)->delete();

        $nEnt = 0; $nSal = 0; $errores = [];

        foreach (array_slice($sheet, 1) as $row) {
            $cod = trim((string) ($row[0] ?? ''));
            if ($cod === '' || strtoupper($cod) === 'TOTALES' || !isset($codToId[$cod])) continue;
            $pid = (int) $codToId[$cod];

            $recibido = (float) ($row[3] ?? 0);
            if ($recibido > 0) {
                $inv->registrarEntrada($idAlmacen, $pid, $recibido, [
                    'fecha' => $fecha, 'id_usuario' => $idUsuario,
                    'motivo' => 'Carga inicial filtros — junio 2026',
                ]);
                $nEnt++;
            }

            foreach ($colFrente as $c => $fr) {
                $cant = (float) ($row[$c] ?? 0);
                if ($cant <= 0) continue;
                try {
                    $inv->registrarSalida($idAlmacen, $pid, $cant, [
                        'fecha' => $fecha, 'id_usuario' => $idUsuario,
                        'id_frente' => $fr['id'],
                        // Si el frente no tiene ID en BD, dejamos su nombre en el motivo.
                        'motivo' => $fr['id'] ? null : ('Salida a frente: ' . $fr['nombre']),
                    ]);
                    $nSal++;
                } catch (\Throwable $e) {
                    $errores[] = "{$cod} → {$fr['nombre']} ({$cant}): " . $e->getMessage();
                }
            }
        }

        $this->newLine();
        $this->info("Entradas registradas: {$nEnt}");
        $this->info("Salidas registradas:  {$nSal}  (fecha {$fecha}, almacén {$idAlmacen})");
        if ($frentesNoMapeados) {
            $this->warn('Frentes del Excel SIN equivalente en BD (salida sin frente, nombre en el motivo): ' . implode(' · ', array_keys($frentesNoMapeados)));
        }
        if ($errores) {
            $this->warn(count($errores) . ' salidas fallaron (stock insuficiente):');
            foreach (array_slice($errores, 0, 10) as $e) $this->line('  ' . $e);
        }
        return self::SUCCESS;
    }
}
