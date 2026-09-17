<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FrenteTrabajo;
use App\Models\Equipo;
use App\Models\TipoEquipo;
use Illuminate\Support\Facades\DB;

class RegistroEquipoSeeder extends Seeder
{
    public function run()
    {
        DB::transaction(function () {
            // 1. Ensure Frente exists
            $frente = FrenteTrabajo::firstOrCreate(
                ['NOMBRE_FRENTE' => 'ANACO'],
                [
                    'UBICACION' => 'Anzoátegui, Anaco',
                    'RESP_1_NOM' => 'No Asignado',
                    'RESP_1_CAR' => 'Supervisor',
                    'ESTATUS_FRENTE' => 'ACTIVO'
                ]
            );

            // 2. Create Equipment
            // El TIPO va por su id (id_tipo_equipo). 'TIPO_EQUIPO' no es columna de
            // `equipos`, asi que updateOrCreate lo descartaba EN SILENCIO al pasar por
            // fill() y el equipo nacia sin tipo -mientras el mensaje de abajo decia que
            // habia quedado registrado-.
            $tipo = TipoEquipo::firstOrCreate(['nombre' => 'VOLTEO']);

            $equipo = Equipo::updateOrCreate(
                ['SERIAL_CHASIS' => 'LZZ1ELSF1SJ413129'], // Unique key
                [
                    'CODIGO_PATIO' => 'VS-BH-STK-02',
                    'id_tipo_equipo' => $tipo->id,
                    'MARCA' => 'SINOTRUK',
                    'MODELO' => 'ZZ3257V464JB1',
                    'ANIO' => 2025,
                    'SERIAL_DE_MOTOR' => '1425F022978',
                    'ESTADO_OPERATIVO' => 'OPERATIVO',
                    'CATEGORIA_FLOTA' => 'MAQUINARIA_PESADA', // Defaulting or inferring
                    'CONFIRMADO_EN_SITIO' => true
                ]
            );

            // ID_FRENTE_ACTUAL esta FUERA de $fillable a proposito (lo documenta el modelo:
            // el frente se mueve por movilizacion, no por asignacion masiva). Dentro del
            // array se perdia sin avisar; aqui se asigna por la puerta que el modelo deja
            // abierta.
            $equipo->ID_FRENTE_ACTUAL = $frente->ID_FRENTE;
            $equipo->save();

            // 3. Create Specs (Optional but good to store model info)
            // Checking if specs exist for this model to avoid duplicates if possible, or just link if I had specs logic fully disjoint.
            // For now, I won't create a separate spec record unless I need to ID_ESPEC. 
            // The user didn't give technical specs like engine capacity etc, so I will skip creating CaracteristicaModelo 
            // and just rely on the Equipo table fields I just filled.
            
            $this->command->info("Equipo VS-BH-STK-02 registrado en frente ANACO.");
        });
    }
}
