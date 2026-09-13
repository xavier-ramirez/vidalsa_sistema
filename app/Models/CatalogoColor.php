<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Foto de un COLOR de un modelo del catálogo (tabla catalogo_colores).
 *
 * La ficha técnica (caracteristicas_modelo) es una por modelo+año; el color es de cada
 * unidad (equipos.COLOR) y aquí se guarda la foto de cada color. Qué foto se muestra de
 * un equipo lo decide Equipo::fotoParaMostrar().
 */
class CatalogoColor extends Model
{
    protected $table = 'catalogo_colores';
    protected $primaryKey = 'ID_COLOR';

    protected $fillable = ['ID_ESPEC', 'COLOR', 'FOTO'];

    /**
     * Terminaciones en femenino que se escriben igual de seguido que en masculino ("la
     * camioneta ROJA", "el camión ROJO"): se guardan y se comparan en masculino, que es como
     * ya estaban los colores de la flota (BLANCO, AMARILLO). Sin esto ROJA y ROJO serían
     * dos colores y el ROJO de la ficha no le daría su foto a la unidad ROJA.
     */
    private const FEMENINOS = [
        'ROJA' => 'ROJO', 'NEGRA' => 'NEGRO', 'BLANCA' => 'BLANCO', 'AMARILLA' => 'AMARILLO',
        'DORADA' => 'DORADO', 'PLATEADA' => 'PLATEADO', 'MORADA' => 'MORADO', 'ANARANJADA' => 'ANARANJADO',
    ];

    /** "  roja " → "ROJO"; vacío → null. Punto ÚNICO para guardar y comparar colores. */
    public static function normalizar(?string $color): ?string
    {
        $c = preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $color)));
        if ($c === '') {
            return null;
        }
        return self::FEMENINOS[$c] ?? $c;
    }

    /** Tono de la muestra que se pinta junto al nombre del color en el catálogo. */
    private const MUESTRAS = [
        'BLANCO' => '#f8fafc', 'NEGRO' => '#111827', 'GRIS' => '#6b7280', 'PLATEADO' => '#cbd5e1',
        'ROJO' => '#dc2626', 'VINOTINTO' => '#7f1d1d', 'AZUL' => '#2563eb', 'VERDE' => '#15803d',
        'AMARILLO' => '#facc15', 'DORADO' => '#b08d57', 'ANARANJADO' => '#f97316', 'NARANJA' => '#f97316',
        'MARRON' => '#7c4a2d', 'MARRÓN' => '#7c4a2d', 'BEIGE' => '#d6c7a1', 'MORADO' => '#7c3aed',
    ];

    /** Tono de la muestra de un color (gris neutro si no se conoce o no hay color). */
    public static function muestra(?string $color): string
    {
        return self::MUESTRAS[self::normalizar($color) ?? ''] ?? '#94a3b8';
    }

    public function ficha()
    {
        return $this->belongsTo(CaracteristicaModelo::class, 'ID_ESPEC', 'ID_ESPEC');
    }
}
