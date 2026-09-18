<?php

use App\Models\EquipoAuditLog;
use App\Models\VerificacionDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 18-09-2026, al montar la regla del "PDF anterior"
 * (VerificacionDocumento::documentoAnterior).
 *
 * 1. DESHACE lo que la verificacion automatica puso en una ficha cuando el PDF enlazado era el
 *    VIEJO: la ficha ya tenia el vencimiento renovado y la tarea, con "manda el documento", le
 *    escribio encima la fecha anterior (fichas que pasaron a salir como vencidas). Se vuelve a
 *    lo que habia, dato a dato, solo si la ficha sigue con lo que puso la tarea (si alguien la
 *    toco despues, manda lo suyo). Cada ficha deja su apunte en el historial.
 * 2. Vuelve a LEER todos los ROTC y RACDA: se retiran sus lecturas y la tarea de la mañana
 *    (9:05-13:05, cuatro lectores) los toma como pendientes, ya con el lector corregido
 *    (la fila de la tabla de flota, el PDF anterior). Lo pidio el cliente.
 *
 * Una sola vez: es una migracion. No hay vuelta atras (down vacio): lo deshecho queda en el
 * historial de cada equipo y las lecturas se rehacen solas.
 */
return new class extends Migration
{
    private const ORIGEN = 'Verificación de documentos (automática)';

    /** Vencimiento de cada documento => su fecha de emision (van juntos en el mismo apunte). */
    private const VENCE = [
        'FECHA_VENC_POLIZA' => 'FECHA_EMISION_POLIZA',
        'FECHA_ROTC'        => 'FECHA_EMISION_ROTC',
        'FECHA_RACDA'       => 'FECHA_EMISION_RACDA',
    ];

    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        $this->deshacerVencimientosViejos();

        DB::table('verificacion_documento_registro')
            ->whereIn('TIPO', [VerificacionDocumento::ROTC, VerificacionDocumento::RACDA])
            ->delete();
    }

    public function down(): void
    {
    }

    private function deshacerVencimientosViejos(): void
    {
        $aseguradoras = DB::table('catalogo_seguros')->pluck('ID_SEGURO', 'NOMBRE_ASEGURADORA');
        $vistos = [];

        // Del mas nuevo al mas viejo: por cada ficha y documento cuenta el ULTIMO apunte.
        // CAMBIOS es TEXT (no JSON): se filtra con LIKE y se confirma el origen ya leido, porque
        // un JSON_EXTRACT sobre una fila vieja mal escrita tumbaria la consulta entera.
        $apuntes = EquipoAuditLog::whereNotNull('ID_EQUIPO')
            ->where('CAMBIOS', 'like', '%documentos (autom%')
            ->orderByDesc('ID_LOG')
            ->get();

        foreach ($apuntes as $apunte) {
            $cambios = $apunte->CAMBIOS;
            if (is_string($cambios)) $cambios = json_decode($cambios, true);
            if (!is_array($cambios) || ($cambios['_origen'] ?? null) !== self::ORIGEN) continue;
            foreach (self::VENCE as $vence => $emision) {
                $c = $cambios[$vence] ?? null;
                $clave = $apunte->ID_EQUIPO . '|' . $vence;
                if (!$c || isset($vistos[$clave])) continue;
                $vistos[$clave] = true;

                // Solo si RETROCEDIO: antes habia una fecha mas nueva que la que puso la tarea.
                if (!VerificacionDocumento::documentoAnterior($this->dia($c['antes'] ?? null), $this->dia($c['despues'] ?? null))) continue;

                $this->devolver($apunte->ID_EQUIPO, $vence, $cambios, $emision, $aseguradoras);
            }
        }
    }

    /** Devuelve a la ficha lo que tenia antes de ese apunte (vencimiento, emision, aseguradora). */
    private function devolver(int $equipo, string $vence, array $cambios, string $emision, $aseguradoras): void
    {
        $ficha = DB::table('documentacion')->where('ID_EQUIPO', $equipo)->first();
        if (!$ficha) return;

        $poner = $apunte = [];
        foreach ([$vence, $emision, 'ID_SEGURO'] as $campo) {
            if (!isset($cambios[$campo]) || ($campo === 'ID_SEGURO' && $vence !== 'FECHA_VENC_POLIZA')) continue;
            $c = $cambios[$campo];
            if ($campo === 'ID_SEGURO') {
                // El historial guarda el NOMBRE de la aseguradora; la ficha, su ID.
                $puso = $aseguradoras->get((string) ($c['despues'] ?? ''));
                $habia = $aseguradoras->get((string) ($c['antes'] ?? ''));
                if ($puso === null || $habia === null || (int) $ficha->ID_SEGURO !== (int) $puso) continue;
                $poner[$campo] = $habia;
            } else {
                // Si alguien la toco despues de la tarea, manda lo suyo.
                if ($this->dia($ficha->{$campo}) !== $this->dia($c['despues'] ?? null)) continue;
                $poner[$campo] = $this->dia($c['antes'] ?? null);
            }
            $apunte[$campo] = ['antes' => $c['despues'] ?? null, 'despues' => $c['antes'] ?? null];
        }
        if (!isset($poner[$vence])) return;

        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update($poner);
        EquipoAuditLog::registrar($equipo, 'edit', $apunte
            + ['_origen' => 'Deshecho el 18-09-2026: la verificación había puesto la fecha de un PDF anterior']);
    }

    /** '2027-07-03 00:00:00' -> '2027-07-03'; vacio -> null. */
    private function dia($v): ?string
    {
        return $v ? substr((string) $v, 0, 10) : null;
    }
};
