<?php

/**
 * Mensajes de validación en ESPAÑOL.
 *
 * APP_LOCALE ya era 'es', pero no existía ningún archivo de idioma, así que Laravel caía
 * al inglés y además "humanizaba" los nombres de campo letra por letra: un campo llamado
 * ID_FRENTE_ACTUAL salía como "The i d  f r e n t e  a c t u a l field is required.".
 *
 * Aquí van SOLO las reglas que el proyecto usa de verdad (buscadas en los controladores)
 * y el array `attributes` con el nombre legible de cada campo. Cualquier clave que falte
 * cae al inglés por fallback_locale, así que añadir una regla nueva no rompe nada.
 */
return [

    'accepted'    => 'Debes aceptar :attribute.',
    'after'       => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'array'       => ':attribute debe ser una lista.',
    'before'      => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'boolean'     => ':attribute debe ser verdadero o falso.',
    'confirmed'   => 'La confirmación de :attribute no coincide.',
    'date'        => ':attribute no es una fecha válida.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'different'   => ':attribute y :other deben ser distintos.',
    'digits'      => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'email'       => ':attribute debe ser un correo electrónico válido.',
    'exists'      => ':attribute seleccionado no existe.',
    'file'        => ':attribute debe ser un archivo.',
    'filled'      => ':attribute no puede estar vacío.',
    'image'       => ':attribute debe ser una imagen.',
    'in'          => ':attribute seleccionado no es válido.',
    'integer'     => ':attribute debe ser un número entero.',
    'max'         => [
        'array'   => ':attribute no puede tener más de :max elementos.',
        'file'    => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string'  => ':attribute no puede tener más de :max caracteres.',
    ],
    'mimes'       => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes'   => ':attribute debe ser un archivo de tipo: :values.',
    'min'         => [
        'array'   => ':attribute debe tener al menos :min elementos.',
        'file'    => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string'  => ':attribute debe tener al menos :min caracteres.',
    ],
    'not_in'      => ':attribute seleccionado no es válido.',
    'numeric'     => ':attribute debe ser un número.',
    'present'     => ':attribute debe estar presente.',
    'prohibited'  => ':attribute está prohibido.',
    'regex'       => 'El formato de :attribute no es válido.',
    'required'    => 'Debes indicar :attribute.',
    'required_if' => 'Debes indicar :attribute cuando :other es :value.',
    'required_with' => 'Debes indicar :attribute cuando hay :values.',
    'same'        => ':attribute y :other deben coincidir.',
    'size'        => [
        'array'   => ':attribute debe contener :size elementos.',
        'file'    => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string'  => ':attribute debe tener :size caracteres.',
    ],
    'string'      => ':attribute debe ser texto.',
    'unique'      => ':attribute ya está registrado.',
    'uploaded'    => 'No se pudo subir :attribute.',
    'url'         => ':attribute debe ser una URL válida.',

    /**
     * Nombre legible de cada campo. Sin esto Laravel parte los nombres en MAYÚSCULAS con
     * guion bajo letra por letra ("i d  f r e n t e  a c t u a l").
     */
    'attributes' => [
        // Comunes a equipos y auxiliares
        'ID_FRENTE_ACTUAL'         => 'el frente de trabajo',
        'MARCA'                    => 'la marca',
        'MODELO'                   => 'el modelo',
        'ANIO'                     => 'el año',
        'TIPO'                     => 'el tipo',
        'ESTADO_OPERATIVO'         => 'el estado operativo',
        'OBSERVACIONES'            => 'las observaciones',
        'DETALLE_UBICACION_ACTUAL' => 'el detalle de ubicación',

        // Equipos
        'SERIAL_CHASIS'            => 'el serial de chasis',
        'SERIAL_DE_MOTOR'          => 'el serial de motor',
        'CODIGO_PATIO'             => 'el código de patio',
        'NUMERO_ETIQUETA'          => 'el número de etiqueta',
        'CATEGORIA_FLOTA'          => 'la categoría de flota',
        'id_tipo_equipo'           => 'el tipo de equipo',
        'ID_ESPEC'                 => 'el modelo del catálogo',
        'ID_ANCLAJE'               => 'el equipo de anclaje',

        // Auxiliares
        'SERIAL'                   => 'el serial',
        'CODIGO_INTERNO'           => 'el código interno',
        'CAPACIDAD'                => 'la capacidad',
        'ID_EQUIPO_HOST'           => 'el equipo host',

        // Documentación
        'documentacion.PLACA'      => 'la placa',
        'documentacion.NRO_DE_DOCUMENTO'   => 'el número de documento',
        'documentacion.NOMBRE_DEL_TITULAR' => 'el nombre del titular',

        // Usuarios
        'NOMBRE_COMPLETO'          => 'el nombre completo',
        'CORREO_ELECTRONICO'       => 'el correo electrónico',
        'CLAVE'                    => 'la clave',
        'PERMISOS'                 => 'los permisos',
    ],

];
