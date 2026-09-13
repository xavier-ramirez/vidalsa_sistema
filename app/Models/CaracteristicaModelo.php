<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaracteristicaModelo extends Model
{
    protected $table = 'caracteristicas_modelo';
    protected $primaryKey = 'ID_ESPEC';

    protected $fillable = [
        'MODELO',
        'TIPO',
        'ANIO_ESPEC',
        'MOTOR',
        'ACEITE_MOTOR',
        'ACEITE_CAJA',
        'LIGA_FRENO',
        'REFRIGERANTE',
        'TIPO_BATERIA',
        'FOTO_REFERENCIAL'
    ];

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'ID_ESPEC', 'ID_ESPEC');
    }

    /** Foto de cada color de este modelo (ver CatalogoColor y Equipo::fotoParaMostrar). */
    public function colores()
    {
        return $this->hasMany(CatalogoColor::class, 'ID_ESPEC', 'ID_ESPEC');
    }

    /**
     * Guarda una foto del modelo: la de un COLOR si se indica, la general (FOTO_REFERENCIAL)
     * si no. Devuelve la ruta que había antes (para borrar el archivo viejo de Drive) o null.
     * Punto único: lo usan el catálogo y el formulario del equipo, así una foto subida desde
     * una pick-up roja nunca le cambia la foto al modelo entero.
     */
    public function guardarFoto(string $ruta, ?string $color = null): ?string
    {
        $color = CatalogoColor::normalizar($color);
        if ($color === null) {
            $anterior = $this->FOTO_REFERENCIAL;
            $this->update(['FOTO_REFERENCIAL' => $ruta]);
            return $anterior;
        }

        $fila = $this->colores()->firstOrNew(['COLOR' => $color]);
        $anterior = $fila->FOTO;
        $fila->FOTO = $ruta;
        $fila->save();
        return $anterior;
    }

    /** ID del archivo de Drive de una ruta /storage/google/{id}?… (o null). */
    public static function idDrive(?string $ruta): ?string
    {
        return $ruta ? (basename(str_replace('/storage/google/', '', explode('?', $ruta)[0])) ?: null) : null;
    }

    /**
     * ¿Alguna foto del catálogo (de un modelo o de un color) sigue apuntando a este archivo
     * de Drive? Antes de borrar uno viejo hay que preguntarlo: al unir las fichas repetidas
     * la foto gris quedó como foto del modelo Y del color GRIS, y borrarla por reemplazar
     * una dejaría a la otra rota.
     */
    public static function fotoSigueEnUso(string $idDrive): bool
    {
        $patron = '%/storage/google/' . $idDrive . '%';
        return static::where('FOTO_REFERENCIAL', 'like', $patron)->exists()
            || CatalogoColor::where('FOTO', 'like', $patron)->exists();
    }

    /** Filtros que usa este modelo de equipo (compatibilidad/fitment). */
    public function filtros()
    {
        return $this->belongsToMany(ProductoInventario::class, 'modelo_filtro', 'ID_ESPEC', 'ID_PRODUCTO')
                    ->withPivot('CANTIDAD')
                    ->withTimestamps();
    }
}
