<?php

namespace App\Support\Sincronizacion;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Falla;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Support\Str;

/**
 * Dominio FALLAS de la copia local: los reportes de falla. La pantalla /admin/fallas filtra,
 * pagina, cuenta y pinta SOLO con estas filas, con o sin internet (fallas_index.js): no hay
 * filtros en el servidor.
 *
 * Quién lo ve: todos, igual que la pantalla (FallaController no la restringe), menos los
 * reportes de activos que están en un frente bloqueado para el usuario: bloqueado se resta
 * en todo. Cerrar, crear y borrar siguen pidiendo sus permisos en el servidor.
 *
 * Cada fila copia los datos de su activo (tipo, marca, serial, placa, foto, frente actual):
 * cambiar el equipo, el auxiliar, su tipo, su ficha o el nombre de su frente reenvía sus
 * reportes (expandir).
 */
class DominioFallas
{
    /** Súbelo cuando cambie la forma de las filas: los clientes recargan su copia. */
    public const ESQUEMA = 1;

    /** Lo que la tarjeta muestra de la descripción; el resto solo lo trae el acta. */
    private const DESCRIPCION_MAX = 220;

    public function __construct(private Usuario $usuario)
    {
    }

    public static function puede(Usuario $usuario): bool
    {
        return true;
    }

    /** Lo único que cambia lo que ve cada usuario son sus frentes bloqueados. */
    public function firma(): string
    {
        return $this->usuario->huellaFrentesBloqueados();
    }

    /** @return array{datos: array, catalogos: array} */
    public function completo(): array
    {
        return [
            'datos'     => ['fallas' => $this->filas(Falla::query())],
            'catalogos' => [],
        ];
    }

    /**
     * @param  array<string,array<int>>  $agregados  agregado → ids (RegistroCambios::desde)
     * @return array{datos: array, borrados: array, catalogos: array}
     */
    public function cambios(array $agregados): array
    {
        $ids = $this->expandir($agregados);
        $ver = $ids ? $this->filas(Falla::query()->whereIn('ID_FALLA', $ids)) : [];
        $fuera = array_values(array_diff($ids, array_column($ver, 'id')));

        return [
            'datos'     => $ver ? ['fallas' => $ver] : [],
            'borrados'  => $fuera ? ['fallas' => $fuera] : [],
            'catalogos' => [],
        ];
    }

    /**
     * Reportes cuya FILA cambia: los anotados y los de un equipo o auxiliar que cambió, o
     * que copia datos de un tipo, una ficha o un frente que cambió.
     *
     * @return array<int>
     */
    private function expandir(array $agregados): array
    {
        $equipos = $agregados['equipos'] ?? [];
        $auxiliares = $agregados['auxiliares'] ?? [];
        foreach (['tipos' => 'id_tipo_equipo', 'fichas' => 'ID_ESPEC'] as $agregado => $columna) {
            if (! empty($agregados[$agregado])) {
                $equipos = array_merge($equipos, Equipo::withTrashed()->whereIn($columna, $agregados[$agregado])->pluck('ID_EQUIPO')->all());
            }
        }
        if (! empty($agregados['frentes'])) {
            $equipos = array_merge($equipos, Equipo::withTrashed()->whereIn('ID_FRENTE_ACTUAL', $agregados['frentes'])->pluck('ID_EQUIPO')->all());
            $auxiliares = array_merge($auxiliares, EquipoAuxiliar::withTrashed()->whereIn('ID_FRENTE_ACTUAL', $agregados['frentes'])->pluck('ID_AUXILIAR')->all());
        }

        $q = Falla::query()->whereRaw('1 = 0');
        if ($equipos) $q->orWhere(fn ($w) => $w->where('ACTIVO_TIPO', 'equipo')->whereIn('ACTIVO_ID', array_unique($equipos)));
        if ($auxiliares) $q->orWhere(fn ($w) => $w->where('ACTIVO_TIPO', 'equipo_auxiliar')->whereIn('ACTIVO_ID', array_unique($auxiliares)));

        $ids = array_merge(
            array_map('intval', $agregados['fallas'] ?? []),
            ($equipos || $auxiliares) ? $q->pluck('ID_FALLA')->map('intval')->all() : []
        );

        return array_values(array_unique($ids));
    }

