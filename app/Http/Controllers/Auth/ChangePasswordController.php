<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChangePasswordController extends Controller
{
    public function show()
    {
        return view('auth.change_password');
    }

    public function update(Request $request)
    {
        $request->validate([
            'password' => ['required', 'confirmed', 'min:6'],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden. Por favor, verifique que ambos campos sean idénticos.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
        ]);

        $user = Auth::user();
        
        // establecerClave() rehashea y deja SESSION_TOKEN en NULL (ver el modelo): cambiar
        // la clave invalida la sesión.
        $user->establecerClave($request->password);
        $user->REQUIERE_CAMBIO_CLAVE = 0;
        $user->save();

        // Y aquí la cerramos de verdad, en vez de dejar que ValidarSesionUnica corte en el
        // request siguiente. Volver a entrar con la clave NUEVA es justo lo que rearma el
        // candado de acceso sin internet de este equipo (el formulario acaba de borrar el
        // de la clave vieja).
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/?aviso=clave_cambiada');
    }
}
