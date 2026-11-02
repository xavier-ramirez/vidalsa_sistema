<?php

namespace App\Support;

/**
 * Enlaces de las fotos de resources/fotos_login, que comparten el login y el encabezado del menu.
 * Agregar o quitar una foto = agregar o quitar el archivo. Las entrega la ruta /foto-login.
 */
class FotosLogin
{
    /** @return list<string> */
    public static function urls(): array
    {
        return array_map(
            fn ($f) => url('foto-login/' . basename($f, '.webp')) . '?v=' . filemtime($f),
            glob(resource_path('fotos_login/*.webp')) ?: []
        );
    }
}
