<?php

use App\Models\EquipoAuditLog;
use App\Models\VerificacionDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las pólizas enlazadas por la carga masiva antes del 28-10-2026 se quedaron con la aseguradora
 * de la póliza VIEJA: aplicar() ponía el PDF y sus fechas, pero no la aseguradora que se leyó del
 * membrete (ya la pone, CargaMasivaDocumentos::aseguradoraDelPdf). En el servidor, ~72 pólizas
 * subidas el 28-10.
 *
 * Solo toca la ficha que SIGUE teniendo ese mismo PDF y cuya aseguradora es otra (o ninguna): si
 * alguien la cambió después, el PDF ya no es el suyo y no se pisa. Cada cambio queda en el
 * historial del equipo con el nombre de antes y el de ahora. No borra ni mueve nada de Drive.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogo = DB::table('catalogo_seguros')->pluck('ID_SEGURO', 'NOMBRE_ASEGURADORA')->all();
        $nombres  = array_flip($catalogo);

        $filas = DB::table('verificacion_documento_registro')
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
            ->where('TIPO', 'poliza')->where('ESTADO', VerificacionDocumento::APLICADO)
            ->get(['PROPUESTA']);

        foreach ($filas as $fila) {
            $p = json_decode((string) $fila->PROPUESTA, true);
            $idSeguro = $catalogo[$p['aseguradora'] ?? ''] ?? null;
            if (!$idSeguro || empty($p['link'])) continue;

            // Las fichas que siguen con ESE PDF (una póliza de flota es de varias unidades).
            $docs = DB::table('documentacion')->where('LINK_POLIZA_SEGURO', $p['link'])
                ->where(fn ($q) => $q->whereNull('ID_SEGURO')->orWhere('ID_SEGURO', '!=', $idSeguro))
                ->get(['ID_EQUIPO', 'ID_SEGURO']);
            foreach ($docs as $d) {
                DB::table('documentacion')->where('ID_EQUIPO', $d->ID_EQUIPO)->update(['ID_SEGURO' => $idSeguro]);
                EquipoAuditLog::registrar((int) $d->ID_EQUIPO, 'edit', [
                    'ID_SEGURO' => ['antes' => $nombres[$d->ID_SEGURO] ?? null, 'despues' => $p['aseguradora']],
                    '_origen'   => 'Carga masiva (aseguradora del PDF)',
                ]);
            }
        }
    }

    public function down(): void
    {
        // Datos corregidos: no hay vuelta atrás que tenga sentido (el historial guarda el valor viejo).
    }
};
