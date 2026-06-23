<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function loginPage()
    {
        if (auth()->check()) {
            return redirect()->route('menu');
        }
        return view('auth.inicio_sesion');
    }

    public function loginRedirect()
    {
        return redirect()->route('login');
    }

    public function refreshCsrf()
    {
        // El token DEBE viajar siempre fresco: si el navegador (o un proxy) cachea
        // esta respuesta, el login inyectaría un token caducado -> 419. Forzamos
        // no-store para que cada handshake traiga el token de la sesión vigente.
        return response(csrf_token())
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function forceFixDb()
    {
        $log = "<h2>Reparando y Ordenando Base de Datos...</h2><pre>";
        
        try {
            // 1. ELIMINAR COLUMNA VIEJA
            if (Schema::hasColumn('documentacion', 'ESTADO_POLIZA')) {
                Schema::table('documentacion', function ($table) {
                    $table->dropColumn('ESTADO_POLIZA');
                });
                $log .= "🗑️ Eliminada columna 'ESTADO_POLIZA'\n";
            }

            // 2. REORDENAR COLUMNAS (SQL PURO PARA MYSQL)
            // Propiedad
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN LINK_DOC_PROPIEDAD varchar(500) AFTER NOMBRE_DEL_TITULAR");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN PROPIEDAD_SUBIDO_POR varchar(191) AFTER LINK_DOC_PROPIEDAD");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN PROPIEDAD_FECHA_SUBIDA timestamp NULL AFTER PROPIEDAD_SUBIDO_POR");

            // Seguro / Poliza
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN ID_SEGURO bigint unsigned AFTER PROPIEDAD_FECHA_SUBIDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN FECHA_VENC_POLIZA date AFTER ID_SEGURO");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN LINK_POLIZA_SEGURO varchar(500) AFTER FECHA_VENC_POLIZA");
            
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN poliza_gestion_frente_id bigint unsigned AFTER LINK_POLIZA_SEGURO");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN poliza_gestion_fecha timestamp NULL AFTER poliza_gestion_frente_id");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN POLIZA_SUBIDO_POR varchar(191) AFTER poliza_gestion_fecha");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN POLIZA_FECHA_SUBIDA timestamp NULL AFTER POLIZA_SUBIDO_POR");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN poliza_status enum('vigente','en_proceso','vencido') AFTER POLIZA_FECHA_SUBIDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN poliza_frente_gestionando bigint unsigned AFTER poliza_status");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN poliza_fecha_inicio_gestion timestamp NULL AFTER poliza_frente_gestionando");

            // ROTC
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN FECHA_ROTC date AFTER poliza_fecha_inicio_gestion");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN LINK_ROTC varchar(500) AFTER FECHA_ROTC");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN rotc_gestion_frente_id bigint unsigned AFTER LINK_ROTC");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN rotc_gestion_fecha timestamp NULL AFTER rotc_gestion_frente_id");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN ROTC_SUBIDO_POR varchar(191) AFTER rotc_gestion_fecha");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN ROTC_FECHA_SUBIDA timestamp NULL AFTER ROTC_SUBIDO_POR");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN rotc_status enum('vigente','en_proceso','vencido') AFTER ROTC_FECHA_SUBIDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN rotc_frente_gestionando bigint unsigned AFTER rotc_status");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN rotc_fecha_inicio_gestion timestamp NULL AFTER rotc_frente_gestionando");

            // RACDA
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN FECHA_RACDA date AFTER rotc_fecha_inicio_gestion");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN LINK_RACDA varchar(500) AFTER FECHA_RACDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN racda_gestion_frente_id bigint unsigned AFTER LINK_RACDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN racda_gestion_fecha timestamp NULL AFTER racda_gestion_frente_id");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN RACDA_SUBIDO_POR varchar(191) AFTER racda_gestion_fecha");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN RACDA_FECHA_SUBIDA timestamp NULL AFTER RACDA_SUBIDO_POR");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN racda_status enum('vigente','en_proceso','vencido') AFTER RACDA_FECHA_SUBIDA");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN racda_frente_gestionando bigint unsigned AFTER racda_status");
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN racda_fecha_inicio_gestion timestamp NULL AFTER racda_frente_gestionando");

            // Adicional
            DB::statement("ALTER TABLE documentacion MODIFY COLUMN LINK_DOC_ADICIONAL text AFTER racda_fecha_inicio_gestion");

            $log .= "✅ Columnas REORDENADAS correctamente\n";
            $log .= "\n✨ PROCESO TERMINADO CON ÉXITO ✨</pre>";
            return $log;

        } catch (\Exception $e) {
            return "<h1>ERROR CRÍTICO:</h1><pre>" . $e->getMessage() . "</pre>";
        }
    }
}
