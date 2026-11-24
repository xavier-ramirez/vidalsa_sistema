<?php

namespace App\Services;

use App\Models\AuxiliarFiltro;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Compatibilidad de un producto: sus números de parte (equivalencias, que se guardan desde la
 * lista de "Editar producto") y los modelos de equipo que lo usan —catálogo (modelo_filtro) y
 * auxiliares (auxiliar_filtro)—, que leen los kits del almacén (KitAlmacenService).
 *
 * Dos números de parte son el mismo sin importar mayúsculas ni espacios ("b 00714" =
 * "B00714"): la misma regla de la lista de "Editar producto" (almProdEquivAdd), y la base
 * tampoco distingue mayúsculas (índice único uk_prod_numparte con collation _ci).
 */
class CompatibilidadProductoService
{
    /**
     * Deja el producto con EXACTAMENTE esta lista de números de parte: agrega los nuevos,
     * borra los quitados y conserva el principal de los que se quedan. Normaliza (trim, sin
     * vacíos, sin repetir). Si uno se quedó pero escrito distinto ("b 00714" → "B00714"), se
     * guarda como viene: así se corrige cómo está escrito sin perder si era el principal.
     */
    public function sincronizarEquivalencias(ProductoInventario $producto, array $lista): void
    {
        $partes = collect($lista)
            ->map(fn ($s) => trim((string) $s))
            ->filter(fn ($s) => $s !== '' && mb_strlen($s) <= 100)
            ->unique(fn ($s) => $this->clave($s))
            ->keyBy(fn ($s) => $this->clave($s));

        $actuales = $producto->equivalencias()->get()->keyBy(fn ($e) => $this->clave($e->NUMERO_PARTE));
        foreach ($actuales as $clave => $eq) {
            if (! $partes->has($clave)) {
                $eq->delete();
            }
        }
        foreach ($partes as $clave => $np) {
            $eq = $actuales->get($clave);
            if (! $eq) {
                ProductoEquivalencia::create(['ID_PRODUCTO' => $producto->ID_PRODUCTO, 'NUMERO_PARTE' => $np, 'ES_PRINCIPAL' => false]);
            } elseif ($eq->NUMERO_PARTE !== $np) {
                $eq->update(['NUMERO_PARTE' => $np]);
            }
        }
        $this->asegurarPrincipal($producto);
    }

