<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\AlmacenLogistica;
use App\Models\MovimientoInventario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transporte de la Nota de Entrega: los choferes y vehículos con que despacha cada almacén.
 * Punto único para sugerir (formulario de la salida y modal "Editar almacén"), limpiar lo que
 * llega en una salida, recordar lo que se usó y guardar la lista del almacén.
 */
class LogisticaAlmacenService
{
    /**
     * Sugerencias para la Nota que emite este almacén: primero su lista (lo usado hace menos
     * arriba) y después la flota de sus frentes, con la persona asignada a cada vehículo como
     * chofer. Sin repetir: lo que ya está en la lista no se vuelve a ofrecer.
     *
     * Cada vehículo de la flota trae `serial` (de chasis) para buscarlo también por ahí; el que
     * no tiene placa de verdad se ofrece con su serial como documento: es lo que lo identifica.
     * Trae además su `tipo`: la lista muestra placa, serial y tipo, sin marca ni modelo, mientras
     * que `nombre` (tipo, marca y modelo) es lo que llena el campo Vehículo de la nota. Un vehículo
     * de la lista del almacén que también está en la flota toma de ahí su serial y su tipo.
     */
    /**
     * Lo que NUNCA se sugiere como vehiculo de la Nota de Entrega.
     *
     * La flota PESADA (payloaders, excavadoras, tractores de oruga...) no sale de la obra a
     * buscar repuestos: ofrecerla solo estorba la lista. Pedido del cliente.
     */
    private const CATEGORIAS_QUE_NO_TRANSPORTAN = ['FLOTA PESADA'];

    /**
     * Y estos POR TIPO, aunque su categoria diga otra cosa. El VACUUM es un camion de
     * succion y esta catalogado como flota LIVIANA (28 unidades), asi que filtrando solo
     * por categoria se colaria igual. Se compara en mayusculas y sin espacios de sobra.
     */
    private const TIPOS_QUE_NO_TRANSPORTAN = ['VACUUM'];

    public function sugerencias(Almacen $almacen): array
    {
        $filas = AlmacenLogistica::where('ID_ALMACEN', $almacen->ID_ALMACEN)
            ->orderByRaw('ULTIMO_USO IS NULL')->orderByDesc('ULTIMO_USO')->orderBy('NOMBRE')->get();
        $choferes  = $this->aLista($filas->where('TIPO', AlmacenLogistica::TIPO_CHOFER));
        $vehiculos = $this->aLista($filas->where('TIPO', AlmacenLogistica::TIPO_VEHICULO));

        $flota = $this->flotaDeFrentes($almacen);
        $deFlota = $flota->map(fn ($v) => [
            'nombre'    => mb_strtoupper(trim(preg_replace('/\s+/', ' ', "{$v->TIPO} {$v->MARCA} {$v->MODELO}"))),
            'documento' => $v->placaValida ? strtoupper(preg_replace('/\s+/', '', $v->PLACA)) : $v->serial,
            'serial'    => $v->serial,
            'tipo'      => mb_strtoupper(trim((string) $v->TIPO)),
        ]);
        // Los de la lista del almacén conservan su nombre (es lo que llena Vehículo), pero si están
        // en la flota se completan con su serial y su tipo: la lista los muestra igual que al resto.
        $porClave  = $deFlota->keyBy(fn ($x) => AlmacenLogistica::clave(AlmacenLogistica::TIPO_VEHICULO, $x['documento']));
        $vehiculos = $vehiculos->map(function ($x) use ($porClave) {
            $f = $porClave->get(AlmacenLogistica::clave(AlmacenLogistica::TIPO_VEHICULO, (string) $x['documento']));
            return $f ? ['nombre' => $x['nombre'], 'documento' => $x['documento'], 'serial' => $f['serial'], 'tipo' => $f['tipo'], 'origen' => $x['origen']] : $x;
        });
        $this->sumar($vehiculos, AlmacenLogistica::TIPO_VEHICULO, $deFlota);
        $asignados = DB::table('responsable')->whereIn('ID_EQUIPO', $flota->pluck('ID_EQUIPO'))
            ->orderByDesc('FECHA_ASIGNACION')->orderByDesc('ID_ASIGNACION')
            ->get(['ID_EQUIPO', 'PERSONA_ASIGNADA', 'CEDULA_RESPONSABLE'])->unique('ID_EQUIPO');
        $this->sumar($choferes, AlmacenLogistica::TIPO_CHOFER, $asignados->map(fn ($r) => [
            'nombre'    => mb_strtoupper(trim((string) $r->PERSONA_ASIGNADA)),
            'documento' => trim((string) $r->CEDULA_RESPONSABLE),
        ]));

        return ['choferes' => $choferes->values()->all(), 'vehiculos' => $vehiculos->values()->all()];
    }

