<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un mismo PUNTO puede estar en VARIOS proyectos.
 *
 * Antes cada punto colgaba de un solo oleoducto (mapa_oleoducto_puntos.oleoducto_id), así que
 * un sitio compartido por dos frentes había que crearlo dos veces: dos filas con la misma
 * coordenada que se editaban y se borraban por separado. Ahora el punto existe UNA vez y esta
 * tabla dice a qué proyectos está vinculado.
 *
 * El ORDEN vive aquí y no en el punto: el mismo punto puede ser el 3º de un proyecto y el 7º
 * de otro, y ese orden es lo que une la línea del tendido.
 *
 * Se ELIMINAN oleoducto_id y orden de mapa_oleoducto_puntos a propósito: dejarlos sería tener
 * la misma información en dos sitios, que es justo lo que se puede desincronizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mapa_oleoducto_puntos') || !Schema::hasTable('mapa_oleoductos')) return;

        if (!Schema::hasTable('mapa_oleoducto_punto_vinculos')) {
            Schema::create('mapa_oleoducto_punto_vinculos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('punto_id')->constrained('mapa_oleoducto_puntos')->cascadeOnDelete();
                $table->foreignId('oleoducto_id')->constrained('mapa_oleoductos')->cascadeOnDelete();
                $table->unsignedInteger('orden')->default(0); // orden DENTRO de ese proyecto
                $table->timestamps();

                // Un punto no puede estar dos veces en el mismo proyecto.
                $table->unique(['punto_id', 'oleoducto_id']);
                $table->index('oleoducto_id');
            });
        }

        // Traspaso de los vínculos que ya existían (1 por punto) y retirada de las columnas viejas.
        if (Schema::hasColumn('mapa_oleoducto_puntos', 'oleoducto_id')) {
            DB::table('mapa_oleoducto_puntos')->orderBy('id')->chunk(500, function ($filas) {
                $ahora = now();
                $nuevos = [];
                foreach ($filas as $f) {
                    if (empty($f->oleoducto_id)) continue;
                    $nuevos[] = [
                        'punto_id'     => $f->id,
                        'oleoducto_id' => $f->oleoducto_id,
                        'orden'        => $f->orden ?? 0,
                        'created_at'   => $ahora,
                        'updated_at'   => $ahora,
                    ];
                }
                if ($nuevos) DB::table('mapa_oleoducto_punto_vinculos')->insertOrIgnore($nuevos);
            });

            // Ni la clave foránea ni el índice tienen por qué existir (bases creadas sin ellos,
            // SQLite): si no están, se sigue. Sin esto la migración abortaba a media faena, con la
            // tabla pivote ya creada y los datos ya copiados.
            try {
                Schema::table('mapa_oleoducto_puntos', function (Blueprint $table) {
                    $table->dropForeign(['oleoducto_id']);
                });
            } catch (\Throwable $e) { /* no había clave foránea */ }
            try {
                Schema::table('mapa_oleoducto_puntos', function (Blueprint $table) {
                    $table->dropIndex(['oleoducto_id']);
                });
            } catch (\Throwable $e) { /* ya no existía */ }

            Schema::table('mapa_oleoducto_puntos', function (Blueprint $table) {
                $table->dropColumn(['oleoducto_id', 'orden']);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mapa_oleoducto_puntos')) return;

        if (!Schema::hasColumn('mapa_oleoducto_puntos', 'oleoducto_id')) {
            Schema::table('mapa_oleoducto_puntos', function (Blueprint $table) {
                $table->unsignedBigInteger('oleoducto_id')->nullable()->after('id');
                $table->unsignedInteger('orden')->default(0)->after('longitud');
                $table->index('oleoducto_id');
            });

            // Al volver atrás un punto solo puede quedar en UN proyecto: se conserva el primer vínculo.
            if (Schema::hasTable('mapa_oleoducto_punto_vinculos')) {
                DB::table('mapa_oleoducto_punto_vinculos')->orderBy('id')->chunk(500, function ($filas) {
                    foreach ($filas as $f) {
                        DB::table('mapa_oleoducto_puntos')
                            ->where('id', $f->punto_id)
                            ->whereNull('oleoducto_id')
                            ->update(['oleoducto_id' => $f->oleoducto_id, 'orden' => $f->orden]);
                    }
                });
            }

            // Los puntos que quedaran sin proyecto no pueden existir en el modelo viejo.
            DB::table('mapa_oleoducto_puntos')->whereNull('oleoducto_id')->delete();

            Schema::table('mapa_oleoducto_puntos', function (Blueprint $table) {
                $table->foreign('oleoducto_id')->references('id')->on('mapa_oleoductos')->cascadeOnDelete();
            });
        }

        Schema::dropIfExists('mapa_oleoducto_punto_vinculos');
    }
};
