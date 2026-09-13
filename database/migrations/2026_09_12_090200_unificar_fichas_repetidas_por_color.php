<?php

use App\Models\CaracteristicaModelo;
use App\Models\CatalogoColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une las fichas de catálogo repetidas (mismo MODELO + año) que existían SOLO para tener
 * otra foto por color, y convierte cada una de esas fotos en la foto de su color
 * (catalogo_colores).
 *
 * Por qué había fichas repetidas: el catálogo solo guardaba UNA foto por modelo y la tabla
 * de Equipos la usa antes que la de la unidad, así que la única forma de mostrar la pick-up
 * roja con foto roja era copiar la ficha. La unidad no decía de qué color era, y la ficha 49
 * juntaba verdes y amarillas: las 5 amarillas se veían verdes.
 *
 * Para cada grupo repetido:
 *   1. Se queda la ficha más antigua (menor ID) como la del modelo; si le falta algún dato
 *      técnico que otra tenga, lo toma de ella.
 *   2. La foto de cada ficha pasa a ser la foto de un COLOR: el que dice FOTOS_CONOCIDAS
 *      (se miró cada foto) o, si no está ahí, el color de sus unidades cuando todas
 *      comparten uno. Si no hay forma de saberlo, la foto no se asigna a ningún color.
 *   3. Las unidades, los filtros del modelo (modelo_filtro) y el historial del catálogo de
 *      las fichas repetidas pasan a la que se queda, y las repetidas se borran.
 *   4. Las unidades de ese modelo+año que no tenían ficha (el enganche automático estaba
 *      bloqueado mientras hubo varias) quedan enlazadas a la ficha única.
 *
 * FOTOS_CONOCIDAS va por el ID del archivo en Google Drive, que es el mismo en esta base y en
 * la del servidor (comparten el Drive), así que la migración da el mismo resultado en las dos
 * aunque los ID de las fichas difieran.
 */
