<?php

use App\Console\Commands\VerificarDocumentos;
use App\Models\EquipoAuditLog;
use App\Services\LectorDocumentoPdf;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 21-09-2026 (lo pidio el cliente al ver la pestaña de documentos):
 *
 * 1. PLACAS con letras de otro alfabeto que se ven IGUAL que las nuestras (A10AE0Н con la Н
 *    cirilica, AO748ҮВ): se pasan a latinas (LectorDocumentoPdf::HOMOGLIFOS). Asi el vehiculo
 *    se encuentra en sus documentos y en el buscador. Cada ficha deja su apunte en el historial.
 * 2. Se vuelven a LEER todas las lecturas de esas fichas, ya con la placa buena. (Los ROTC de
 *    flota que salieron como "PDF anterior" los relee la 2026_09_21_210000, junto con todos los
 *    demas ROTC: aqui no se repite.) Se pide la lectura como el boton "Revisar ahora": el
 *    programador la lanza en el siguiente minuto (solo en el servidor) y lo que falte en la
 *    ficha, como la emision del RACDA, se pone solo.
 *
 * Una sola vez: es una migracion. down vacio: lo cambiado queda en el historial de cada equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $arregladas = $this->arreglarPlacas();

        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        DB::table('verificacion_documento_registro')->whereIn('ID_EQUIPO', $arregladas)->delete();

        VerificarDocumentos::pedirAhora();
    }

    public function down(): void
    {
    }

    /** Pasa a latinas las placas con letras de otro alfabeto. Devuelve los ID_EQUIPO tocados. */
    private function arreglarPlacas(): array
    {
        $tocadas = [];
        // Solo las que tienen algo fuera de ASCII. Se filtra aqui y no en SQL: las pruebas corren
        // las migraciones en SQLite, que no tiene CONVERT ... USING.
        $fichas = DB::table('documentacion')->whereNotNull('PLACA')->get(['ID_EQUIPO', 'PLACA'])
            ->filter(fn ($f) => preg_match('/[^\x20-\x7E]/', $f->PLACA));

        foreach ($fichas as $ficha) {
            $latina = strtr($ficha->PLACA, LectorDocumentoPdf::HOMOGLIFOS);
            // Si queda alguna letra que no es un homoglifo conocido, se deja: no se adivina. Y si
            // otra ficha ya tiene esa placa (PLACA es unica), tambien: son dos fichas del mismo
            // vehiculo y eso lo decide una persona.
            if ($latina === $ficha->PLACA || preg_match('/[^\x20-\x7E]/', $latina)
                || DB::table('documentacion')->where('PLACA', $latina)->where('ID_EQUIPO', '<>', $ficha->ID_EQUIPO)->exists()) {
                continue;
            }

            DB::table('documentacion')->where('ID_EQUIPO', $ficha->ID_EQUIPO)->update(['PLACA' => $latina]);
            EquipoAuditLog::registrar($ficha->ID_EQUIPO, 'edit', [
                'PLACA'   => ['antes' => $ficha->PLACA, 'despues' => $latina],
                '_origen' => 'Arreglo del 21-09-2026: la placa tenía letras de otro alfabeto',
            ]);
            $tocadas[] = $ficha->ID_EQUIPO;
        }
        return $tocadas;
    }
};
