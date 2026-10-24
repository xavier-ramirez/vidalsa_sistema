<?php

namespace App\Support\Sincronizacion;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Movilizacion;
use App\Models\Usuario;

/**
 * Dominio MOVILIZACIONES de la copia local: el historial completo que el usuario puede ver.
 * La pantalla /admin/movilizaciones filtra, pagina y pinta SOLO con estas filas, con o sin
 * internet, y el Excel se pide con los ids que ella eligió: no hay filtros en el servidor.
 *
 * Quién lo ve (decisión del usuario, 23-10-2026): quien tiene permisos de equipos, sin las
 * movilizaciones que tocan un frente bloqueado para él (de origen o de destino).
 *
 * El nombre del usuario que registró va copiado en la fila y la tabla de usuarios no se
 * anota en el registro de cambios: si alguien cambia su nombre, sus filas lo toman en la
 * próxima carga completa.
 */
class DominioMovilizaciones
{
    /** Súbelo cuando cambie la forma de las filas: los clientes recargan su copia. */
    public const ESQUEMA = 2;

    public function __construct(private Usuario $usuario)
    {
    }

    public static function puede(Usuario $usuario): bool
    {
        return $usuario->can('equipos.create') || $usuario->can('equipos.edit') || $usuario->can('equipos.assign');
    }

    public function firma(): string
    {
        return substr(md5((string) ($this->usuario->getAttributes()['ID_FRENTE_BLOQUEADO'] ?? '')), 0, 10);
    }

    /** @return array{datos: array, catalogos: array} */
    public function completo(): array
    {
        return [
            'datos'     => ['movilizaciones' => $this->filas($this->visibles())],
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
        $ver = $ids ? $this->filas($this->visibles()->whereIn('ID_MOVILIZACION', $ids)) : [];
        $fuera = array_values(array_diff($ids, array_column($ver, 'id')));

        return [
            'datos'     => $ver ? ['movilizaciones' => $ver] : [],
            'borrados'  => $fuera ? ['movilizaciones' => $fuera] : [],
            'catalogos' => [],
        ];
    }

    /**
     * Movilizaciones cuya FILA cambia: las anotadas y las que copian datos de un equipo, un
     * auxiliar o un frente que cambió (serial, placa, foto, tipo, nombre del frente).
     *
     * @return array<int>
     */
    private function expandir(array $agregados): array
    {
        $equipos = $agregados['equipos'] ?? [];
        foreach (['tipos' => 'id_tipo_equipo', 'fichas' => 'ID_ESPEC'] as $agregado => $columna) {
            if (! empty($agregados[$agregado])) {
                $equipos = array_merge($equipos, Equipo::withTrashed()->whereIn($columna, $agregados[$agregado])->pluck('ID_EQUIPO')->all());
            }
        }

        $q = Movilizacion::query()->whereRaw('1 = 0');
        if ($equipos) $q->orWhereIn('ID_EQUIPO', $equipos);
        if (! empty($agregados['auxiliares'])) $q->orWhereIn('ID_AUXILIAR', $agregados['auxiliares']);
        if (! empty($agregados['frentes'])) {
            $q->orWhereIn('ID_FRENTE_ORIGEN', $agregados['frentes'])->orWhereIn('ID_FRENTE_DESTINO', $agregados['frentes']);
        }

        $ids = array_merge(
            array_map('intval', $agregados['movilizaciones'] ?? []),
            $q->pluck('ID_MOVILIZACION')->map('intval')->all()
        );

        return array_values(array_unique($ids));
    }

    /** Las que el usuario puede ver. También acota la exportación a Excel. */
    public function visibles()
    {
        $bloqueados = $this->usuario->getFrentesBloqueadosIds();

        return Movilizacion::query()
            ->when($bloqueados, fn ($q) => $q
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_ORIGEN', $bloqueados)->orWhereNull('ID_FRENTE_ORIGEN'))
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_DESTINO', $bloqueados)->orWhereNull('ID_FRENTE_DESTINO')));
    }

    private function filas($query): array
    {
        $tiposAux = EquipoAuxiliar::tiposLabel();

        return $query
            ->with([
                // withTrashed: el historial de un equipo dado de baja se sigue viendo con sus datos.
                'equipo' => fn ($q) => $q->withTrashed(),
                'equipo.tipo:id,nombre',
                'equipo.documentacion:ID_EQUIPO,PLACA',
                ...Equipo::conFoto('equipo.'),
                'auxiliar' => fn ($q) => $q->withTrashed()->select('ID_AUXILIAR', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'FOTO', 'deleted_at'),
                'frenteOrigen:ID_FRENTE,NOMBRE_FRENTE',
                'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
                'usuario:ID_USUARIO,NOMBRE_COMPLETO,CORREO_ELECTRONICO',
            ])
            ->get()
            ->map(static function (Movilizacion $m) use ($tiposAux) {
                $e = $m->ID_AUXILIAR ? null : $m->equipo;
                $a = $m->ID_AUXILIAR ? $m->auxiliar : null;
                $cc = preg_replace('/[^0-9]/', '', (string) $m->CODIGO_CONTROL);

                return [
                    'id'        => (int) $m->ID_MOVILIZACION,
                    'codigo'    => $m->CODIGO_CONTROL,
                    'mv'        => $cc !== '' ? 'MV-' . str_pad($cc, 5, '0', STR_PAD_LEFT) : null,
                    'tipoMov'   => $m->TIPO_MOVIMIENTO,
                    'fecha'     => $m->created_at?->format('Y-m-d H:i:s'),
                    'idOrigen'  => $m->ID_FRENTE_ORIGEN ? (int) $m->ID_FRENTE_ORIGEN : null,
                    'idDestino' => $m->ID_FRENTE_DESTINO ? (int) $m->ID_FRENTE_DESTINO : null,
                    'origen'    => $m->nombre_origen,
                    'destino'   => $m->nombre_destino,
                    'usuario'   => $m->usuario->NOMBRE_COMPLETO ?? $m->USUARIO_REGISTRO,
                    // Para el modal «Movilizaciones» del detalle del equipo (uicomponents.js).
                    'idEquipo'  => $m->ID_EQUIPO ? (int) $m->ID_EQUIPO : null,
                    'detalle'   => $m->DETALLE_UBICACION,
                    'equipo'    => $e ? [
                        'idTipo'   => $e->id_tipo_equipo ? (int) $e->id_tipo_equipo : null,
                        'tipo'     => $e->tipo->nombre ?? null,
                        'serial'   => $e->SERIAL_CHASIS,
                        'placa'    => $e->documentacion->PLACA ?? null,
                        'codigo'   => $e->CODIGO_PATIO,
                        'etiqueta' => $e->NUMERO_ETIQUETA,
                        'fotoId'   => $e->fotoDriveId(),
                    ] : null,
                    'aux'       => $a ? [
                        'tipo'      => $a->TIPO,
                        'tipoLabel' => $tiposAux[$a->TIPO] ?? $a->TIPO,
                        'serial'    => $a->SERIAL,
                        'marca'     => $a->MARCA,
                        'modelo'    => $a->MODELO,
                        'fotoId'    => CaracteristicaModelo::idDrive($a->FOTO),
                    ] : null,
                ];
            })
            ->all();
    }
}
