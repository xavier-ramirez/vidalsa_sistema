<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Usuario;
use Faker\Factory as Faker;

class UsuariosPruebaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create('es_ES');
        
        // Obtener roles y frentes existentes para asignar IDs válidos
        $rolesIds = DB::table('roles')->pluck('ID_ROL')->toArray();
        $frentesIds = DB::table('frentes_trabajo')->pluck('ID_FRENTE')->toArray();

        // Si no hay datos, usar valores por defecto (aunque podrían fallar si hay claves foráneas estrictas)
        if (empty($rolesIds)) $rolesIds = [1];
        if (empty($frentesIds)) $frentesIds = [null]; // Permitir null si no hay frentes

        for ($i = 0; $i < 10; $i++) {
            Usuario::create([
                'NOMBRE_COMPLETO' => $faker->name,
                'CORREO_ELECTRONICO' => $faker->unique()->email,
                'PASSWORD_HASH' => Hash::make('password123'), // Contraseña genérica
                'ID_ROL' => $rolesIds[array_rand($rolesIds)],
                'ID_FRENTE_ASIGNADO' => !empty($frentesIds) ? $frentesIds[array_rand($frentesIds)] : null,
                'NIVEL_ACCESO_EQUIPOS' => $faker->randomElement([1, 2]),
                'NIVEL_ACCESO_ALMACEN' => $faker->randomElement([1, 2]),
                'ESTATUS' => 'ACTIVO',
                'PERMISOS' => 'ver_tablero',
            ]);
        }
    }
}