    private function filas($query): array
    {
        $fallas = $query->get();
        $idsDe = fn (string $tipo) => $fallas->where('ACTIVO_TIPO', $tipo)->pluck('ACTIVO_ID')->unique()->all();

        // withTrashed: el reporte de un equipo dado de baja se sigue viendo con sus datos.
        $equipos = Equipo::withTrashed()->with([...Equipo::conFoto(), 'documentacion:ID_EQUIPO,PLACA', 'tipo:id,nombre'])
            ->whereIn('ID_EQUIPO', $idsDe('equipo'))->get()->keyBy('ID_EQUIPO');
        $auxiliares = EquipoAuxiliar::withTrashed()->whereIn('ID_AUXILIAR', $idsDe('equipo_auxiliar'))->get()->keyBy('ID_AUXILIAR');
        $frentes = FrenteTrabajo::withTrashed()->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        $activo = static fn (Falla $f) => $f->ACTIVO_TIPO === 'equipo_auxiliar'
            ? $auxiliares->get($f->ACTIVO_ID)
            : ($f->ACTIVO_TIPO === 'equipo' ? $equipos->get($f->ACTIVO_ID) : null);
        // Lo que se resta aquí sale en cambios() como borrado: un equipo que entra a un
        // frente bloqueado se lleva sus reportes de la copia.
        $bloqueados = array_map('intval', $this->usuario->getFrentesBloqueadosIds());
        if ($bloqueados) {
            $fallas = $fallas->reject(fn (Falla $f) => in_array((int) $activo($f)?->ID_FRENTE_ACTUAL, $bloqueados, true));
        }

        return $fallas->map(static function (Falla $f) use ($activo, $frentes) {
            $esAux = $f->ACTIVO_TIPO === 'equipo_auxiliar';
            $a = $activo($f);
            $idFrente = $a?->ID_FRENTE_ACTUAL ? (int) $a->ID_FRENTE_ACTUAL : null;

            return [
                'id'             => (int) $f->ID_FALLA,
                'codigo'         => $f->CODIGO_REPORTE,
                'fecha'          => $f->FECHA_EMISION?->format('Y-m-d H:i:s'),
                'estado'         => $f->ESTADO_REPORTE,
                'tipoReporte'    => $f->TIPO_REPORTE,
                'mant'           => $f->TIPO_MANTENIMIENTO,
                'descripcion'    => $f->DESCRIPCION_AVERIA ? Str::limit($f->DESCRIPCION_AVERIA, self::DESCRIPCION_MAX) : null,
                'idReporta'      => $f->ID_USUARIO_REPORTA ? (int) $f->ID_USUARIO_REPORTA : null,
                'reporta'        => $f->NOMBRE_REPORTA,
                // Sección taller: el modal de cierre viene pre-llenado con esto.
                'mecanico'       => $f->MECANICO_ASIGNADO,
                'fechaRecepcion' => $f->FECHA_RECEPCION?->format('Y-m-d'),
                'diagnostico'    => $f->DIAGNOSTICO,
                'acciones'       => $f->ACCIONES_REALIZADAS,
                'activoTipo'     => $f->ACTIVO_TIPO,
                'activo'         => $a ? [
                    'idTipo'      => $esAux ? null : ($a->id_tipo_equipo ? (int) $a->id_tipo_equipo : null),
                    'tipoAux'     => $esAux ? $a->TIPO : null,
                    'tipo'        => $esAux ? $a->TIPO : $a->tipo?->nombre,
                    'marca'       => trim((string) $a->MARCA),
                    'modelo'      => trim((string) $a->MODELO),
                    'serial'      => $esAux ? $a->SERIAL : $a->SERIAL_CHASIS,
                    'serialMotor' => $esAux ? null : $a->SERIAL_DE_MOTOR,
                    'placa'       => $esAux ? null : $a->documentacion?->PLACA,
                    'codigo'      => $esAux ? $a->CODIGO_INTERNO : $a->CODIGO_PATIO,
                    'fotoId'      => $esAux ? CaracteristicaModelo::idDrive($a->FOTO) : $a->fotoDriveId(),
                    'idFrente'    => $idFrente,
                    'frente'      => $idFrente ? ($frentes[$idFrente] ?? null) : null,
                ] + Falla::datosActivo($a) : null,
            ];
        })->values()->all();
    }
}
