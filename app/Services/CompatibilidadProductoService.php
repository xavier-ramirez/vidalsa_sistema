<?php

namespace App\Services;

use App\Models\AuxiliarFiltro;
use App\Models\ModeloFiltro;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Compatibilidad de un producto: sus números de parte (equivalencias) y los equipos que lo
 * usan —modelos del catálogo (modelo_filtro) y auxiliares (auxiliar_filtro)—. Punto único
 * para leerla (ficha "Detalles del producto") y para cambiarla (botones + / × de esa ficha y
 * la lista de equivalencias de "Editar producto").
 *
 * Dos números de parte son el mismo sin importar mayúsculas ni espacios ("b 00714" =
 * "B00714"): la misma regla de la lista de "Editar producto" (almProdEquivAdd), y la base
 * tampoco distingue mayúsculas (índice único uk_prod_numparte con collation _ci).
 */
class CompatibilidadProductoService
{
    /** Números de parte, el principal primero. */
    public function equivalencias(int $idProducto): array
    {
        return ProductoEquivalencia::where('ID_PRODUCTO', $idProducto)
            ->orderByDesc('ES_PRINCIPAL')->orderBy('ID_EQUIVALENCIA')
            ->pluck('NUMERO_PARTE')->values()->all();
    }

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

    /** Agrega un número de parte (el botón + de la ficha). */
    public function agregarEquivalencia(ProductoInventario $producto, string $numero): void
    {
        $numero = trim($numero);
        if ($numero === '') {
            throw new InvalidArgumentException('Escribe el número de parte.');
        }
        $ya = collect($this->equivalencias($producto->ID_PRODUCTO))->first(fn ($e) => $this->clave($e) === $this->clave($numero));
        if ($ya !== null) {
            throw new InvalidArgumentException("El número de parte {$ya} ya está en este producto.");
        }
        ProductoEquivalencia::create(['ID_PRODUCTO' => $producto->ID_PRODUCTO, 'NUMERO_PARTE' => $numero, 'ES_PRINCIPAL' => false]);
        $this->asegurarPrincipal($producto);
    }

