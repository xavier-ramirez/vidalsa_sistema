<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Role;
use App\Models\FrenteTrabajo;
use App\Models\BloqueoIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Requiere SOLO la clave 'super.admin' en PERMISOS (el rol ya no importa)
        $this->middleware('can:manage.users')->only(['index', 'create', 'store', 'edit', 'update', 'destroy', 'getUnusedRoles', 'deleteUnusedRoles']);
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
        // Rehashea y revoca las sesiones (ver Usuario::establecerClave); ESTA sigue abierta
        // con el token nuevo: "se cierra la sesión en los demás dispositivos".
        $user->establecerClave($request->password);
        $user->REQUIERE_CAMBIO_CLAVE = 0;
        $user->save();
        $this->seguirEnEsteEquipo($request, $user);

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
            // OJO: aqui NO va 'frenteAsignado'. Esa relacion NO es eager-loadable (lo
            // explica su docblock en Usuario): en un eager load Laravel la construye sobre
            // una instancia VACIA, asi que su whereKey() queda en null y devuelve null para
            // todos. La columna "Frente" salia "Global" en todas las filas, justo en la
            // pantalla donde se administran los permisos. El nombre lo saca la vista de
            // getNombresFrentesAsignados(), que es la fuente canonica y cachea el mapa
            // id->nombre una vez por proceso (no hay N+1).
            ->with(['rol:ID_ROL,NOMBRE_ROL']);

        // FILTER 1: nombre o correo. % y _ se buscan como texto, no como comodines.
        if ($request->filled('search')) {
            $like = '%' . addcslashes(trim($request->input('search')), '%_\\') . '%';
            $query->where(function($q) use ($like) {
                $q->where('NOMBRE_COMPLETO', 'like', $like)
                  ->orWhere('CORREO_ELECTRONICO', 'like', $like);
            });
        }

        // FILTER 2: Frente de Trabajo. ID_FRENTE_ASIGNADO es CSV ("3,7"): con un igual, los
        // usuarios de varios frentes no salian por ninguno de ellos.
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $query->whereRaw('FIND_IN_SET(?, ID_FRENTE_ASIGNADO)', [$request->input('id_frente')]);
        }

        // FILTER 3: Rol del usuario
        if ($request->filled('id_rol') && $request->input('id_rol') !== 'all') {
            $query->where('ID_ROL', $request->input('id_rol'));
        }

        // FILTER 4: nivel de acceso (Filtros avanzados) = eje-valor: "eq-1" = EQ GLOBAL,
        // "alm-2" = ALM LOCAL (Usuario::NIVELES_ACCESO). Otro valor se ignora en vez de
        // devolver una tabla vacía.
        if (preg_match('/^(eq|alm)-(\d+)$/', (string) $request->input('nivel'), $m)
            && isset(Usuario::NIVELES_ACCESO[(int) $m[2]])) {
            $query->where($m[1] === 'eq' ? 'NIVEL_ACCESO_EQUIPOS' : 'NIVEL_ACCESO_ALMACEN', (int) $m[2]);
        }

        // Execute query with pagination (15 por página). Si la página pedida ya no existe
        // (se borró el último usuario de la última página, o el filtro deja menos), se
        // muestra la última que sí hay en vez de una tabla vacía.
        $users = $query->paginate(15)->onEachSide(3)->withQueryString();
        if ($users->isEmpty() && $users->currentPage() > 1) {
            $users = $query->paginate(15, ['*'], 'page', $users->lastPage())->onEachSide(3)->withQueryString();
        }

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

        // Equipos e IPs bloqueados por claves incorrectas, vía el scope BloqueoIp::bloqueadas()
        // (fuente única). El panel lateral "Equipos e IPs bloqueados" reutiliza
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
            // OJO con la clave que quedo GUARDADA en esos usuarios: no es inerte del todo.
            // Gate::before concede cualquier clave que este literalmente en PERMISOS, asi que
            // hoy $user->can('user.create') devuelve TRUE para ellos. Es inofensivo porque no
            // queda ni un solo sitio que lo pregunte —lo vigila la comprobacion 48 de
            // tools/pruebas_login.php—, pero si alguien vuelve a usar ESE nombre para otra
            // cosa, esos usuarios lo heredarian sin que nadie se lo conceda. Si se reutiliza
            // el nombre, limpiar antes la clave de la base.
            //
            // Se va sola de cada usuario la primera vez que se le guarde: al guardar se
            // reemplaza PERMISOS entero con las casillas marcadas, y esta ya no se pinta.
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
            //   almacen.nota.corregir → corregir la cantidad de un producto en una Nota ya
            //                        emitida (cambia la salida y el stock; queda el rastro).
            //                        EXCLUSIVA, igual que la de eliminar.
            //
            // Solo el literal en PERMISOS otorga acceso. No hay alias ni atajos.
            'almacen.productos'  => 'Registrar y editar productos del catálogo',
            'almacen.movimiento' => 'Registrar entradas, salidas, ajustes, traspasos y confirmar recepciones',
            'almacen.nota.eliminar' => 'Eliminar notas y productos',
            'almacen.nota.corregir' => 'Corregir cantidades de una Nota de Entrega',
            // Visibilidad del panel "Alertas de Documentos" (menú), POR USUARIO. Regla en
            // DashboardController@generateAlertsList: si el usuario tiene AL MENOS una clave
            // alertas.ver.*, ve SOLO esos documentos; si no tiene ninguna, ve TODOS (default).
            // Ej.: un COORD. SIHO con solo 'alertas.ver.certificado' verá solo certificados.
            'alertas.ver.poliza'      => 'Alertas de documentos: ver Pólizas',
            'alertas.ver.rotc'        => 'Alertas de documentos: ver ROTC',
            'alertas.ver.racda'       => 'Alertas de documentos: ver RACDA',
            'alertas.ver.certificado' => 'Alertas de documentos: ver Certificados',
            // Carga masiva de documentos (Control de Auditoria). EXCLUSIVA
            // (PERMISOS_EXPLICITOS): ni super.admin la hereda. Sube PDF en lote y los
            // engancha SOLA a la ficha que reconoce, asi que quien la tenga puede tocar la
            // documentacion de cualquier equipo o auxiliar de un tiron: se concede a dedo.
            'docs.carga.masiva'   => 'Carga masiva de documentos',
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
        $this->asignarAccesos($user, $request);
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
    public function edit(Request $request, string $id)
    {
        $user = Usuario::findOrFail($id);
        // Listado al que volver (con sus filtros y página) al Cancelar/Guardar. Lo manda el
        // lápiz de la tabla como ?return=…; se sanea para evitar open-redirect.
        $returnUrl = self::usuariosReturnUrl($request->query('return'));
        // Solo roles que YA tiene asignados algún usuario: los huérfanos (roles que
        // quedaron sin nadie, p. ej. creados por firstOrCreate y luego desasignados) no
        // ensucian las sugerencias. Para asignar un rol nuevo se escribe el nombre y
        // firstOrCreate lo resuelve/crea al guardar.
        $roles = Role::select('ID_ROL', 'NOMBRE_ROL')->has('usuarios')->get();
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->select('ID_FRENTE', 'NOMBRE_FRENTE')->get();
        $available_permissions = self::availablePermissions();

        return view('admin.usuarios.formulario', compact('user', 'roles', 'frentes', 'available_permissions', 'returnUrl'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(\App\Http\Requests\UserRequest $request, string $id)
    {
        $user = Usuario::findOrFail($id);
        $validated = $request->validated();
        $esElMismo = (int) $user->ID_USUARIO === (int) auth()->id();

        // Quien edita su propia cuenta no puede quitarse el Acceso Total ni desactivarse: se
        // quedaría fuera de este módulo sin nadie que lo pueda deshacer desde la pantalla.
        if ($esElMismo && $request->ESTATUS === 'INACTIVO') {
            throw ValidationException::withMessages(['ESTATUS' => 'No puedes desactivar tu propio usuario.']);
        }
        if ($esElMismo && ! in_array('super.admin', (array) $request->PERMISOS, true)) {
            throw ValidationException::withMessages(['PERMISOS' => 'No puedes quitarte el Acceso Total (Super Admin).']);
        }

        // Update user attributes
        $user->NOMBRE_COMPLETO = $validated['NOMBRE_COMPLETO'];
        $user->CORREO_ELECTRONICO = $validated['CORREO_ELECTRONICO'];
        $this->asignarAccesos($user, $request);

        if ($request->filled('password')) {
            // Un admin cambiando la clave de otro TIENE que echarlo de su sesión: si no,
            // seguía navegando con una clave que ya no existe. Y como al crearlo, al entrar
            // con esa clave se le pide cambiarla (la sabe el admin). La suya propia, no.
            $user->establecerClave($request->password);
            if (! $esElMismo) {
                $user->REQUIERE_CAMBIO_CLAVE = 1;
            }
        }

        $user->save();
        if ($esElMismo && $request->filled('password')) {
            $this->seguirEnEsteEquipo($request, $user);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'redirect' => self::usuariosReturnUrl($request->input('return_url'))
            ]);
        }

        return redirect()->to(self::usuariosReturnUrl($request->input('return_url')))->with('success', 'Usuario actualizado correctamente.');
    }

    /**
     * Tras cambiar la clave propia: esta sesión toma el token nuevo y sigue abierta; las demás
     * (otros equipos) traen el viejo y ValidarSesionUnica las cierra.
     */
    private function seguirEnEsteEquipo(Request $request, Usuario $user): void
    {
        $request->session()->put('current_session_token', $user->tokenDeSesion());
    }

    /**
     * URL del listado de usuarios a la que volver, con sus filtros. Solo se acepta una ruta
     * interna a /admin/usuarios (sin host): cualquier otra cosa cae en el listado sin filtros.
     */
    private static function usuariosReturnUrl(?string $candidata): string
    {
        $porDefecto = route('usuarios.index');
        if (! $candidata || parse_url($candidata, PHP_URL_HOST) !== null || str_starts_with($candidata, '//')) {
            return $porDefecto;
        }
        if ((parse_url($candidata, PHP_URL_PATH) ?: '') !== '/admin/usuarios') {
            return $porDefecto;
        }
        $query = parse_url($candidata, PHP_URL_QUERY);
        return $query ? '/admin/usuarios?' . $query : '/admin/usuarios';
    }

    /**
     * Lo que store() y update() guardan igual: rol, niveles de acceso, estatus, frentes y permisos.
     */
    private function asignarAccesos(Usuario $user, \App\Http\Requests\UserRequest $request): void
    {
        // Rol: si lo escribieron nuevo, se crea; si enviaron uno existente (id o nombre), se usa.
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
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = Usuario::findOrFail($id);
        if ((int) $user->ID_USUARIO === (int) auth()->id()) {
            return response()->json(['success' => false, 'message' => 'No puedes eliminar tu propio usuario.'], 422);
        }
        $user->delete();

        // La tabla lo pide por AJAX (usrEliminar) y se recarga ella misma con sus filtros.
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Usuario eliminado correctamente.']);
        }

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
