<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\TipoEquipo;
use Illuminate\Support\Str;

class BarcelonaEquiposSeeder extends Seeder
{
    public function run()
    {
        // 1. Find or Create "Barcelona" Front
        $frente = FrenteTrabajo::where('NOMBRE_FRENTE', 'like', '%Barcelona%')->first();

        if (!$frente) {
            $frente = FrenteTrabajo::create([
                'NOMBRE_FRENTE' => 'Barcelona',
                'ESTATUS_FRENTE' => 'ACTIVO',
                'UBICACION' => 'Barcelona, Anzoátegui',
                 // Add other required fields if any, typically these are enough based on context
            ]);
            $this->command->info('Frente "Barcelona" created.');
        } else {
            $this->command->info('Frente "Barcelona" found: ' . $frente->NOMBRE_FRENTE);
        }

        // 2. Ensure some Types exist
        $tipos = ['Camioneta', 'Excavadora', 'Volqueta', 'Grúa', 'Compresor'];
        $tipoIds = [];
        foreach ($tipos as $nombre) {
            $t = TipoEquipo::firstOrCreate(['nombre' => strtoupper($nombre)]);
            $tipoIds[] = $t->id;
        }

        // 3. Create 20 Equipments
        $marcas = ['Toyota', 'Caterpillar', 'Mack', 'Ford', 'Komatsu'];
        $estados = ['OPERATIVO', 'INACTIVO', 'EN MANTENIMIENTO'];

        for ($i = 1; $i <= 20; $i++) {
            $tipoId = $tipoIds[array_rand($tipoIds)];
            $marca = $marcas[array_rand($marcas)];
            $modelo = 'MOD-' . rand(100, 999);
            $anio = rand(2010, 2025);
            $estado = $estados[array_rand($estados)];
            $categoria = ($marca == 'Toyota' || $marca == 'Ford') ? 'FLOTA LIVIANA' : 'FLOTA PESADA';

            $baseCode = 'BAR-' . str_pad($i, 3, '0', STR_PAD_LEFT);
            // Ensure unique Code
            while(Equipo::where('CODIGO_PATIO', $baseCode)->exists()) {
                $baseCode = 'BAR-' . str_pad($i + rand(100,999), 3, '0', STR_PAD_LEFT);
            }

            Equipo::create([
                'id_tipo_equipo' => $tipoId,
                'CATEGORIA_FLOTA' => $categoria,
                'CODIGO_PATIO' => $baseCode,
                'MARCA' => $marca,
                'MODELO' => $modelo,
                'ANIO' => $anio,
                'SERIAL_CHASIS' => strtoupper(Str::random(17)),
                'SERIAL_DE_MOTOR' => strtoupper(Str::random(12)),
                'ESTADO_OPERATIVO' => $estado,
                'ID_FRENTE_ACTUAL' => $frente->ID_FRENTE,
                'CONFIRMADO_EN_SITIO' => 1,
            ]);
        }

        $this->command->info('20 Equipos registered for ' . $frente->NOMBRE_FRENTE);
    }
}
