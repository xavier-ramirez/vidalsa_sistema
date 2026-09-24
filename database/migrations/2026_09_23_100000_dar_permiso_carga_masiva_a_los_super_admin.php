<?php

use App\Models\Usuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * La carga masiva de documentos pasa a tener SU permiso, 'docs.carga.masiva', y es de los
 * EXCLUSIVOS (Usuario::PERMISOS_EXPLICITOS): ni super.admin lo hereda.
 *
 * Sin esto, el dia del despliegue TODOS los super.admin se quedarian sin la pantalla de golpe
 * (el boton desaparece del menu Acciones y las rutas responden 403), que es justo lo contrario
 * de lo que se busca: la clave esta para poder QUITARSELA a quien no deba tenerla, no para
 * dejar a nadie tirado. Asi que se le da a quien ya podia usarla —todo super.admin— y a partir
 * de ahi se desmarca a mano al que corresponda.
 *
 * Se lee y se escribe con el modelo porque PERMISOS es un JSON con cast: asi se guarda igual
 * que lo guarda la pantalla de usuarios y no hay dos formatos del mismo dato.
 */
return new class extends Migration
{
    private const CLAVE = 'docs.carga.masiva';

    /**
     * A quien se la dio ESTA migracion, para que down() no se la quite a nadie mas.
     *
     * Vive en la cache a proposito y con su riesgo asumido: si alguien vacia la cache entre el
     * up() y el down(), el rollback no encuentra la lista y NO QUITA NADA. Es el fallo que se
     * prefiere: dejar el permiso puesto de mas es una molestia; quitarselo a quien se lo
     * concedieron a mano deja a una persona sin poder trabajar y sin saber por que.
     */
    private const MEMORIA = 'migracion_carga_masiva_permiso_dado';

    /** A quien ya la tuviera no se le toca, y se deja anotado para poder deshacer bien. */
    public function up(): void
    {
        $dadas = [];
        foreach ($this->superAdmins() as $u) {
            if (in_array(self::CLAVE, (array) $u->PERMISOS, true)) continue;
            $u->PERMISOS = array_values(array_merge((array) $u->PERMISOS, [self::CLAVE]));
            $u->save();
            $dadas[] = $u->getKey();
        }
        Cache::forever(self::MEMORIA, $dadas);
    }

    /**
     * Se le quita SOLO a quien se la dio esta migracion (los ids anotados en up()). Si a
     * alguien se la concedieron a mano —antes o despues— se queda con ella: no es suya.
     */
    public function down(): void
    {
        foreach ((array) Cache::get(self::MEMORIA, []) as $id) {
            $u = Usuario::find($id);
            if (!$u || !in_array(self::CLAVE, (array) $u->PERMISOS, true)) continue;
            $u->PERMISOS = array_values(array_diff((array) $u->PERMISOS, [self::CLAVE]));
            $u->save();
        }
        Cache::forget(self::MEMORIA);
    }

    private function superAdmins()
    {
        return Usuario::whereNotNull('PERMISOS')->get()
            ->filter(fn ($u) => in_array('super.admin', array_map('strtolower', (array) $u->PERMISOS), true));
    }
};
