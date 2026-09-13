<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Color de las 49 pick-up SINOTRUK BOLDEN de la 8.ª embarcación (hoja "PICK-UP SINOTRUK 8va
 * EMBARCACIÓN", DUA C-5685), por serial de chasis.
 *
 * Solo llena el color donde está VACÍO: si alguien ya lo corrigió a mano en el formulario,
 * se respeta. Los colores van en masculino (ROJO, NEGRO, DORADO…) como los que ya existían
 * (BLANCO, AMARILLO): la hoja los escribe en femenino por "la camioneta", y mezclar los dos
 * partiría el mismo color en dos en cualquier filtro o conteo.
 *
 * Con el color en la unidad, la foto de cada color la pone el catálogo
 * (App\Models\CatalogoColor, ver la migración siguiente).
 */
return new class extends Migration
{
    private const COLORES = [
        'GRIS' => [
            'LZZWADG41TT501196', 'LZZWADG48TT501180', 'LZZWADG45TT501198', 'LZZWADG49TT501186', 'LZZWADG4XTT501178',
            'LZZWADG44TT501189', 'LZZWADG43TT501183', 'LZZWADG42TT501191', 'LZZWADG46TT501176', 'LZZWADG46TT501193',
            'LZZWADG40TT501190', 'LZZWADG48TT501177', 'LZZWADG4XTT501181', 'LZZWADG43TT501197', 'LZZWADG48TT501194',
            'LZZWADG45TT501184', 'LZZWADG40TT501187', 'LZZWADG41TT501179', 'LZZWADG41TT501182', 'LZZWADG44TT501192',
            'LZZWADG42TT501188', 'LZZWADG44TT501175', 'LZZWADG42TT501174', 'LZZWADG47TT501185', 'LZZWADG4XTT501195',
        ],
        'VERDE'    => ['LZZWADG48TT501146', 'LZZWADG43TT501166', 'LZZWADG4XTT500645', 'LZZWADG41TT500646'],
        'ROJO'     => ['LZZWADG48TT500644', 'LZZWADG47TT501168', 'LZZWADG46TT500643', 'LZZWADG45TT501167', 'LZZWADG49TT501169'],
        'NEGRO'    => ['LZZWADG47TT501204', 'LZZWADG49TT501205', 'LZZWADG42TT500641', 'LZZWADG44TT500642', 'LZZWADG45TT501203'],
        'DORADO'   => ['LZZWADG4XTT501200', 'LZZWADG43TT501202', 'LZZWADG43TT500647', 'LZZWADG41TT501201', 'LZZWADG47TT501199'],
        'AMARILLO' => ['LZZWADG48TT501034', 'LZZWADG49TT501172', 'LZZWADG40TT501173', 'LZZWADG45TT501170', 'LZZWADG47TT501171'],
    ];

    public function up(): void
    {
        foreach (self::COLORES as $color => $seriales) {
            DB::table('equipos')
                ->whereIn(DB::raw('UPPER(TRIM(SERIAL_CHASIS))'), $seriales)
                ->where(fn ($q) => $q->whereNull('COLOR')->orWhere('COLOR', ''))
                ->update(['COLOR' => $color, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Solo se vacía lo que sigue con el color que puso up(): un color cambiado a mano
        // después ya no es de esta migración.
        foreach (self::COLORES as $color => $seriales) {
            DB::table('equipos')
                ->whereIn(DB::raw('UPPER(TRIM(SERIAL_CHASIS))'), $seriales)
                ->where('COLOR', $color)
                ->update(['COLOR' => null]);
        }
    }
};
