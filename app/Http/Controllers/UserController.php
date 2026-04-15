<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Role;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Requiere clave super.admin EN PERMISOS + rol SUPER ADMIN
        $this->middleware('can:manage.users')->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    }

    // ─── Perfil Propio (para usuarios sin permiso manage.users) ──────────────

    /**
     * Muestra la página de edición de clave propia.
     */
    public function miPerfil()
    {
        $user = auth()->user();
        return view('admin.usuarios.mi_perfil', compact('user'));
    }

    /**
     * Actualiza SOLO la contraseña del usuario autenticado.
     */
    public function actualizarMiClave(Request $request)
    {
        $request->validate([
            'password'              => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required',
        ], [
            'password.required'     => 'La nueva contraseña es obligatoria.',
            'password.min'          => 'La contraseña debe tener al menos 6 caracteres.',
            'password.confirmed'    => 'Las contraseñas no coinciden.',
        ]);

        $user = auth()->user();
        $user->PASSWORD_HASH = Hash::make($request->password);
        $user->REQUIERE_CAMBIO_CLAVE = 0;
        $user->save();

        return back()->with('success_perfil', '¡Contraseña actualizada correctamente!');
    }


    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Start with base query
        $query = Usuario::select('ID_USUARIO', 'NOMBRE_COMPLETO', 'CORREO_ELECTRONICO', 'ID_ROL', 'ID_FRENTE_ASIGNADO', 'NIVEL_ACCESO', 'ESTATUS')
            ->with([
                'rol:ID_ROL,NOMBRE_ROL', 
                'frenteAsignado:ID_FRENTE,NOMBRE_FRENTE'
            ]);

        // FILTER 1: Search by name or email (independent)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('NOMBRE_COMPLETO', 'like', "%{$search}%")
                  ->orWhere('CORREO_ELECTRONICO', 'like', "%{$search}%");
            });
        }

        // FILTER 2: Frente de Trabajo (independent)
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $query->where('ID_FRENTE_ASIGNADO', $request->input('id_frente'));
        }

        // Execute query with pagination
        $users = $query->paginate(10)->onEachSide(3)->withQueryString();
        
        // Frentes for dropdown
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->get();

        // Statistics for info cards (Optimized to 1 query)
        $stats = Usuario::selectRaw("
            count(*) as total, 
            count(case when ESTATUS = 'ACTIVO' then 1 end) as activos, 
            count(case when ESTATUS = 'INACTIVO' then 1 end) as inactivos
        ")->first();

        $totalUsuarios = $stats->total;
        $usuariosActivos = $stats->activos;
        $usuariosInactivos = $stats->inactivos;

        // AJAX Response
        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.usuarios.partials.table_rows', compact('users'))->render(),
                'pagination' => $users->links()->toHtml(),
                'count' => $users->total()
            ]);
        }

        return view('admin.usuarios.lista', compact('users', 'frentes', 'totalUsuarios', 'usuariosActivos', 'usuariosInactivos'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->get();
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        $available_permissions = [
            'user.create'       => 'Registrar Usuarios',
            'user.edit'         => 'Actualizar Información',
            'user.delete'       => 'Eliminar Usuarios',
            'equipos.create'    => 'Registrar Equipos',
            'equipos.edit'      => 'Actualizar Equipos',
            'equipos.assign'    => 'Asignar Equipos',
            'super.admin'       => 'Acceso Total (Super Admin)',
        ];
        
        return view('admin.usuarios.formulario', compact('roles', 'frentes', 'available_permissions'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'NOMBRE_COMPLETO' => 'required|string|max:150',

            'CORREO_ELECTRONICO' => [
                'required',
                'email',
                'unique:usuarios,CORREO_ELECTRONICO',
                'regex:/^.+@cvidalsa27\.com$/i'
            ],
            'password' => 'required|string|min:6',
            'ID_ROL' => 'required|string|max:150',
            'ID_FRENTE_ASIGNADO' => 'nullable|array',
            'ID_FRENTE_ASIGNADO.*' => 'exists:frentes_trabajo,ID_FRENTE',
            'NIVEL_ACCESO' => 'required|integer|in:1,2',
            'ESTATUS' => 'required|in:ACTIVO,INACTIVO',
            'PERMISOS' => 'required|array',
            'PERMISOS.*' => 'in:user.create,user.edit,user.delete,equipos.create,equipos.edit,equipos.assign,super.admin',
        ], [
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
            'PERMISOS.required' => 'Debes seleccionar al menos un permiso.',
        ]);

        // Create user with mass assignment for validated data
        $user = new Usuario();
        $user->NOMBRE_COMPLETO = mb_convert_case($request->NOMBRE_COMPLETO, MB_CASE_TITLE, 'UTF-8');

        $user->CORREO_ELECTRONICO = strtolower($request->CORREO_ELECTRONICO);
        $user->PASSWORD_HASH = Hash::make($request->password);
        // Resolver el Rol (si lo escribieron nuevo, se crea. Si enviaron el nombre existente, se busca)
        $rolInput = trim($request->ID_ROL);
        $roleObj = \App\Models\Role::find($rolInput);
        if (!$roleObj) {
            $rolName = mb_strtoupper($rolInput, 'UTF-8');
            $roleObj = \App\Models\Role::firstOrCreate(['NOMBRE_ROL' => $rolName]);
        }
        $user->ID_ROL = $roleObj->ID_ROL;
        $user->NIVEL_ACCESO = $request->NIVEL_ACCESO;
        $user->ESTATUS = $request->ESTATUS;
        // Guardar frentes como CSV (igual que PERMISOS). NULL si usuario GLOBAL sin frente asignado.
        $frentesSeleccionados = $request->input('ID_FRENTE_ASIGNADO', []);
        $user->setAttribute('ID_FRENTE_ASIGNADO', !empty($frentesSeleccionados) ? implode(',', $frentesSeleccionados) : null);
        $user->PERMISOS = $request->PERMISOS;
        $user->REQUIERE_CAMBIO_CLAVE = 1;
        $user->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente.',
                'redirect' => route('usuarios.create')
            ]);
        }

        return redirect()->route('usuarios.create')->with('success', 'Usuario creado correctamente.');
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $user = Usuario::findOrFail($id);
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->get();
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        $available_permissions = [
            'user.create'       => 'Registrar Usuarios',
            'user.edit'         => 'Actualizar Información',
            'user.delete'       => 'Eliminar Usuarios',
            'equipos.create'    => 'Registrar Equipos',
            'equipos.edit'      => 'Actualizar Equipos',
            'equipos.assign'    => 'Asignar Equipos',
            'super.admin'       => 'Acceso Total (Super Admin)',
        ];

        return view('admin.usuarios.formulario', compact('user', 'roles', 'frentes', 'available_permissions'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = Usuario::findOrFail($id);

        $validated = $request->validate([
            'NOMBRE_COMPLETO' => 'required|string|max:150',

            'CORREO_ELECTRONICO' => [
                'required',
                'email',
                Rule::unique('usuarios', 'CORREO_ELECTRONICO')->ignore($user->ID_USUARIO, 'ID_USUARIO'),
                'regex:/^.+@cvidalsa27\.com$/i'
            ],
            'password' => 'nullable|string|min:6',
            'ID_ROL' => 'required|string|max:150',
            'ID_FRENTE_ASIGNADO' => 'nullable|array',
            'ID_FRENTE_ASIGNADO.*' => 'exists:frentes_trabajo,ID_FRENTE',
            'NIVEL_ACCESO' => 'required|integer|in:1,2',
            'ESTATUS' => 'required|in:ACTIVO,INACTIVO',
            'PERMISOS' => 'required|array',
            'PERMISOS.*' => 'in:user.create,user.edit,user.delete,equipos.create,equipos.edit,equipos.assign,super.admin',
        ], [
            'NOMBRE_COMPLETO.required' => 'El nombre completo es obligatorio.',
            'CORREO_ELECTRONICO.required' => 'El correo electrónico es obligatorio.',
            'CORREO_ELECTRONICO.email' => 'El formato del correo electrónico no es válido.',
            'CORREO_ELECTRONICO.unique' => 'Este correo electrónico ya está registrado en el sistema.',
            'CORREO_ELECTRONICO.regex' => 'Solo se permiten correos con el dominio @cvidalsa27.com',
            'password.min' => 'La clave de acceso debe tener al menos 6 caracteres.',
            'ID_ROL.required' => 'Debes asignar un rol al usuario.',
            'ID_ROL.max' => 'El rol no puede tener más de 150 caracteres.',
            'ID_FRENTE_ASIGNADO.required' => 'Debes asignar al menos un frente de trabajo.',
            'ID_FRENTE_ASIGNADO.min' => 'Debes asignar al menos un frente de trabajo.',
            'NIVEL_ACCESO.required' => 'El nivel de acceso es obligatorio.',
            'NIVEL_ACCESO.in' => 'El nivel de acceso seleccionado no es válido.',
            'ESTATUS.required' => 'El estatus es obligatorio.',
            'ESTATUS.in' => 'El estatus seleccionado no es válido.',
            'PERMISOS.required' => 'Debes seleccionar al menos un permiso.',
        ]);

        // Update user attributes
        $user->NOMBRE_COMPLETO = mb_convert_case($request->NOMBRE_COMPLETO, MB_CASE_TITLE, 'UTF-8');

        $user->CORREO_ELECTRONICO = strtolower($request->CORREO_ELECTRONICO);
        // Resolver el Rol (si lo escribieron nuevo, se crea. Si enviaron el nombre existente, se busca)
        $rolInput = trim($request->ID_ROL);
        $roleObj = \App\Models\Role::find($rolInput);
        if (!$roleObj) {
            $rolName = mb_strtoupper($rolInput, 'UTF-8');
            $roleObj = \App\Models\Role::firstOrCreate(['NOMBRE_ROL' => $rolName]);
        }
        $user->ID_ROL = $roleObj->ID_ROL;
        $user->NIVEL_ACCESO = $request->NIVEL_ACCESO;
        $user->ESTATUS = $request->ESTATUS;
        // Guardar frentes como CSV (igual que PERMISOS). NULL si usuario GLOBAL sin frente asignado.
        $frentesSeleccionados = $request->input('ID_FRENTE_ASIGNADO', []);
        $user->setAttribute('ID_FRENTE_ASIGNADO', !empty($frentesSeleccionados) ? implode(',', $frentesSeleccionados) : null);
        $user->PERMISOS = $request->PERMISOS;

        if ($request->filled('password')) {
            $user->PASSWORD_HASH = Hash::make($request->password);
        }

        $user->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'redirect' => route('usuarios.index')
            ]);
        }

        return redirect()->route('usuarios.index')->with('success', 'Usuario actualizado correctamente.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Usuario::findOrFail($id);
        $user->delete();

        return redirect()->route('usuarios.index')->with('success', 'Usuario eliminado correctamente.');
    }
}
