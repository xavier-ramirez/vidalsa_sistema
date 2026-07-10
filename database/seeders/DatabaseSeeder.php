<?php

namespace Database\Seeders;


use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            FrentesSeeder::class,
        ]);

        // forceCreate: ID_ROL / NIVEL_ACCESO_* / PERMISOS / ID_FRENTE_ASIGNADO NO están en
        // $fillable a proposito (campos sensibles: se asignan explicitamente desde
        // UserController bajo can:manage.users). Con create() Eloquent los descartaba en
        // silencio y este "super admin" nacia sin rol, sin permisos y LOCAL (default de la
        // columna) — justo lo contrario de lo que declara el seeder.
        \App\Models\Usuario::forceCreate([
            'NOMBRE_COMPLETO'      => 'Francisco Sanchez',
            'CORREO_ELECTRONICO'   => 'fsanchez@cvidalsa27.com',
            'PASSWORD_HASH'        => Hash::make('12345678'),
            'ID_ROL'               => 1, // SUPER ADMIN
            'ID_FRENTE_ASIGNADO'   => 1, // Primer frente creado por FrentesSeeder
            'NIVEL_ACCESO_EQUIPOS' => 1, // Global en equipos
            'NIVEL_ACCESO_ALMACEN' => 1, // Global en almacen
            'ESTATUS'              => 'ACTIVO',
            'PERMISOS'             => ['super.admin'],
        ]);
    }
}
