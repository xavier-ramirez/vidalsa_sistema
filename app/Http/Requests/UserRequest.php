<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // We use policies via middleware in controller
    }

    protected function prepareForValidation()
    {
        if ($this->has('NOMBRE_COMPLETO')) {
            $this->merge([
                'NOMBRE_COMPLETO' => mb_convert_case($this->input('NOMBRE_COMPLETO'), MB_CASE_TITLE, 'UTF-8'),
                'CORREO_ELECTRONICO' => strtolower($this->input('CORREO_ELECTRONICO')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('usuario') ?? $this->route('id');

        $rules = [
            'NOMBRE_COMPLETO' => 'required|string|max:150',
            'CORREO_ELECTRONICO' => [
                'required',
                'email',
                $id ? Rule::unique('usuarios', 'CORREO_ELECTRONICO')->ignore($id, 'ID_USUARIO') : 'unique:usuarios,CORREO_ELECTRONICO',
                'regex:/^.+@cvidalsa27\.com$/i'
            ],
            // Si es creacion password required, si es actualizacion nullable
            'password' => $id ? 'nullable|string|min:6' : 'required|string|min:6',
            'ID_ROL' => 'required|string|max:150',
            'ID_FRENTE_ASIGNADO' => 'nullable|array',
            'ID_FRENTE_ASIGNADO.*' => 'exists:frentes_trabajo,ID_FRENTE',
            'ID_FRENTE_BLOQUEADO' => 'nullable|array',
            'ID_FRENTE_BLOQUEADO.*' => 'exists:frentes_trabajo,ID_FRENTE',
            'NIVEL_ACCESO' => 'required|integer|in:1,2',
            'ESTATUS' => 'required|in:ACTIVO,INACTIVO',
            'PERMISOS' => 'nullable|array',
            'PERMISOS.*' => Rule::in(array_keys(\App\Http\Controllers\UserController::availablePermissions())),
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'NOMBRE_COMPLETO.required' => 'El nombre completo es obligatorio.',
            'CORREO_ELECTRONICO.required' => 'El correo electrónico es obligatorio.',
            'CORREO_ELECTRONICO.email' => 'El formato del correo electrónico no es válido.',
            'CORREO_ELECTRONICO.unique' => 'Este correo electrónico ya está registrado en el sistema.',
            'CORREO_ELECTRONICO.regex' => 'Solo se permiten correos con el dominio @cvidalsa27.com',
            'password.required' => 'La clave de acceso es obligatoria.',
            'password.min' => 'La clave de acceso debe tener al menos 6 caracteres.',
            'ID_ROL.required' => 'Debes asignar un rol al usuario.',
            'ID_ROL.max' => 'El rol no puede tener más de 150 caracteres.',
            'ID_FRENTE_ASIGNADO.required' => 'Debes asignar al menos un frente de trabajo.',
            'ID_FRENTE_ASIGNADO.min' => 'Debes asignar al menos un frente de trabajo.',
            'NIVEL_ACCESO.required' => 'El nivel de acceso es obligatorio.',
            'NIVEL_ACCESO.in' => 'El nivel de acceso seleccionado no es válido.',
            'ESTATUS.required' => 'El estatus es obligatorio.',
            'ESTATUS.in' => 'El estatus seleccionado no es válido.',
        ];
    }
}
