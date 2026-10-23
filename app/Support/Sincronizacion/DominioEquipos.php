<?php

namespace App\Support\Sincronizacion;

use App\Models\CatalogoColor;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Falla;
use App\Models\FrenteTrabajo;
use App\Models\TipoEquipo;
use App\Models\Usuario;

/**
 * Dominio EQUIPOS de la copia local: qué equipos ve el usuario, cómo es cada fila y qué
 * filas toca cada cambio del registro (App\Support\RegistroCambios).
 *
 * La fila es el payload del modal (Equipo::toDetailsPayload, fuente única) más los campos
 * con los que la pantalla pinta y filtra la tabla. El navegador arma la tabla y los
 * filtros SOLO con estas filas, con o sin internet: no hay otra lógica.
 *
 * Visibilidad: la misma barrera que el listado (Usuario::aplicarScopeFrentesEquipos), también
 * al buscar.
 */
class DominioEquipos
{
    /** Súbelo cuando cambie la forma de las filas: los clientes recargan su copia. */
    public const ESQUEMA = 1;

    public function __construct(private Usuario $usuario)
    {
    }

    /**
     * Huella del ALCANCE del usuario. Si cambia, la copia entera deja de valer: los
     * equipos que entran o salen de su visibilidad no quedan anotados como cambios.
     */
    public function firma(): string
    {
        $a = $this->usuario->getAttributes();

        return substr(md5(implode('|', [
            (int) ($a['NIVEL_ACCESO_EQUIPOS'] ?? 0),
            (string) ($a['ID_FRENTE_ASIGNADO'] ?? ''),
            (string) ($a['ID_FRENTE_BLOQUEADO'] ?? ''),
        ])), 0, 10);
    }

    /** @return array{datos: array, catalogos: array} */
    public function completo(): array
    {
        return [
            'datos'     => ['equipos' => $this->filas($this->visibles()->pluck('ID_EQUIPO')->all())],
            'catalogos' => $this->catalogos(),
        ];
    }

    /**
     * Lo que cambió según el registro: las filas a reemplazar, los ids a borrar (dados de
     * baja o fuera de su alcance) y los catálogos que hay que reemplazar enteros.
     *
     * @param  array<string,array<int>>  $agregados  agregado → ids (RegistroCambios::desde)
     * @return array{datos: array, borrados: array, catalogos: array}
     */
    public function cambios(array $agregados): array
    {
        $ids = $this->expandir($agregados);
        $ver = $ids ? $this->visibles()->whereIn('ID_EQUIPO', $ids)->pluck('ID_EQUIPO')->all() : [];

        $catalogos = [];
        if (! empty($agregados['frentes']) || ! empty($agregados['tipos'])) {
            $catalogos = $this->catalogos();
        }

        return [
            'datos'     => $ver ? ['equipos' => $this->filas($ver)] : [],
            'borrados'  => ($fuera = array_values(array_diff($ids, $ver))) ? ['equipos' => $fuera] : [],
            'catalogos' => $catalogos,
        ];
    }

    /**
     * Ids de los equipos cuya FILA cambia. Además de los anotados como 'equipos', los que
     * copian datos de un catálogo que cambió (nombre del frente o del tipo, aseguradora,
     * foto de la ficha) y los anclados a cualquiera de ellos (muestran placa, código y foto
     * de su ancla).
     *
     * @return array<int>
     */
    private function expandir(array $agregados): array
    {
        $ids = $agregados['equipos'] ?? [];

        $por = [
            'frentes' => 'ID_FRENTE_ACTUAL',
            'tipos'   => 'id_tipo_equipo',
            'fichas'  => 'ID_ESPEC',
        ];
        foreach ($por as $agregado => $columna) {
            if (! empty($agregados[$agregado])) {
                $ids = array_merge($ids, Equipo::whereIn($columna, $agregados[$agregado])->pluck('ID_EQUIPO')->all());
            }
        }
        if (! empty($agregados['seguros'])) {
            $ids = array_merge($ids, Documentacion::whereIn('ID_SEGURO', $agregados['seguros'])->pluck('ID_EQUIPO')->all());
        }

        $ids = array_map('intval', $ids);
        if ($ids) {
            $ids = array_merge($ids, Equipo::whereIn('ID_ANCLAJE', $ids)->pluck('ID_EQUIPO')->map('intval')->all());
        }

        return array_values(array_unique($ids));
    }

