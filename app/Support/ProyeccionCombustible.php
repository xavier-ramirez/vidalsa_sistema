<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Proyección de consumo de combustible por frente.
 *
 * FUENTE ÚNICA de la regla del chuto. Un chuto solo es un tractocamión: lo que gasta
 * depende de QUÉ arrastra. Con batea o cisterna de vacío rueda cargado todos los días;
 * con lowboy trabaja por tandas, porque mover maquinaria pesada pasa una o dos veces por
 * semana — por eso la proyección original de TUBERÍA 12'' ya los separaba (150 vs 50).
 *
 * No se puede resolver por unidad: el 86% de los chutos no tiene anclaje registrado y el
 * remolque se cambia de un día para otro. Pero SÍ por frente — si hay 5 chutos, 4 bateas
 * y 1 lowboy, se sabe que 4 van con batea y 1 con lowboy, sin importar cuál es cuál.
 *
 * La usan el reporte Excel (generar_excel_consumo_frentes.php) y el "Consumo total" del
 * dashboard de flota. Si esta clase no existiera, los dos números no coincidirían: el
 * Excel descontaría los lowboys y la web no.
 */
class ProyeccionCombustible
{
    /** L/día de un chuto que anda con lowboy. Sale de la proyección de TUBERÍA 12''. */
    public const CHUTO_CON_LOWBOY = 50;

    /** Remolques que ruedan cargados A DIARIO: el chuto que los hala va a tarifa plena. */
    public const REMOLQUES_DIARIOS = ['BATEA', 'BATEA/SILOS', 'BATEA/VOLQUETA', 'VACUUM'];

    /** Remolques de uso EPISÓDICO: el chuto que los hala no trabaja todos los días. */
    public const REMOLQUES_LOWBOY = ['LOWBOY', 'CAMA BAJA'];

    /**
     * Reparte los chutos de un frente entre los remolques que hay allí.
     *
     * Primero se cubren los remolques de uso diario —son el trabajo de todos los días— y
     * solo los chutos que sobran quedan para el lowboy. Por eso con 1 chuto + 1 batea +
     * 1 lowboy el chuto cuenta como batea: no puede halar los dos y la batea manda.
     * Los chutos que quedan sin remolque cuentan a tarifa plena: siguen operando.
     *
     * @return array{diario:int, lowboy:int, sueltos:int}
     */
    public static function repartirChutos(int $chutos, int $remolquesDiarios, int $remolquesLowboy): array
    {
        $diario = min($chutos, $remolquesDiarios);
        $lowboy = min($chutos - $diario, $remolquesLowboy);

        return [
            'diario'  => $diario,
            'lowboy'  => $lowboy,
            'sueltos' => $chutos - $diario - $lowboy,
        ];
    }

    /**
     * Cuántos chutos y remolques hay en un frente.
     *
     * @return array{chutos:int, diarios:int, lowboys:int}
     */
    public static function conteoFrente(?int $idFrente): array
    {
        return self::conteosPorFrente([$idFrente])[self::claveFrente($idFrente)];
    }

    /** Consumo diario del chuto en ese frente (el mayor si hubiera varios valores). */
    public static function consumoBaseChuto(?int $idFrente): float
    {
        return self::consumosBaseChuto([$idFrente])[self::claveFrente($idFrente)];
    }

    /**
     * Chutos y remolques de VARIOS frentes en UNA consulta agrupada, [clave del frente => conteo].
     * El "Consumo total" del dashboard de flota pregunta por todos los frentes a la vez: antes
     * eran tres consultas por frente (chutos, remolques diarios, lowboys) cada vez que se abría.
     *
     * @param  array<int|null>  $idsFrentes
     * @return array<string, array{chutos:int, diarios:int, lowboys:int}>
     */
    private static function conteosPorFrente(array $idsFrentes): array
    {
        $grupo = ['CHUTO' => 'chutos']
            + array_fill_keys(self::REMOLQUES_DIARIOS, 'diarios')
            + array_fill_keys(self::REMOLQUES_LOWBOY, 'lowboys');

        $conteos = [];
        foreach ($idsFrentes as $idFrente) {
            $conteos[self::claveFrente($idFrente)] = ['chutos' => 0, 'diarios' => 0, 'lowboys' => 0];
        }

        $filas = self::equiposDeFrentes($idsFrentes)
            ->whereIn('t.nombre', array_keys($grupo))
            ->groupBy('e.ID_FRENTE_ACTUAL', 't.nombre')
            ->get(['e.ID_FRENTE_ACTUAL as frente', 't.nombre as tipo', DB::raw('COUNT(*) as n')]);

        foreach ($filas as $fila) {
            // El whereIn de MySQL no distingue mayúsculas: el nombre se busca igual aquí.
            $campo = $grupo[mb_strtoupper(trim((string) $fila->tipo))] ?? null;
            $clave = self::claveFrente($fila->frente === null ? null : (int) $fila->frente);
            if ($campo !== null && isset($conteos[$clave])) {
                $conteos[$clave][$campo] += (int) $fila->n;
            }
        }

        return $conteos;
    }