    /**
     * El transporte que trae una salida, limpio y con las claves de MovimientoInventario::
     * CAMPOS_TRANSPORTE: nombres en mayúsculas (como el resto de la nota), placa sin espacios
     * y vacíos en null.
     */
    public function transporteDe(array $data): array
    {
        $limpio = [];
        foreach (array_keys(MovimientoInventario::CAMPOS_TRANSPORTE) as $campo) {
            $v = trim(preg_replace('/\s+/', ' ', (string) ($data[$campo] ?? '')));
            if ($campo === 'transporte_placa') {
                $v = str_replace(' ', '', $v);
            }
            $limpio[$campo] = $v === '' ? null : ($campo === 'transporte_cedula' ? $v : mb_strtoupper($v));
        }
        return $limpio;
    }

    /**
     * Recuerda el chofer y el vehículo de una nota recién registrada: si son nuevos entran a
     * la lista del almacén, y si ya estaban suben al principio de las sugerencias. Solo con el
     * dato completo (nombre y cédula, vehículo y placa): medio dato no sirve para la próxima.
     */
    public function recordar(int $idAlmacen, array $transporte): void
    {
        $pares = [
            [AlmacenLogistica::TIPO_CHOFER,   $transporte['transporte_chofer'] ?? null,   $transporte['transporte_cedula'] ?? null],
            [AlmacenLogistica::TIPO_VEHICULO, $transporte['transporte_vehiculo'] ?? null, $transporte['transporte_placa'] ?? null],
        ];
        foreach ($pares as [$tipo, $nombre, $documento]) {
            $clave = AlmacenLogistica::clave($tipo, (string) $documento);
            if (!$nombre || $clave === '') {
                continue;
            }
            $fila = AlmacenLogistica::firstOrNew(['ID_ALMACEN' => $idAlmacen, 'TIPO' => $tipo, 'CLAVE' => $clave]);
            if (!$fila->exists) {
                $fila->NOMBRE    = $nombre;
                $fila->DOCUMENTO = $documento;
            }
            $fila->ULTIMO_USO = now();
            $fila->save();
        }
    }

