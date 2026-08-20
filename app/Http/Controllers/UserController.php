<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Role;
use App\Models\FrenteTrabajo;
use App\Models\BloqueoIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Requiere SOLO la clave 'super.admin' en PERMISOS (el rol ya no importa)
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
        // Rehashea y revoca la sesión (ver Usuario::establecerClave): en el request
        // siguiente hay que volver a entrar con la clave nueva.
        $user->establecerClave($request->password);
        $user->REQUIERE_CAMBIO_CLAVE = 0;
        $user->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => '¡Contraseña actualizada correctamente!'
            ]);
        }

        return back()->with('success_perfil', '¡Contraseña actualizada correctamente!');
    }


    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Start with base query
        $query = Usuario::select('ID_USUARIO', 'NOMBRE_COMPLETO', 'CORREO_ELECTRONICO', 'ID_ROL', 'ID_FRENTE_ASIGNADO', 'NIVEL_ACCESO_EQUIPOS', 'NIVEL_ACCESO_ALMACEN', 'ESTATUS', 'created_at')
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

        // FILTER 3: Fecha de creación del usuario (created_at) — un día exacto.
        if ($request->filled('fecha_creacion')) {
            $query->whereDate('created_at', $request->input('fecha_creacion'));
        }

        // FILTER 4: Rol del usuario (independent)
        if ($request->filled('id_rol') && $request->input('id_rol') !== 'all') {
            $query->where('ID_ROL', $request->input('id_rol'));
        }

        // Execute query with pagination (15 por página).
        $users = $query->paginate(15)->onEachSide(3)->withQueryString();

        // Frentes for dropdown
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->get();

        // Roles for dropdown
        // Solo roles con al menos un usuario (mismo criterio que el form): filtrar por un
        // rol sin usuarios no tendría sentido (devolvería vacío) y ensuciaría la lista.
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->has('usuarios')->orderBy('NOMBRE_ROL')->get();

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

        // Lista para el autocompletado del buscador (nombre / correo). Se inyecta en la
        // vista como JSON y el filtrado de sugerencias ocurre en el cliente al escribir.
        $usuariosSugerencias = Usuario::select('NOMBRE_COMPLETO', 'CORREO_ELECTRONICO')
            ->orderBy('NOMBRE_COMPLETO')
            ->get()
            ->map(fn ($u) => [
                'nombre' => $u->NOMBRE_COMPLETO,
                'correo' => $u->CORREO_ELECTRONICO,
            ])
            ->values();

        // Usuarios con sesión activa (últimos 30 min) — misma fuente que el módulo de
        // Auditoría (Usuario::sesionesActivas), para el panel lateral. No duplica lógica.
        $activeUsers = \App\Models\Usuario::sesionesActivas(30);

        // IPs efectivamente bloqueadas — mismo criterio/umbral que Auditoría vía el scope
        // BloqueoIp::bloqueadas() (fuente única). El panel lateral "IPs Bloqueadas" reutiliza
        // el JS global (unlockIp/filterBlockedIps) ya cargado en todas las páginas.
        $blockedIps = BloqueoIp::bloqueadas()->get();

        return view('admin.usuarios.lista', compact('users', 'frentes', 'roles', 'totalUsuarios', 'usuariosActivos', 'usuariosInactivos', 'usuariosSugerencias', 'activeUsers', 'blockedIps'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Solo roles que YA tiene asignados algún usuario: los huérfanos (roles que
        // quedaron sin nadie, p. ej. creados por firstOrCreate y luego desasignados) no
        // ensucian las sugerencias. Para asignar un rol nuevo se escribe el nombre y
        // firstOrCreate lo resuelve/crea al guardar.
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->has('usuarios')->get();
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        $available_permissions = self::availablePermissions();

        return view('admin.usuarios.formulario', compact('roles', 'frentes', 'available_permissions'));
    }

    /**
     * Catálogo de claves de permiso disponibles (key => etiqueta).
     * Fuente única — usada por create()/edit() y por UserRequest para validar PERMISOS.*.
     */
    public static function availablePermissions(): array
    {
        return [
            // SIN 'user.create'. Era una casilla que prometia "Registrar Usuarios" y no
            // concedia nada: crear usuarios lo protege can:manage.users (ver el constructor),
            // que se resuelve con la clave super.admin y solo con ella. Estaba marcada en 7
            // usuarios, ninguno de ellos super.admin, asi que ninguno podia crear pese a
            // tenerla. Se quita del catalogo en vez de hacerla efectiva: darle efecto habria
            // dado acceso de creacion a esas 7 cuentas, que es justo lo contrario de lo que
            // esperaba quien las configuro.
            //
            // La clave que quedo guardada en esos usuarios es inerte y se va sola: al guardar
            // se reemplaza PERMISOS entero con las casillas marcadas, y esta ya no se pinta.
            'user.edit'           => 'Actualizar Información',
            // user.delete es EXCLUSIVA (PERMISOS_EXPLICITOS): ni super.admin
            // la hereda. Borra equipos via soft-delete (bulkDelete) y abre
            // las papeleras en /admin/historial-documentos.
            'user.delete'         => 'Eliminar Equipos',
            'equipos.create'      => 'Registrar Equipos',
            'equipos.edit'        => 'Actualizar Equipos',
            'equipos.assign'      => 'Asignar Equipos',
            // La visibilidad de almacenes depende solo de usuarios.NIVEL_ACCESO_ALMACEN
            // (1=GLOBAL ve todo, 2=LOCAL solo sus frentes), no de permisos. Es un nivel
            // independiente del de equipos (NIVEL_ACCESO_EQUIPOS).
            //
            // Modelo de permisos de almacen:
            //   super.admin        → CRUD de almacenes + acceso total al sistema,
            //                        EXCEPTO almacen.productos / almacen.movimiento
            //                        que requieren la clave especifica.
            //   almacen.productos  → CRUD del catalogo de productos.
            //   almacen.movimiento → registrar entradas, salidas, ajustes, traspasos
            //                        y confirmar recepcion de traspasos en el destino.
            //   almacen.nota.eliminar → eliminar Notas de Entrega (revierte el stock)
            //                        y eliminar productos del catalogo. EXCLUSIVA
            //                        (PERMISOS_EXPLICITOS): ni super.admin la hereda.
            //
            // Solo el literal en PERMISOS otorga acceso. No hay alias ni atajos.
            'almacen.productos'  => 'Registrar y editar productos del catálogo',
            'almacen.movimiento' => 'Registrar entradas, salidas, ajustes, traspasos y confirmar recepciones',
            'almacen.nota.eliminar' => 'Eliminar Notas de Entrega y productos del catálogo',
            // Visibilidad del panel "Alertas de Documentos" (menú), POR USUARIO. Regla en
            // DashboardController@generateAlertsList: si el usuario tiene AL MENOS una clave
            // alertas.ver.*, ve SOLO esos documentos; si no tiene ninguna, ve TODOS (default).
            // Ej.: un COORD. SIHO con solo 'alertas.ver.certificado' verá solo certificados.
            'alertas.ver.poliza'      => 'Alertas de documentos: ver Pólizas',
            'alertas.ver.rotc'        => 'Alertas de documentos: ver ROTC',
            'alertas.ver.racda'       => 'Alertas de documentos: ver RACDA',
            'alertas.ver.certificado' => 'Alertas de documentos: ver Certificados',
            'super.admin'         => 'Acceso Total (Super Admin)',
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(\App\Http\Requests\UserRequest $request)
    {
        $validated = $request->validated();

        // Create user with mass assignment for validated data
        $user = new Usuario();
        $user->NOMBRE_COMPLETO = $validated['NOMBRE_COMPLETO'];
        $user->CORREO_ELECTRONICO = $validated['CORREO_ELECTRONICO'];
        $user->PASSWORD_HASH = Hash::make($request->password);
        // Resolver el Rol (si lo escribieron nuevo, se crea. Si enviaron el nombre existente, se busca)
        $rolInput = trim($request->ID_ROL);
        $roleObj = \App\Models\Role::find($rolInput);
        if (!$roleObj) {
            $rolName = mb_strtoupper($rolInput, 'UTF-8');
            $roleObj = \App\Models\Role::firstOrCreate(['NOMBRE_ROL' => $rolName]);
        }
        $user->ID_ROL = $roleObj->ID_ROL;
        // Dos niveles independientes: equipos y almacen. Se asignan explicitamente
        // (no via fill()) porque son campos sensibles fuera de $fillable.
        $user->NIVEL_ACCESO_EQUIPOS = $request->NIVEL_ACCESO_EQUIPOS;
        $user->NIVEL_ACCESO_ALMACEN = $request->NIVEL_ACCESO_ALMACEN;
        $user->ESTATUS = $request->ESTATUS;
        // Guardar frentes como CSV (igual que PERMISOS). NULL si usuario GLOBAL sin frente asignado.
        $frentesSeleccionados = $request->input('ID_FRENTE_ASIGNADO', []);
        $user->setAttribute('ID_FRENTE_ASIGNADO', !empty($frentesSeleccionados) ? implode(',', $frentesSeleccionados) : null);
        // Frentes BLOQUEADOS (lista negra): se restan de la visibilidad incluso al GLOBAL.
        $frentesBloqueados = $request->input('ID_FRENTE_BLOQUEADO', []);
        $user->setAttribute('ID_FRENTE_BLOQUEADO', !empty($frentesBloqueados) ? implode(',', $frentesBloqueados) : null);
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
        // Solo roles que YA tiene asignados algún usuario: los huérfanos (roles que
        // quedaron sin nadie, p. ej. creados por firstOrCreate y luego desasignados) no
        // ensucian las sugerencias. Para asignar un rol nuevo se escribe el nombre y
        // firstOrCreate lo resuelve/crea al guardar.
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->has('usuarios')->get();
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        $available_permissions = self::availablePermissions();

        return view('admin.usuarios.formulario', compact('user', 'roles', 'frentes', 'available_permissions'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(\App\Http\Requests\UserRequest $request, string $id)
    {
        $user = Usuario::findOrFail($id);
        $validated = $request->validated();

        // Update user attributes
        $user->NOMBRE_COMPLETO = $validated['NOMBRE_COMPLETO'];
        $user->CORREO_ELECTRONICO = $validated['CORREO_ELECTRONICO'];
        // Resolver el Rol (si lo escribieron nuevo, se crea. Si enviaron el nombre existente, se busca)
        $rolInput = trim($request->ID_ROL);
        $roleObj = \App\Models\Role::find($rolInput);
        if (!$roleObj) {
            $rolName = mb_strtoupper($rolInput, 'UTF-8');
            $roleObj = \App\Models\Role::firstOrCreate(['NOMBRE_ROL' => $rolName]);
        }
        $user->ID_ROL = $roleObj->ID_ROL;
        // Dos niveles independientes: equipos y almacen. Se asignan explicitamente
        // (no via fill()) porque son campos sensibles fuera de $fillable.
        $user->NIVEL_ACCESO_EQUIPOS = $request->NIVEL_ACCESO_EQUIPOS;
        $user->NIVEL_ACCESO_ALMACEN = $request->NIVEL_ACCESO_ALMACEN;
        $user->ESTATUS = $request->ESTATUS;
        // Guardar frentes como CSV (igual que PERMISOS). NULL si usuario GLOBAL sin frente asignado.
        $frentesSeleccionados = $request->input('ID_FRENTE_ASIGNADO', []);
        $user->setAttribute('ID_FRENTE_ASIGNADO', !empty($frentesSeleccionados) ? implode(',', $frentesSeleccionados) : null);
        // Frentes BLOQUEADOS (lista negra): se restan de la visibilidad incluso al GLOBAL.
        $frentesBloqueados = $request->input('ID_FRENTE_BLOQUEADO', []);
        $user->setAttribute('ID_FRENTE_BLOQUEADO', !empty($frentesBloqueados) ? implode(',', $frentesBloqueados) : null);
        $user->PERMISOS = $request->PERMISOS;

        if ($request->filled('password')) {
            // Un admin cambiando la clave de otro TIENE que echarlo de su sesión: si no,
            // seguía navegando con una clave que ya no existe. REQUIERE_CAMBIO_CLAVE no se
            // toca aquí a propósito — lo decide el admin en el formulario.
            $user->establecerClave($request->password);
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

    /**
     * Devuelve la lista de roles que no tienen ningún usuario asignado.
     */
    public function getUnusedRoles()
    {
        $unusedRoles = Role::doesntHave('usuarios')->get(['ID_ROL', 'NOMBRE_ROL']);
        return response()->json($unusedRoles);
    }

    /**
     * Elimina todos los roles que no tienen ningún usuario asignado.
     */
    public function deleteUnusedRoles()
    {
        $deleted = Role::doesntHave('usuarios')->delete();
        return response()->json([
            'success' => true,
            'message' => "Se han eliminado {$deleted} roles sin uso correctamente."
        ]);
    }
}