    /** Quita un número de parte (la × de la ficha). */
    public function quitarEquivalencia(ProductoInventario $producto, string $numero): void
    {
        $producto->equivalencias()->where('NUMERO_PARTE', trim($numero))->delete();
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
     * Equipos que usan el producto: modelos del catálogo y auxiliares, con tipo, marca y
     * modelo, la etapa y la cantidad por servicio. La MARCA de un modelo del catálogo no vive
     * en caracteristicas_modelo: se toma de los equipos de ese modelo (la más común).
     *
     * El catálogo tiene varias fichas con el mismo tipo, marca y modelo (una por año): se ven
     * como UNA fila y `ids` son todos sus vínculos, para que la × los quite juntos. Antes se
     * mostraba uno solo y, al quitarlo, reaparecía el otro como si la × no hubiera hecho nada.
     */
    public function equipos(int $idProducto): Collection
    {
        $vinculos = DB::table('modelo_filtro as mf')
            ->join('caracteristicas_modelo as cm', 'cm.ID_ESPEC', '=', 'mf.ID_ESPEC')
            ->where('mf.ID_PRODUCTO', $idProducto)
            ->orderBy('cm.TIPO')->orderBy('cm.MODELO')
            ->get(['mf.ID_MODELO_FILTRO as id', 'mf.ID_ESPEC as espec', 'cm.TIPO', 'cm.MODELO', 'mf.ETAPA', 'mf.CANTIDAD']);
        $marcas = $this->marcaPorModelo($vinculos->pluck('espec'));

        $modelos = $vinculos->map(fn ($x) => [
            'origen' => 'modelo',
            'id'     => (int) $x->id,
            'tipo'   => (string) $x->TIPO,
            'modelo' => $this->nombreModelo($marcas->get($x->espec), $x->MODELO),
            'etapa'  => $x->ETAPA,
            'cant'   => (int) $x->CANTIDAD,
        ]);

        $aux = AuxiliarFiltro::where('ID_PRODUCTO', $idProducto)
            ->orderBy('TIPO')->orderBy('MARCA')->orderBy('MODELO')
            ->get()
            ->map(fn ($x) => [
                'origen' => 'aux',
                'id'     => (int) $x->ID_AUX_FILTRO,
                'tipo'   => str_replace('_', ' ', (string) $x->TIPO),
                'modelo' => $this->nombreModelo($x->MARCA, $x->MODELO),
                'etapa'  => $x->ETAPA,
                'cant'   => (int) $x->CANTIDAD,
            ]);

        return $modelos->concat($aux)
            ->groupBy(fn ($x) => $this->claveEquipo($x))
            ->map(function ($g) {
                $etapa = $g->pluck('etapa')->filter()->first();
                return [
                    'origen' => $g->first()['origen'],
                    'ids'    => $g->pluck('id')->all(),
                    'tipo'   => $g->first()['tipo'],
                    'modelo' => $g->first()['modelo'],
                    'etapa'  => $etapa ? ucfirst(mb_strtolower((string) $etapa)) : null,
                    'cant'   => (int) $g->max('cant'),
                ];
            })
            ->values();
    }

    /**
     * Equipos que se pueden vincular: los modelos del catálogo y los tipos de auxiliar que
     * existen, filtrados por el texto (o por la placa de un equipo de ese modelo, que viaja en
     * `placas`) y sin los que el producto ya tiene. Agrupados igual que
     * equipos(): `refs` es lo que se manda al vincular (los ID_ESPEC de las fichas del modelo o
     * "TIPO|MARCA|MODELO" del auxiliar), y se vinculan todas.
     */
    public function opcionesEquipo(int $idProducto, string $texto): Collection
    {
        $texto = mb_strtoupper(trim($texto));
        $ya = $this->equipos($idProducto)->map(fn ($e) => $this->claveEquipo($e))->all();

        $especs = DB::table('caracteristicas_modelo')->orderBy('TIPO')->orderBy('MODELO')->get(['ID_ESPEC', 'TIPO', 'MODELO']);
        $marcas = $this->marcaPorModelo($especs->pluck('ID_ESPEC'));
        $modelos = $especs->map(fn ($m) => [
            'origen' => 'modelo',
            'ref'    => (string) $m->ID_ESPEC,
            'tipo'   => (string) $m->TIPO,
            'modelo' => $this->nombreModelo($marcas->get($m->ID_ESPEC), $m->MODELO),
            'base'   => (string) $m->MODELO,   // el MODELO de la ficha, sin marca: con él se reconoce un equipo sin ficha
        ]);

        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->select('TIPO', 'MARCA', 'MODELO')->distinct()->orderBy('TIPO')->orderBy('MARCA')->orderBy('MODELO')->get()
            ->map(fn ($a) => [
                'origen' => 'aux',
                'ref'    => $a->TIPO.'|'.$a->MARCA.'|'.$a->MODELO,
                'tipo'   => str_replace('_', ' ', (string) $a->TIPO),
                'modelo' => $this->nombreModelo($a->MARCA, $a->MODELO),
            ]);

        $placas = $this->equiposPorPlaca($texto);

        return $modelos->concat($aux)
            ->groupBy(fn ($o) => $this->claveEquipo($o))
            ->reject(fn ($g, $clave) => in_array($clave, $ya, true))
            ->map(function ($g) use ($placas) {
                $o = $g->first();
                $refs = $g->pluck('ref')->all();
                // El modelo de los equipos cuya placa se escribió: por su ficha del catálogo o, si
                // el equipo aún no la tiene, por su tipo y modelo iguales a los de la ficha (el
                // tipo cuenta: hay modelos repetidos en tipos distintos; la marca no, la ficha no la tiene).
                $bases = $g->pluck('base')->filter()->map(fn ($b) => mb_strtoupper(trim($b)))->all();
                $susPlacas = $o['origen'] !== 'modelo' ? [] : $placas
                    ->filter(fn ($e) => $e->ID_ESPEC !== null
                        ? in_array((string) $e->ID_ESPEC, $refs, true)
                        : mb_strtoupper(trim((string) $e->TIPO)) === mb_strtoupper($o['tipo'])
                            && in_array(mb_strtoupper(trim((string) $e->MODELO)), $bases, true))
                    ->pluck('PLACA')->unique()->values()->all();
                return [
                    'origen' => $o['origen'],
                    'refs'   => $refs,
                    'tipo'   => $o['tipo'],
                    'modelo' => $o['modelo'],
                    'placas' => $susPlacas,
                ];
            })
            ->filter(fn ($o) => $texto === '' || $o['placas'] || str_contains(mb_strtoupper($o['tipo'].' '.$o['modelo']), $texto))
            ->sortByDesc(fn ($o) => (bool) $o['placas'])   // lo hallado por placa, primero
            ->take(100)->values();   // son pocos (el catálogo y los tipos de auxiliar): caben todos
    }

    /**
     * Equipos (no borrados) cuya placa contiene lo escrito, sin contar espacios ni guiones
     * ("A85-DR1K" = "A85DR1K"). Desde 5 letras o números: con menos, lo que se escribe buscando
     * un modelo ("320", "D6T") coincidiría con placas de otros equipos y los colaría arriba.
     */
    private function equiposPorPlaca(string $texto): Collection
    {
        $limpio = preg_replace('/[^A-Z0-9]/u', '', $texto);
        if (mb_strlen($limpio) < 5) {
            return collect();
        }
        return DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->leftJoin('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')
            ->whereRaw("REPLACE(REPLACE(UPPER(d.PLACA), ' ', ''), '-', '') LIKE ?", ['%'.$limpio.'%'])
            ->get(['d.PLACA', 'e.ID_ESPEC', 't.nombre as TIPO', 'e.MODELO']);
    }

    /** Vincula el producto a las fichas de un modelo del catálogo o a un tipo de auxiliar. */
    public function vincularEquipo(ProductoInventario $producto, string $origen, array $refs): void
    {
        foreach ($refs as $ref) {
            if ($origen === 'modelo') {
                if (! DB::table('caracteristicas_modelo')->where('ID_ESPEC', (int) $ref)->exists()) {
                    throw new InvalidArgumentException('Ese modelo de equipo ya no existe.');
                }
                ModeloFiltro::firstOrCreate(['ID_ESPEC' => (int) $ref, 'ID_PRODUCTO' => $producto->ID_PRODUCTO], ['CANTIDAD' => 1]);
            } elseif ($origen === 'aux') {
                [$tipo, $marca, $modelo] = array_pad(explode('|', (string) $ref, 3), 3, '');
                $existe = DB::table('equipos_auxiliares')->whereNull('deleted_at')
                    ->where('TIPO', $tipo)->where('MARCA', $marca)->where('MODELO', $modelo)->exists();
                if (! $existe) {
                    throw new InvalidArgumentException('Ese equipo auxiliar ya no existe.');
                }
                AuxiliarFiltro::firstOrCreate(
                    ['ID_PRODUCTO' => $producto->ID_PRODUCTO, 'TIPO' => $tipo, 'MARCA' => $marca, 'MODELO' => $modelo],
                    ['CANTIDAD' => 1]
                );
            } else {
                throw new InvalidArgumentException('Tipo de equipo desconocido.');
            }
        }
    }

    /** Quita vínculos (la × de la ficha, con todos los `ids` de la fila). Solo del producto indicado. */
    public function desvincularEquipo(ProductoInventario $producto, string $origen, array $ids): void
    {
        $query = match ($origen) {
            'modelo' => ModeloFiltro::whereIn('ID_MODELO_FILTRO', $ids),
            'aux'    => AuxiliarFiltro::whereIn('ID_AUX_FILTRO', $ids),
            default  => throw new InvalidArgumentException('Tipo de equipo desconocido.'),
        };
        $query->where('ID_PRODUCTO', $producto->ID_PRODUCTO)->delete();
    }

    /** Lo que identifica una fila de equipos: origen, tipo y nombre visible. */
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
