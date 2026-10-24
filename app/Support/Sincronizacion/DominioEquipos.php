<?php

namespace App\Support\Sincronizacion;

use App\Models\CatalogoColor;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Falla;
use App\Models\FrenteTrabajo;
use App\Models\TipoEquipo;
use App\Models\Usuario;

/**
 * Dominio EQUIPOS de la copia local: qué equipos y auxiliares ve el usuario, cómo es cada
 * fila y qué filas toca cada cambio del registro (App\Support\RegistroCambios).
 *
 * La pantalla /admin/equipos arma la tabla, el Consolidado, la Distribución y los filtros
 * SOLO con estas filas (equipos_local.js), con o sin internet: no hay otra lógica.
 *   · equipos:    el payload del modal (Equipo::toDetailsPayload) más los campos con los
 *                 que la tabla se pinta y se filtra (los "*Real" son el valor de la columna,
 *                 sin el 'N/A' que pone el modal).
 *   · auxiliares: lo que pinta y filtra su fila, más `det` (EquipoAuxiliar::toDetailsPayload).
 *
 * Visibilidad: la misma barrera que el listado (Usuario::aplicarScopeFrentesEquipos), también
 * al buscar.
 */
class DominioEquipos
{
    /** Súbelo cuando cambie la forma de las filas: los clientes recargan su copia. */
    public const ESQUEMA = 2;

    public function __construct(private Usuario $usuario)
    {
    }