    private function visibles()
    {
        return $this->usuario->aplicarScopeFrentesEquipos(Equipo::query(), 'ID_FRENTE_ACTUAL');
    }

    /** @param  array<int>  $ids */
    private function filas(array $ids): array
    {
        $filas = [];
        foreach (array_chunk($ids, 500) as $tanda) {
            Equipo::whereIn('ID_EQUIPO', $tanda)
                ->with([
                    'documentacion.seguro',
                    ...Equipo::conFoto(),
                    'tipo',
                    'frenteActual',
                    'fallaAbierta:ID_FALLA,ACTIVO_ID,ACTIVO_TIPO,ESTADO_REPORTE,CODIGO_REPORTE,TIPO_REPORTE,FECHA_EMISION,DESCRIPCION_AVERIA,NOMBRE_REPORTA',
                    'ancladoA.tipo',
                    'ancladoA.documentacion',
                    ...Equipo::conFoto('ancladoA.'),
                ])
                ->withCount('equiposAuxiliares')
                ->get()
                ->each(function (Equipo $e) use (&$filas) {
                    $filas[] = $this->fila($e);
                });
        }

        return $filas;
    }

    private function fila(Equipo $e): array
    {
        $falla = $e->fallaAbierta;

        return $e->toDetailsPayload() + [
            'etiqueta'          => $e->NUMERO_ETIQUETA,
            'idTipo'            => $e->id_tipo_equipo ? (int) $e->id_tipo_equipo : null,
            'idEspec'           => $e->ID_ESPEC ? (int) $e->ID_ESPEC : null,
            'estado'            => $e->ESTADO_OPERATIVO,
            'confirmado'        => (int) ($e->CONFIRMADO_EN_SITIO ?? 0),
            'capacidad'         => $e->CAPACIDAD,
            'color'             => CatalogoColor::normalizar($e->COLOR),
            'fotoId'            => $e->fotoDriveId(),
            'falla'             => $falla ? [
                'id'      => (int) $falla->ID_FALLA,
                'codigo'  => $falla->CODIGO_REPORTE,
                'tipo'    => $falla->TIPO_REPORTE,
                'desc'    => $falla->DESCRIPCION_AVERIA,
                'resumen' => $falla->resumen,
            ] + Falla::datosActivo($e) : null,
        ];
    }

    /**
     * Frentes y tipos, enteros: pocos KB, el cliente los reemplaza cada vez que llegan.
     * Frentes: los que el usuario ve, de cualquier estatus (un equipo puede seguir en un
     * frente FINALIZADO), con lo que la tabla necesita para pintarlos y filtrarlos.
     */
    private function catalogos(): array
    {
        $frentes = $this->usuario->aplicarScopeFrentesEquipos(FrenteTrabajo::query(), 'ID_FRENTE')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'ESTATUS_FRENTE', 'TIPO_FRENTE'])
            ->map(static fn ($f) => [
                'id'           => (int) $f->ID_FRENTE,
                'nombre'       => $f->NOMBRE_FRENTE,
                'estatus'      => $f->ESTATUS_FRENTE,
                'tipo'         => $f->TIPO_FRENTE,
                'fueraDeFlota' => in_array($f->TIPO_FRENTE, FrenteTrabajo::TIPOS_FUERA_DE_FLOTA, true),
                'venta'        => FrenteTrabajo::avisoVentaDe($f->TIPO_FRENTE)['clase'] ?? null,
            ])
            ->all();

        $tipos = TipoEquipo::orderBy('nombre')
            ->get(['id', 'nombre', 'ROL_ANCLAJE'])
            ->map(static fn ($t) => ['id' => (int) $t->id, 'nombre' => $t->nombre, 'rol' => $t->ROL_ANCLAJE])
            ->all();

        return ['frentes' => $frentes, 'tipos' => $tipos];
    }
}
