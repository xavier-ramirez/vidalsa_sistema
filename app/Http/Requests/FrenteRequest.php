<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            $contratos = [];
        }
        // Dedup conservando orden de aparición.
        $contratos = array_values(array_unique($contratos));

        $this->merge([
            'NOMBRE_FRENTE' => mb_strtoupper($this->input('NOMBRE_FRENTE')),
            'UBICACION'     => mb_strtoupper($this->input('UBICACION')),
            'ZONA'          => $this->filled('ZONA') ? mb_strtoupper($this->input('ZONA')) : null,
            'CONTRATOS'     => $contratos,
            'SUBDIVISIONES' => $this->filled('SUBDIVISIONES') ? mb_strtoupper($this->input('SUBDIVISIONES')) : null,
            'RESP_1_NOM'    => mb_strtoupper($this->input('RESP_1_NOM')),
            'RESP_1_CAR'    => mb_strtoupper($this->input('RESP_1_CAR')),
            'RESP_1_CED'    => mb_strtoupper($this->input('RESP_1_CED', '')),
            'RESP_1_EQU'    => mb_strtoupper($this->input('RESP_1_EQU')),
            'RESP_2_NOM'    => mb_strtoupper($this->input('RESP_2_NOM')),
            'RESP_2_CAR'    => mb_strtoupper($this->input('RESP_2_CAR')),
            'RESP_2_CED'    => mb_strtoupper($this->input('RESP_2_CED', '')),
            'RESP_2_EQU'    => mb_strtoupper($this->input('RESP_2_EQU')),
            'RESP_3_NOM'    => mb_strtoupper($this->input('RESP_3_NOM')),
            'RESP_3_CAR'    => mb_strtoupper($this->input('RESP_3_CAR')),
            'RESP_3_CED'    => mb_strtoupper($this->input('RESP_3_CED', '')),
            'RESP_3_EQU'    => mb_strtoupper($this->input('RESP_3_EQU')),
            'RESP_4_NOM'    => mb_strtoupper($this->input('RESP_4_NOM')),
            'RESP_4_CAR'    => mb_strtoupper($this->input('RESP_4_CAR')),
            'RESP_4_CED'    => mb_strtoupper($this->input('RESP_4_CED', '')),
            'RESP_4_EQU'    => mb_strtoupper($this->input('RESP_4_EQU')),
            'RESP_5_NOM'    => mb_strtoupper($this->input('RESP_5_NOM')),
            'RESP_5_CAR'    => mb_strtoupper($this->input('RESP_5_CAR')),
            'RESP_5_CED'    => mb_strtoupper($this->input('RESP_5_CED', '')),
            'RESP_5_EQU'    => mb_strtoupper($this->input('RESP_5_EQU')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Si hay una ID de frente (ruta PUT/PATCH update), excluirlo de la validación unique.
        $id = $this->route('frente') ?? $this->route('id');

        return [
            'NOMBRE_FRENTE' => 'required|string|max:150|unique:frentes_trabajo,NOMBRE_FRENTE,' . $id . ',ID_FRENTE',
            'UBICACION'     => 'required|string|max:100',
            'ZONA'          => 'nullable|string|max:100',
            'CONTRATOS'     => 'nullable|array',
            'CONTRATOS.*'   => 'string|max:60',
            'TIPO_FRENTE'   => 'required|in:OPERACION,RESGUARDO,ESPECIAL',
            'ESTATUS_FRENTE'=> 'required|in:ACTIVO,FINALIZADO',
            'SUBDIVISIONES' => 'nullable|string',
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