    /** El listado de equipos lo abre cualquier usuario con sesión; su alcance lo acota visibles(). */
    public static function puede(Usuario $usuario): bool
    {
        return true;
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
            'datos' => [
                'equipos'    => $this->filas($this->visibles()->pluck('ID_EQUIPO')->all()),
                'auxiliares' => $this->filasAux($this->auxVisibles()->pluck('ID_AUXILIAR')->all()),
            ],
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

        $aux    = $this->expandirAux($agregados, $ids);
        $verAux = $aux ? $this->auxVisibles()->whereIn('ID_AUXILIAR', $aux)->pluck('ID_AUXILIAR')->all() : [];

        $datos = array_filter([
            'equipos'    => $ver ? $this->filas($ver) : [],
            'auxiliares' => $verAux ? $this->filasAux($verAux) : [],
        ]);
        $borrados = array_filter([
            'equipos'    => array_values(array_diff($ids, $ver)),
            'auxiliares' => array_values(array_diff($aux, $verAux)),
        ]);

        $catalogos = [];
        if (! empty($agregados['frentes']) || ! empty($agregados['tipos']) || ! empty($agregados['auxiliares'])) {
            $catalogos = $this->catalogos();
        }

        return ['datos' => $datos, 'borrados' => $borrados, 'catalogos' => $catalogos];
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

    /**
     * Ids de los auxiliares cuya FILA cambia: los anotados, los de un frente que cambió, los
     * montados en un equipo cuya fila cambió ($equipos: su modal muestra datos del anfitrión)
     * y los de la misma marca y modelo que uno anotado (de ahí sale la foto del que no tiene).
     *
     * @param  array<int>  $equipos
     * @return array<int>
     */
    private function expandirAux(array $agregados, array $equipos): array
    {
        $ids = array_map('intval', $agregados['auxiliares'] ?? []);

        if ($ids) {
            $modelos = EquipoAuxiliar::withTrashed()->whereIn('ID_AUXILIAR', $ids)->get(['MARCA', 'MODELO']);
            foreach ($modelos->unique(fn ($a) => $a->MARCA . '|' . $a->MODELO) as $m) {
                $ids = array_merge($ids, EquipoAuxiliar::where('MARCA', $m->MARCA)->where('MODELO', $m->MODELO)->pluck('ID_AUXILIAR')->all());
            }
        }
        if (! empty($agregados['frentes'])) {
            $ids = array_merge($ids, EquipoAuxiliar::whereIn('ID_FRENTE_ACTUAL', $agregados['frentes'])->pluck('ID_AUXILIAR')->all());
        }
        if ($equipos) {
            $ids = array_merge($ids, EquipoAuxiliar::whereIn('ID_EQUIPO_HOST', $equipos)->pluck('ID_AUXILIAR')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function visibles()
    {
        return $this->usuario->aplicarScopeFrentesEquipos(Equipo::query(), 'ID_FRENTE_ACTUAL');
    }

    private function auxVisibles()
    {
        return $this->usuario->aplicarScopeFrentesEquipos(EquipoAuxiliar::query(), 'ID_FRENTE_ACTUAL');
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
        $doc   = $e->documentacion;

        return ['id' => (int) $e->ID_EQUIPO] + $e->toDetailsPayload() + [
            'etiqueta'          => $e->NUMERO_ETIQUETA,
            'idTipo'            => $e->id_tipo_equipo ? (int) $e->id_tipo_equipo : null,
            'idEspec'           => $e->ID_ESPEC ? (int) $e->ID_ESPEC : null,
            'estado'            => $e->ESTADO_OPERATIVO,
            'confirmado'        => (int) ($e->CONFIRMADO_EN_SITIO ?? 0),
            'capacidad'         => $e->CAPACIDAD,
            'color'             => CatalogoColor::normalizar($e->COLOR),
            'colorReal'         => $e->COLOR,
            'placaReal'         => $doc?->PLACA,
            'titularReal'       => $doc?->NOMBRE_DEL_TITULAR,
            'aseguradoraReal'   => $doc?->seguro?->NOMBRE_ASEGURADORA,
            'combustibleReal'   => $e->COMBUSTIBLE,
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

    /** @param  array<int>  $ids */
    private function filasAux(array $ids): array
    {
        if (! $ids) return [];

        // Foto de respaldo por MARCA|MODELO: la del auxiliar más nuevo de ese modelo que
        // tenga una (cualquier frente: es la foto del modelo, no del activo).
        $fotoModelo = null;
        $clave = fn ($a) => mb_strtoupper(trim(($a->MARCA ?? '') . '|' . ($a->MODELO ?? '')));

        $filas = [];
        foreach (array_chunk($ids, 500) as $tanda) {
            $auxs = EquipoAuxiliar::whereIn('ID_AUXILIAR', $tanda)->with(EquipoAuxiliar::conDetalles())->get();

            if ($fotoModelo === null && $auxs->contains(fn ($a) => ! $a->FOTO)) {
                $fotoModelo = [];
                EquipoAuxiliar::whereNotNull('FOTO')->where('FOTO', '!=', '')
                    ->orderByDesc('ID_AUXILIAR')->get(['MARCA', 'MODELO', 'FOTO'])
                    ->each(function ($a) use (&$fotoModelo, $clave) {
                        if ($clave($a) !== '|') $fotoModelo[$clave($a)] ??= $a->FOTO;
                    });
            }

            foreach ($auxs as $a) {
                $foto = $a->FOTO ?: ($fotoModelo[$clave($a)] ?? null);
                $filas[] = [
                    'id'          => (int) $a->ID_AUXILIAR,
                    'tipo'        => $a->TIPO,
                    'marca'       => $a->MARCA,
                    'modelo'      => $a->MODELO,
                    'serial'      => $a->SERIAL,
                    'codigo'      => $a->CODIGO_INTERNO,
                    'capacidad'   => $a->CAPACIDAD,
                    'anio'        => $a->ANIO,
                    'estado'      => $a->ESTADO_OPERATIVO,
                    'idFrente'    => $a->ID_FRENTE_ACTUAL ? (int) $a->ID_FRENTE_ACTUAL : null,
                    'detalle'     => $a->DETALLE_UBICACION_ACTUAL,
                    'confirmado'  => (int) ($a->CONFIRMADO_EN_SITIO ?? 0),
                    'fotoId'      => $foto ? basename((string) parse_url($foto, PHP_URL_PATH)) : null,
                    'propiedad'   => $a->LINK_DOC_PROPIEDAD,
                    'certificado' => $a->LINK_CERTIFICADO,
                    'creado'      => $a->created_at?->format('Y-m-d H:i:s'),
                    'det'         => $a->toDetailsPayload(),
                ];
            }
        }

        return $filas;
    }

    /**
     * Catálogos enteros (pocos KB; el cliente los reemplaza cada vez que llegan):
     *   · frentes: los que el usuario ve, de cualquier estatus (un equipo puede seguir en un
     *     frente FINALIZADO), con lo que la tabla necesita para pintarlos y filtrarlos.
     *   · tipos de equipo y tiposAux (código → rótulo) de los auxiliares que ve.
     *   · alcance: si es LOCAL y sus frentes (el filtro de frente de los auxiliares los usa).
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
                'venta'        => FrenteTrabajo::avisoVentaDe($f->TIPO_FRENTE),
            ])
            ->all();

        $tipos = TipoEquipo::orderBy('nombre')
            ->get(['id', 'nombre', 'ROL_ANCLAJE'])
            ->map(static fn ($t) => ['id' => (int) $t->id, 'nombre' => $t->nombre, 'rol' => $t->ROL_ANCLAJE])
            ->all();

        $tiposAux = $this->auxVisibles()->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->pluck('TIPO')
            ->map(static fn ($t) => ['id' => $t, 'nombre' => EquipoAuxiliar::etiquetaTipo($t)])
            ->sortBy('nombre')->values()->all();

        $alcance = [[
            'id'      => 1,
            'local'   => ! $this->usuario->veTodosLosFrentesEquipos(),
            'frentes' => array_map('intval', $this->usuario->getFrentesIds()),
        ]];

        return ['frentes' => $frentes, 'tipos' => $tipos, 'tiposAux' => $tiposAux, 'alcance' => $alcance];
    }
}
