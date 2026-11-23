<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FrenteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // CONTRATOS: el formulario manda un campo "CONTRATOS" como string CSV (un input
        // con chips estilo `subdivisiones`). Lo normalizamos a array de strings UPPER
        // antes de validar. Acepta también el caso en que el cliente ya mande un array.
        $contratos = $this->input('CONTRATOS');
        if (is_string($contratos)) {
            $contratos = array_values(array_filter(array_map(
                fn ($v) => mb_strtoupper(trim((string) $v)),
                preg_split('/[,;\n\r]+/', $contratos) ?: []
            ), fn ($v) => $v !== ''));
        } elseif (is_array($contratos)) {
            $contratos = array_values(array_filter(array_map(
                fn ($v) => mb_strtoupper(trim((string) $v)),
                $contratos
            ), fn ($v) => $v !== ''));
        } else {
            // La clave NO viene en la peticion. Aqui NO se pone [] : eso borraria los
            // contratos ya guardados. Hoy el unico que llama a frentes.update es el
            // formulario, que siempre manda el hidden CONTRATOS_HIDDEN; pero cualquier
            // otro camino (una edicion por JSON, un formulario nuevo que se olvide el
            // campo) vaciaria la columna sin que nadie lo pidiera. Se deja fuera del
            // merge y el modelo no lo toca.
            $contratos = null;
        }
        if ($contratos !== null) {
            // Dedup conservando orden de aparicion.
            $contratos = array_values(array_unique($contratos));
        }

        if ($contratos !== null) {
            $this->merge(['CONTRATOS' => $contratos]);
        }

        // En mayúsculas; lo vacío queda NULL. Antes mb_strtoupper(null) guardaba '' en los
        // responsables sin llenar, y el acta (MovilizacionController::extractFirmasActa) no
        // ponía su cargo por defecto porque '' no es null.
        $campos = ['NOMBRE_FRENTE', 'UBICACION', 'ZONA', 'SUBDIVISIONES'];
        for ($i = 1; $i <= 5; $i++) {
            array_push($campos, "RESP_{$i}_NOM", "RESP_{$i}_CAR", "RESP_{$i}_CED", "RESP_{$i}_EQU");
        }
        $this->merge(collect($campos)->mapWithKeys(fn ($c) => [
            $c => $this->filled($c) ? mb_strtoupper(trim((string) $this->input($c))) : null,
        ])->all());
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Si hay una ID de frente (ruta PUT/PATCH update), excluirlo de la validación unique.
        $id = $this->route('frente') ?? $this->route('id');

        return [
            // Al CREAR no cuentan los frentes eliminados (store revive el del mismo nombre).
            // Al EDITAR tampoco cuentan en la regla unique, pero los rechaza la función de abajo
            // con su propio aviso: renombrar a ese nombre chocaría con el índice UNIQUE.
            'NOMBRE_FRENTE' => ['required', 'string', 'max:150', $id
                ? Rule::unique('frentes_trabajo', 'NOMBRE_FRENTE')->ignore($id, 'ID_FRENTE')->whereNull('deleted_at')
                : Rule::unique('frentes_trabajo', 'NOMBRE_FRENTE')->whereNull('deleted_at'),
                // Al editar, el choque con un frente ELIMINADO se explica aparte: «Ya existe un
                // frente con este nombre» mandaba a buscar uno que no sale en ninguna lista.
                function ($attr, $valor, $fail) use ($id) {
                    if ($id && \App\Models\FrenteTrabajo::onlyTrashed()->where('NOMBRE_FRENTE', $valor)
                            ->where('ID_FRENTE', '!=', $id)->exists()) {
                        $fail('Ese nombre es de un frente eliminado. Para usarlo, regístralo como frente nuevo: se recupera con su historial.');
                    }
                }],
            'UBICACION'     => 'required|string|max:100',
            'ZONA'          => 'nullable|string|max:100',
            'CONTRATOS'     => 'nullable|array',
            'CONTRATOS.*'   => 'string|max:60',
            'TIPO_FRENTE'   => ['required', Rule::in(array_keys(\App\Models\FrenteTrabajo::TIPOS))],
            'ESTATUS_FRENTE'=> 'required|in:ACTIVO,FINALIZADO',
            // La columna es TEXT (65.535 bytes): sin tope, un texto enorme llegaba a la base y
            // salía un error 500 en vez del aviso.
            'SUBDIVISIONES' => 'nullable|string|max:20000',
            'RESP_1_NOM'    => 'nullable|string|max:60',
            'RESP_1_CAR'    => 'nullable|string|max:40',
            'RESP_1_CED'    => 'nullable|string|max:20',
            'RESP_1_EQU'    => 'nullable|string|max:40',
            'RESP_2_NOM'    => 'nullable|string|max:60',
            'RESP_2_CAR'    => 'nullable|string|max:40',
            'RESP_2_CED'    => 'nullable|string|max:20',
            'RESP_2_EQU'    => 'nullable|string|max:40',
            'RESP_3_NOM'    => 'nullable|string|max:60',
            'RESP_3_CAR'    => 'nullable|string|max:40',
            'RESP_3_CED'    => 'nullable|string|max:20',
            'RESP_3_EQU'    => 'nullable|string|max:40',
            'RESP_4_NOM'    => 'nullable|string|max:60',
            'RESP_4_CAR'    => 'nullable|string|max:40',
            'RESP_4_CED'    => 'nullable|string|max:20',
            'RESP_4_EQU'    => 'nullable|string|max:40',
            'RESP_5_NOM'    => 'nullable|string|max:80',
            'RESP_5_CAR'    => 'nullable|string|max:60',
            'RESP_5_CED'    => 'nullable|string|max:20',
            'RESP_5_EQU'    => 'nullable|string|max:40',
        ];
    }

    /**
     * Nombre de cada campo en los avisos. Sin esto Laravel deletrea la clave en mayúsculas
     * («n o m b r e  f r e n t e no puede tener más de 150 caracteres»).
     */
    public function attributes(): array
    {
        $nombres = [
            'NOMBRE_FRENTE'  => 'el nombre del frente',
            'UBICACION'      => 'la ubicación',
            'ZONA'           => 'la zona',
            'CONTRATOS'      => 'los contratos',
            'CONTRATOS.*'    => 'cada contrato',
            'TIPO_FRENTE'    => 'el tipo de frente',
            'ESTATUS_FRENTE' => 'el estatus',
            'SUBDIVISIONES'  => 'las subdivisiones',
        ];
        $partes = ['NOM' => 'el nombre', 'CAR' => 'el cargo', 'CED' => 'la cédula', 'EQU' => 'el filtro de firma'];
        for ($i = 1; $i <= 5; $i++) {
            foreach ($partes as $sufijo => $texto) {
                $nombres["RESP_{$i}_{$sufijo}"] = "{$texto} del responsable {$i}";
            }
        }

        return $nombres;
    }

    /**
     * Custom messages
     */
    public function messages(): array
    {
        return [
            'NOMBRE_FRENTE.required' => 'El nombre del frente es obligatorio.',
            'NOMBRE_FRENTE.unique'   => 'Ya existe un frente de trabajo con este nombre.',
            'UBICACION.required'     => 'La ubicación es obligatoria.',
            'TIPO_FRENTE.required'   => 'El tipo de frente es obligatorio.',
            'ESTATUS_FRENTE.required'=> 'El estatus es obligatorio.',
        ];
    }
}