    /**
     * Deja la lista del almacén exactamente con estos choferes y vehículos (modal "Editar
     * almacén"): agrega los nuevos, corrige los que cambiaron y borra los quitados. Cada uno
     * es ['nombre' => ..., 'documento' => ...]; sin nombre o sin documento no entra.
     */
    public function sincronizar(int $idAlmacen, array $choferes, array $vehiculos): void
    {
        $actuales = AlmacenLogistica::where('ID_ALMACEN', $idAlmacen)->get()->keyBy(fn ($f) => $f->TIPO . '|' . $f->CLAVE);
        $quedan = [];
        foreach ([AlmacenLogistica::TIPO_CHOFER => $choferes, AlmacenLogistica::TIPO_VEHICULO => $vehiculos] as $tipo => $lista) {
            foreach ($lista as $item) {
                $nombre    = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($item['nombre'] ?? ''))));
                $documento = trim((string) ($item['documento'] ?? ''));
                if ($tipo === AlmacenLogistica::TIPO_VEHICULO) {
                    $documento = strtoupper(str_replace(' ', '', $documento));
                }
                $clave = AlmacenLogistica::clave($tipo, $documento);
                if ($nombre === '' || $clave === '' || isset($quedan[$tipo . '|' . $clave])) {
                    continue;
                }
                $quedan[$tipo . '|' . $clave] = true;
                $fila = $actuales->get($tipo . '|' . $clave)
                    ?? new AlmacenLogistica(['ID_ALMACEN' => $idAlmacen, 'TIPO' => $tipo, 'CLAVE' => $clave]);
                $fila->fill(['NOMBRE' => $nombre, 'DOCUMENTO' => $documento])->save();
            }
        }
        $actuales->reject(fn ($f, $k) => isset($quedan[$k]))->each->delete();
    }

    /**
     * Vehículos de los frentes del almacén con algo que los identifique en la Nota: una placa de
     * verdad (vive en documentacion) o, si no la tienen, su serial de chasis.
     */
    private function flotaDeFrentes(Almacen $almacen): Collection
    {
        $frentes = $almacen->frentes()->pluck('frentes_trabajo.ID_FRENTE');
        if ($frentes->isEmpty()) {
            return collect();
        }
        return DB::table('equipos as e')
            ->leftJoin('documentacion as d', 'd.ID_EQUIPO', '=', 'e.ID_EQUIPO')
            ->leftJoin('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereIn('e.ID_FRENTE_ACTUAL', $frentes)->whereNull('e.deleted_at')
            // Nada que no vaya a llevar material (ver las dos constantes de arriba).
            ->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(e.CATEGORIA_FLOTA, '')))"), self::CATEGORIAS_QUE_NO_TRANSPORTAN)
            ->where(function ($q) {
                $q->whereNull('t.nombre')
                  ->orWhereNotIn(DB::raw('UPPER(TRIM(t.nombre))'), self::TIPOS_QUE_NO_TRANSPORTAN);
            })
            ->orderBy('t.nombre')->orderBy('e.MARCA')->orderBy('e.MODELO')->orderBy('e.ID_EQUIPO')
            ->get(['e.ID_EQUIPO', 'd.PLACA', 'e.SERIAL_CHASIS', 'e.MARCA', 'e.MODELO', 't.nombre as TIPO'])
            ->map(function ($v) {
                // La flota tiene placas de relleno ("-", "...", "N/A"): una placa de verdad lleva
                // letras y números.
                $v->placaValida = (bool) preg_match('/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{5,10}$/', AlmacenLogistica::clave(AlmacenLogistica::TIPO_VEHICULO, (string) $v->PLACA));
                $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v->SERIAL_CHASIS));
                $v->serial = strlen($serial) >= 5 ? $serial : '';
                return $v;
            })
            ->filter(fn ($v) => $v->placaValida || $v->serial !== '')
            ->values();
    }

    /** Agrega a la lista lo que todavía no está (misma clave = mismo chofer o vehículo). */
    private function sumar(Collection $lista, string $tipo, Collection $nuevos): void
    {
        $ya = $lista->map(fn ($x) => AlmacenLogistica::clave($tipo, $x['documento']))->flip();
        foreach ($nuevos as $n) {
            $clave = AlmacenLogistica::clave($tipo, $n['documento']);
            if ($n['nombre'] === '' || $clave === '' || $ya->has($clave)) {
                continue;
            }
            $ya->put($clave, true);
            $lista->push($n + ['origen' => 'flota']);
        }
    }

    private function aLista(Collection $filas): Collection
    {
        return $filas->map(fn ($f) => ['nombre' => $f->NOMBRE, 'documento' => $f->DOCUMENTO, 'origen' => 'almacen'])->values();
    }
}
