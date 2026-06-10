<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: a los usuarios con rol SIHO se les agrega la clave
     * 'alertas.ver.certificado' en PERMISOS, para PRESERVAR su comportamiento (en el panel
     * de alertas ven SOLO certificados) tras migrar el filtro de por-ROL a por-PERMISO.
     *
     * Match por NOMBRE del rol (contiene 'SIHO') — robusto entre entornos (el ID puede
     * variar). Idempotente: no duplica la clave y respeta el límite de varchar(255).
     */
    public function up(): void
    {
        $clave = 'alertas.ver.certificado';

        $sihoRolIds = DB::table('roles')->where('NOMBRE_ROL', 'like', '%SIHO%')->pluck('ID_ROL');
        if ($sihoRolIds->isEmpty()) {
            return;
        }

        $usuarios = DB::table('usuarios')->whereIn('ID_ROL', $sihoRolIds)->get(['ID_USUARIO', 'PERMISOS']);
        foreach ($usuarios as $u) {
            $perms = array_values(array_filter(array_map('trim', explode(',', (string) $u->PERMISOS))));
            if (in_array($clave, array_map('strtolower', $perms), true)) {
                continue; // ya la tiene
            }
            $perms[] = $clave;
            $nuevo = implode(',', $perms);
            if (mb_strlen($nuevo) <= 255) {
                DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->update(['PERMISOS' => $nuevo]);
            }
        }
    }

    public function down(): void
    {
        // Reversible: quita la clave 'alertas.ver.certificado' de los usuarios SIHO.
        $clave = 'alertas.ver.certificado';
        $sihoRolIds = DB::table('roles')->where('NOMBRE_ROL', 'like', '%SIHO%')->pluck('ID_ROL');
        if ($sihoRolIds->isEmpty()) {
            return;
        }
        $usuarios = DB::table('usuarios')->whereIn('ID_ROL', $sihoRolIds)->get(['ID_USUARIO', 'PERMISOS']);
        foreach ($usuarios as $u) {
            $perms = array_values(array_filter(array_map('trim', explode(',', (string) $u->PERMISOS)), function ($p) use ($clave) {
                return strtolower($p) !== $clave;
            }));
            DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->update(['PERMISOS' => implode(',', $perms)]);
        }
    }
};