    /**
     * Consumo base del chuto (el mayor CONSUMO_PROMEDIO) de VARIOS frentes en una consulta.
     *
     * @param  array<int|null>  $idsFrentes
     * @return array<string, float>
     */
    private static function consumosBaseChuto(array $idsFrentes): array
    {
        $bases = [];
        foreach ($idsFrentes as $idFrente) {
            $bases[self::claveFrente($idFrente)] = 0.0;
        }

        $filas = self::equiposDeFrentes($idsFrentes)
            ->where('t.nombre', 'CHUTO')
            ->whereNotNull('e.CONSUMO_PROMEDIO')
            ->groupBy('e.ID_FRENTE_ACTUAL')
            ->get(['e.ID_FRENTE_ACTUAL as frente', DB::raw('MAX(e.CONSUMO_PROMEDIO) as base')]);

        foreach ($filas as $fila) {
            $bases[self::claveFrente($fila->frente === null ? null : (int) $fila->frente)] = (float) $fila->base;
        }

        return $bases;
    }

    /** Equipos vivos, con su tipo, de esos frentes; un null en la lista = los que no tienen frente. */
    private static function equiposDeFrentes(array $idsFrentes)
    {
        $ids       = array_values(array_filter($idsFrentes, fn ($f) => $f !== null));
        $sinFrente = in_array(null, $idsFrentes, true);

        return DB::table('equipos as e')
            ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')
            ->where(function ($q) use ($ids, $sinFrente) {
                if ($ids) {
                    $q->whereIn('e.ID_FRENTE_ACTUAL', $ids);
                }
                if ($sinFrente) {
                    $q->orWhereNull('e.ID_FRENTE_ACTUAL');
                }
                if (!$ids && !$sinFrente) {
                    $q->whereRaw('1 = 0');
                }
            });
    }

    private static function claveFrente(?int $idFrente): string
    {
        return $idFrente === null ? '' : (string) $idFrente;
    }

    /**
     * Litros/día que hay que RESTAR de una suma plana de CONSUMO_PROMEDIO por los chutos
     * que en realidad andan con lowboy.
     *
     * Se expresa como descuento —y no como recálculo completo— para poder aplicarse
     * encima de cualquier suma ya scopeada (permisos, frentes bloqueados, exclusión de
     * frentes ESPECIAL) sin duplicar toda esa lógica de alcance.
     *
     * Son dos consultas para todos los frentes juntos (conteosPorFrente y, solo para los que
     * tienen chutos con lowboy, consumosBaseChuto), no cuatro por frente.
     *
     * @param  array<int|null>  $idsFrentes  Frentes dentro del alcance. null = sin frente.
     */
    public static function descuentoLowboy(array $idsFrentes): float
    {
        $ids = array_map(fn ($f) => $f === null ? null : (int) $f, array_values(array_unique($idsFrentes, SORT_REGULAR)));
        if (!$ids) {
            return 0.0;
        }

        $conteos = self::conteosPorFrente($ids);
        $lowboys = [];   // [clave del frente => chutos que andan con lowboy]
        foreach ($ids as $idFrente) {
            $c = $conteos[self::claveFrente($idFrente)];
            if ($c['chutos'] === 0 || $c['lowboys'] === 0) continue;

            $reparto = self::repartirChutos($c['chutos'], $c['diarios'], $c['lowboys']);
            if ($reparto['lowboy'] === 0) continue;

            $lowboys[self::claveFrente($idFrente)] = $reparto['lowboy'];
        }
        if (!$lowboys) {
            return 0.0;
        }

        $bases = self::consumosBaseChuto(array_values(array_filter($ids, fn ($f) => isset($lowboys[self::claveFrente($f)]))));
        $descuento = 0.0;
        foreach ($lowboys as $clave => $n) {
            $descuento += $n * max(0.0, $bases[$clave] - self::CHUTO_CON_LOWBOY);
        }

        return $descuento;
    }

    /**
     * Desglosa la fila "CHUTO" de un frente en las filas que van al reporte.
     *
     * @return array<int, array{0:string, 1:int, 2:float}>  [etiqueta, unidades, L/día c/u]
     */
    public static function filasChuto(?int $idFrente, int $chutos, float $consumoBase): array
    {
        $c = self::conteoFrente($idFrente);
        $reparto = self::repartirChutos($chutos, $c['diarios'], $c['lowboys']);

        $filas = [];
        if ($reparto['diario'])  $filas[] = ['CHUTO CON BATEA / VACUUM', $reparto['diario'],  $consumoBase];
        if ($reparto['lowboy'])  $filas[] = ['CHUTO CON LOWBOY',         $reparto['lowboy'],  (float) self::CHUTO_CON_LOWBOY];
        if ($reparto['sueltos']) $filas[] = ['CHUTO SIN REMOLQUE',       $reparto['sueltos'], $consumoBase];

        return $filas;
    }
}
