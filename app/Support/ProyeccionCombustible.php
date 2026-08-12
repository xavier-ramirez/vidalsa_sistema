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
        $cuenta = function (array $tipos) use ($idFrente) {
            $q = DB::table('equipos as e')
                ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
                ->whereNull('e.deleted_at')
                ->whereIn('t.nombre', $tipos);

            $idFrente === null
                ? $q->whereNull('e.ID_FRENTE_ACTUAL')
                : $q->where('e.ID_FRENTE_ACTUAL', $idFrente);

            return (int) $q->count();
        };

        return [
            'chutos'  => $cuenta(['CHUTO']),
            'diarios' => $cuenta(self::REMOLQUES_DIARIOS),
            'lowboys' => $cuenta(self::REMOLQUES_LOWBOY),
        ];
    }

    /** Consumo diario del chuto en ese frente (el mayor si hubiera varios valores). */
    public static function consumoBaseChuto(?int $idFrente): float
    {
        $q = DB::table('equipos as e')
            ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')->where('t.nombre', 'CHUTO')
            ->whereNotNull('e.CONSUMO_PROMEDIO');

        $idFrente === null
            ? $q->whereNull('e.ID_FRENTE_ACTUAL')
            : $q->where('e.ID_FRENTE_ACTUAL', $idFrente);

        return (float) ($q->max('e.CONSUMO_PROMEDIO') ?? 0);
    }

    /**
     * Litros/día que hay que RESTAR de una suma plana de CONSUMO_PROMEDIO por los chutos
     * que en realidad andan con lowboy.
     *
     * Se expresa como descuento —y no como recálculo completo— para poder aplicarse
     * encima de cualquier suma ya scopeada (permisos, frentes bloqueados, exclusión de
     * frentes ESPECIAL) sin duplicar toda esa lógica de alcance.
     *
     * @param  array<int|null>  $idsFrentes  Frentes dentro del alcance. null = sin frente.
     */
    public static function descuentoLowboy(array $idsFrentes): float
    {
        $descuento = 0.0;

        foreach (array_unique($idsFrentes, SORT_REGULAR) as $idFrente) {
            $idFrente = $idFrente === null ? null : (int) $idFrente;
            $c = self::conteoFrente($idFrente);
            if ($c['chutos'] === 0 || $c['lowboys'] === 0) continue;

            $reparto = self::repartirChutos($c['chutos'], $c['diarios'], $c['lowboys']);
            if ($reparto['lowboy'] === 0) continue;

            $base = self::consumoBaseChuto($idFrente);
            $descuento += $reparto['lowboy'] * max(0.0, $base - self::CHUTO_CON_LOWBOY);
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
