<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FrentesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $frentes = [
            ['NOMBRE_FRENTE' => 'BARCELONA'],
            ['NOMBRE_FRENTE' => 'MATURIN'],
            ['NOMBRE_FRENTE' => 'CARACAS'],
        ];

        foreach ($frentes as $frente) {
            DB::table('frentes_trabajo')->updateOrInsert(
                ['NOMBRE_FRENTE' => $frente['NOMBRE_FRENTE']],
                ['TIPO_FRENTE' => 'OPERACION', 'ESTATUS_FRENTE' => 'ACTIVO']
            );
        }

        Cache::forget('frentes_especial_ids');
    }
}