return new class extends Migration
{
    /** Archivo de Drive → color que muestra (revisadas una por una el 12-09-2026). */
    private const FOTOS_CONOCIDAS = [
        '1v3NH4zuHqeuB4RV9oPYK9UutEu342ysQ' => 'GRIS',
        '1EJvQgLB6-Ywk2nZF2zuEN8qCo8xhToOa' => 'ROJO',
        '1soAKfZLcE-QmOALDmf0Uuo_aknvvHKxB' => 'DORADO',
        '101ucNn3M8kD4Co--VSvBPFLzn4aaza4_' => 'NEGRO',
        '17lfil7TVUN6GLwzNyrzLIdDpXy-ydrLH' => 'VERDE',
    ];

    private const DATOS_TECNICOS = ['TIPO', 'MOTOR', 'ACEITE_MOTOR', 'ACEITE_CAJA', 'LIGA_FRENO', 'REFRIGERANTE', 'TIPO_BATERIA', 'FOTO_REFERENCIAL'];

    public function up(): void
    {
        $grupos = DB::table('caracteristicas_modelo')
            ->select('MODELO', 'ANIO_ESPEC')
            ->groupBy('MODELO', 'ANIO_ESPEC')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupos as $g) {
            DB::transaction(fn () => $this->unir($g->MODELO, $g->ANIO_ESPEC));
        }
    }

    private function unir(string $modelo, $anio): void
    {
        $fichas = DB::table('caracteristicas_modelo')
            ->where('MODELO', $modelo)->where('ANIO_ESPEC', $anio)
            ->orderBy('ID_ESPEC')->lockForUpdate()->get();
        if ($fichas->count() < 2) {
            return;
        }
        $principal = $fichas->first();
        $ahora = now();

        // 1. Datos técnicos que le falten a la principal, de la primera que los tenga.
        $completar = [];
        foreach (self::DATOS_TECNICOS as $campo) {
            if ($principal->{$campo} === null || $principal->{$campo} === '') {
                $valor = $fichas->first(fn ($f) => $f->{$campo} !== null && $f->{$campo} !== '')?->{$campo};
                if ($valor !== null) {
                    $completar[$campo] = $valor;
                }
            }
        }
        if ($completar) {
            DB::table('caracteristicas_modelo')->where('ID_ESPEC', $principal->ID_ESPEC)->update($completar + ['updated_at' => $ahora]);
        }

        foreach ($fichas as $ficha) {
            // 2. Su foto, como foto de un color.
            $color = $this->colorDeLaFoto($ficha);
            if ($color !== null) {
                DB::table('catalogo_colores')->insertOrIgnore([
                    'ID_ESPEC'   => $principal->ID_ESPEC,
                    'COLOR'      => $color,
                    'FOTO'       => $ficha->FOTO_REFERENCIAL,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
            if ((int) $ficha->ID_ESPEC === (int) $principal->ID_ESPEC) {
                continue;
            }

            // 3. Todo lo que colgaba de la repetida pasa a la principal.
            DB::table('equipos')->where('ID_ESPEC', $ficha->ID_ESPEC)->update(['ID_ESPEC' => $principal->ID_ESPEC]);
            // modelo_filtro es único por (ID_ESPEC, ID_PRODUCTO): se copia lo que la principal
            // no tenga y el resto se va con la ficha.
            foreach (DB::table('modelo_filtro')->where('ID_ESPEC', $ficha->ID_ESPEC)->get() as $mf) {
                $fila = (array) $mf;
                unset($fila['ID_MODELO_FILTRO']);
                DB::table('modelo_filtro')->insertOrIgnore(['ID_ESPEC' => $principal->ID_ESPEC] + $fila);
            }
            DB::table('modelo_filtro')->where('ID_ESPEC', $ficha->ID_ESPEC)->delete();
            if (Schema::hasTable('catalogo_audit_log')) {
                DB::table('catalogo_audit_log')->where('ID_ESPEC', $ficha->ID_ESPEC)->update(['ID_ESPEC' => $principal->ID_ESPEC]);
            }
            DB::table('caracteristicas_modelo')->where('ID_ESPEC', $ficha->ID_ESPEC)->delete();
        }

        // 4. Las unidades sueltas del mismo modelo+año (o con una ficha que ya no existe).
        DB::table('equipos')
            ->where('MODELO', $modelo)->where('ANIO', $anio)
            ->where(fn ($q) => $q->whereNull('ID_ESPEC')->orWhereNotIn('ID_ESPEC', DB::table('caracteristicas_modelo')->select('ID_ESPEC')))
            ->update(['ID_ESPEC' => $principal->ID_ESPEC]);
    }

    /** Color de la foto de una ficha, o null si no se puede saber. */
    private function colorDeLaFoto(object $ficha): ?string
    {
        if (!$ficha->FOTO_REFERENCIAL) {
            return null;
        }
        $idDrive = CaracteristicaModelo::idDrive($ficha->FOTO_REFERENCIAL);
        if (isset(self::FOTOS_CONOCIDAS[$idDrive])) {
            return self::FOTOS_CONOCIDAS[$idDrive];
        }

        // Sin revisar a mano: vale solo si todas sus unidades son del mismo color. Se escribe
        // como se comparan los colores (CatalogoColor::normalizar): esta migración corre antes
        // de la que normaliza equipos.COLOR y un "BLANCA" dejaría una foto que nadie usa.
        $colores = DB::table('equipos')->where('ID_ESPEC', $ficha->ID_ESPEC)
            ->whereNotNull('COLOR')->where('COLOR', '!=', '')
            ->distinct()->pluck('COLOR')
            ->map(fn ($c) => CatalogoColor::normalizar($c))->filter()->unique();

        return $colores->count() === 1 ? $colores->first() : null;
    }

    public function down(): void
    {
        // Intencionalmente vacío: las fichas repetidas eran copias exactas salvo la foto, y
        // esa foto quedó guardada como foto de su color. Volver a partirlas no recupera nada.
    }
};