    /** Lo que hace iguales a dos números de parte (ver el encabezado de la clase). */
    private function clave(string $numero): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $numero));
    }

    /**
     * Si el producto se quedó sin principal (se borró justo ese, o es el primero que se
     * agrega), se asciende el más antiguo: el nº de parte que sugieren el buscador y la Nota
     * de Entrega (ordenan por ES_PRINCIPAL) no puede quedar al azar.
     */
    private function asegurarPrincipal(ProductoInventario $producto): void
    {
        $quedan = $producto->equivalencias()->orderBy('ID_EQUIVALENCIA')->get();
        if ($quedan->isNotEmpty() && ! $quedan->contains('ES_PRINCIPAL', true)) {
            $quedan->first()->update(['ES_PRINCIPAL' => true]);
        }
    }

    /**
     * TODOS los modelos de equipo que existen, agrupados como los ve el usuario: las fichas
     * del catálogo con el mismo tipo, marca y modelo (una por año) son UN modelo, y cada tipo
     * de auxiliar es otro. Cada grupo trae:
     *   origen  'modelo' (catálogo) o 'aux'
     *   clave   lo que lo identifica (claveEquipo)
     *   refs    los ID_ESPEC de sus fichas, o "TIPO|MARCA|MODELO" del auxiliar
     *   tipo, modelo  lo que se muestra ("MARCA MODELO")
     *   bases   el MODELO de cada ficha, sin marca: con él se reconoce un equipo sin ficha
     * Fuente ÚNICA de los kits del almacén (KitAlmacenService), para que un kit y sus
     * productos hablen del mismo modelo.
     */
    public function modelosAgrupados(): Collection
    {
        $especs = DB::table('caracteristicas_modelo')->orderBy('TIPO')->orderBy('MODELO')->get(['ID_ESPEC', 'TIPO', 'MODELO']);
        $marcas = $this->marcaPorModelo($especs->pluck('ID_ESPEC'));
        $modelos = $especs->map(fn ($m) => [
            'origen' => 'modelo',
            'ref'    => (string) $m->ID_ESPEC,
            'tipo'   => (string) $m->TIPO,
            'modelo' => $this->nombreModelo($marcas->get($m->ID_ESPEC), $m->MODELO),
            'base'   => (string) $m->MODELO,
        ]);

        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->select('TIPO', 'MARCA', 'MODELO')->distinct()->orderBy('TIPO')->orderBy('MARCA')->orderBy('MODELO')->get()
            ->map(fn ($a) => [
                'origen' => 'aux',
                'ref'    => $a->TIPO.'|'.$a->MARCA.'|'.$a->MODELO,
                'tipo'   => str_replace('_', ' ', (string) $a->TIPO),
                'modelo' => $this->nombreModelo($a->MARCA, $a->MODELO),
            ]);

        return $modelos->concat($aux)
            ->groupBy(fn ($o) => $this->claveEquipo($o))
            ->map(fn ($g, $clave) => [
                'origen' => $g->first()['origen'],
                'clave'  => (string) $clave,
                'refs'   => $g->pluck('ref')->all(),
                'tipo'   => $g->first()['tipo'],
                'modelo' => $g->first()['modelo'],
                'bases'  => $g->pluck('base')->filter()->map(fn ($b) => mb_strtoupper(trim($b)))->values()->all(),
            ])
            ->values();
    }

    /**
     * Placas de los equipos de cada uno de estos modelos (grupos de modelosAgrupados), como
     * [clave => [placas]]. Recorre todos los equipos con placa una sola vez; los modelos sin
     * equipos no aparecen. Lo usan los kits para encontrar el de un equipo escribiendo su placa.
     */
    public function placasPorModelo(Collection $grupos): array
    {
        $grupos = $grupos->where('origen', 'modelo');
        if ($grupos->isEmpty()) {
            return [];
        }
        $equipos = $this->equiposConPlaca()->whereNotNull('d.PLACA')->where('d.PLACA', '<>', '')->get();

        $salida = [];
        foreach ($grupos as $g) {
            $placas = $this->placasDe($g, $equipos);
            if ($placas) {
                $salida[$g['clave']] = $placas;
            }
        }
        return $salida;
    }

    /**
     * Productos ligados a un modelo (las refs de un grupo de modelosAgrupados), con la cantidad
     * por servicio de su vínculo: lo que "lleva" ese equipo. Es la compatibilidad vista desde
     * el equipo — el editor de kits la sugiere primero.
     */
    public function productosDeModelo(string $origen, array $refs): Collection
    {
        if ($origen === 'modelo') {
            $filas = DB::table('modelo_filtro')->whereIn('ID_ESPEC', array_map('intval', $refs))
                ->get(['ID_PRODUCTO', 'CANTIDAD']);
        } elseif ($origen === 'aux') {
            $filas = collect();
            foreach ($refs as $ref) {
                [$tipo, $marca, $modelo] = array_pad(explode('|', (string) $ref, 3), 3, '');
                $filas = $filas->concat(AuxiliarFiltro::where('TIPO', $tipo)->where('MARCA', $marca)->where('MODELO', $modelo)
                    ->get(['ID_PRODUCTO', 'CANTIDAD']));
            }
        } else {
            throw new InvalidArgumentException('Tipo de equipo desconocido.');
        }

        return $filas->groupBy('ID_PRODUCTO')->map(fn ($g, $id) => [
            'id_producto' => (int) $id,
            'cantidad'    => max(1, (int) $g->max('CANTIDAD')),
        ])->values();
    }

    /**
     * Placas de los equipos de un modelo, entre los equipos dados: por su ficha del catálogo o,
     * si el equipo aún no la tiene, por su tipo y modelo iguales a los de la ficha (el tipo
     * cuenta: hay modelos repetidos en tipos distintos; la marca no, la ficha no la tiene).
     * Los auxiliares no se buscan por placa.
     */
    private function placasDe(array $grupo, Collection $equipos): array
    {
        if ($grupo['origen'] !== 'modelo' || $equipos->isEmpty()) {
            return [];
        }
        $tipo = mb_strtoupper($grupo['tipo']);
        return $equipos
            ->filter(fn ($e) => $e->ID_ESPEC !== null
                ? in_array((string) $e->ID_ESPEC, $grupo['refs'], true)
                : mb_strtoupper(trim((string) $e->TIPO)) === $tipo
                    && in_array(mb_strtoupper(trim((string) $e->MODELO)), $grupo['bases'], true))
            ->pluck('PLACA')->unique()->values()->all();
    }

    /**
     * Equipos (no borrados) con su placa, su ficha del catálogo y su tipo: lo que necesita
     * placasDe() para reconocer de qué modelo es cada uno. Base de placasPorModelo.
     */
    private function equiposConPlaca(): \Illuminate\Database\Query\Builder
    {
        return DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->leftJoin('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')
            ->select(['d.PLACA', 'e.ID_ESPEC', 't.nombre as TIPO', 'e.MODELO']);
    }

    /** Lo que identifica un modelo agrupado: origen, tipo y nombre visible. */
    private function claveEquipo(array $e): string
    {
        return $e['origen'].'|'.$e['tipo'].'|'.$e['modelo'];
    }

    /** "MARCA MODELO"; la marca "-" de los auxiliares (sin marca en el registro) no se muestra. */
    private function nombreModelo(?string $marca, ?string $modelo): string
    {
        $marca = trim((string) $marca);
        return trim(($marca === '-' ? '' : $marca).' '.trim((string) $modelo));
    }

    /** Marca más común de los equipos de cada modelo del catálogo, como [ID_ESPEC => MARCA]. */
    private function marcaPorModelo(Collection $especs): Collection
    {
        if ($especs->isEmpty()) {
            return collect();
        }
        return DB::table('equipos')
            ->whereIn('ID_ESPEC', $especs->all())->whereNotNull('ID_ESPEC')->whereNull('deleted_at')
            ->select('ID_ESPEC', 'MARCA', DB::raw('COUNT(*) as n'))
            ->groupBy('ID_ESPEC', 'MARCA')->orderByDesc('n')
            ->get()->groupBy('ID_ESPEC')->map(fn ($g) => $g->first()->MARCA);
    }
}
