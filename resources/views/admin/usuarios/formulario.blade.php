@extends('layouts.estructura_base')

@section('title', isset($user) ? 'Editar Usuario' : 'Nuevo Usuario')

@section('content')
<section class="page-title-card" style="text-align: center; margin: 0 auto 16px auto; padding: 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">{{ isset($user) ? 'Edición de Usuario' : 'Registro de Usuario' }}</span>
    </h1>
</section>

<div class="admin-card" style="max-width: 800px; margin: 0 auto;">
    <form id="userForm" action="{{ isset($user) ? route('usuarios.update', $user->ID_USUARIO) : route('usuarios.store') }}" method="POST">
        @csrf
        @if(isset($user))
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="NOMBRE_COMPLETO" class="form-label">Nombre Completo</label>
                <input type="text" id="NOMBRE_COMPLETO" name="NOMBRE_COMPLETO" class="form-input-custom @error('NOMBRE_COMPLETO') is-invalid @enderror" value="{{ old('NOMBRE_COMPLETO', $user->NOMBRE_COMPLETO ?? '') }}" required autocomplete="off">
                @error('NOMBRE_COMPLETO')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>


            <div>
                <label for="CORREO_ELECTRONICO" class="form-label">Email Corporativo</label>
                <input type="email" id="CORREO_ELECTRONICO" name="CORREO_ELECTRONICO" class="form-input-custom @error('CORREO_ELECTRONICO') is-invalid @enderror" value="{{ old('CORREO_ELECTRONICO', $user->CORREO_ELECTRONICO ?? '') }}" required autocomplete="off" style="text-transform: lowercase;">
                @error('CORREO_ELECTRONICO')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <span id="lbl_usuario_estatus_title" class="form-label">Estatus de Cuenta</span>
                <div class="custom-dropdown" id="statusSelect">
                    <input type="hidden" name="ESTATUS" id="input_estatus" value="{{ old('ESTATUS', $user->ESTATUS ?? 'ACTIVO') }}" aria-label="Estatus de Cuenta">
                    <div class="dropdown-trigger" id="trigger_estatus" onclick="toggleDropdown('statusSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_estatus_title label_estatus" style="cursor: default;">
                        <span id="label_estatus">{{ old('ESTATUS', $user->ESTATUS ?? 'ACTIVO') }}</span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="dropdown-content">
                        <div class="dropdown-item {{ old('ESTATUS', $user->ESTATUS ?? 'ACTIVO') == 'ACTIVO' ? 'selected' : '' }}" onclick="selectOption('statusSelect', 'ACTIVO', 'ACTIVO', 'estatus')">ACTIVO</div>
                        <div class="dropdown-item {{ old('ESTATUS', $user->ESTATUS ?? 'ACTIVO') == 'INACTIVO' ? 'selected' : '' }}" onclick="selectOption('statusSelect', 'INACTIVO', 'INACTIVO', 'estatus')">INACTIVO</div>
                    </div>
                </div>
                @error('ESTATUS')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>


            <div>
                <label for="password" class="form-label">Clave de Acceso</label>
                <input type="password" id="password" name="password" class="form-input-custom @error('password') is-invalid @enderror" {{ isset($user) ? '' : 'required' }} placeholder="{{ isset($user) ? 'Dejar en blanco para mantener la actual' : '' }}" autocomplete="new-password">
                @error('password')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
                @if(isset($user))
                    <small style="color: var(--maquinaria-gray-text); font-size: 12px; display: block; margin-top: 5px;">
                        Dejar vacío si no desea cambiar la contraseña
                    </small>
                @endif
            </div>

            <div>
                <label for="ID_ROL" class="form-label">Rol Asignado</label>
                <div class="custom-dropdown" id="roleSelect">
                    @php 
                        $oldId = old('ID_ROL', $user->ID_ROL ?? '');
                        $currentRol = $roles->firstWhere('ID_ROL', $oldId);
                        $rolValue = $currentRol ? $currentRol->NOMBRE_ROL : $oldId;
                    @endphp
                    <div class="dropdown-trigger" style="cursor: text; padding: 0; display: flex; align-items: center;" onclick="if(!document.getElementById('roleSelect').classList.contains('active')) toggleDropdown('roleSelect', event)">
                        <input type="text" name="ID_ROL" id="ID_ROL" 
                               value="{{ $rolValue }}" 
                               placeholder="Seleccione o escriba un rol..." 
                               required autocomplete="off" 
                               style="flex: 1; border: none; background: transparent; padding: 12px 15px; outline: none; color: var(--maquinaria-text); font-size: 14px; font-family: inherit; text-transform: uppercase;"
                               oninput="const val = this.value.toLowerCase().trim(); document.querySelectorAll('.role-item-opt').forEach(i => i.style.display = i.textContent.toLowerCase().includes(val) ? 'block' : 'none');"
                               onfocus="document.getElementById('roleSelect').classList.add('active');"
                               onclick="event.stopPropagation();">
                        <i class="material-icons" style="padding-right: 15px; cursor: pointer; color: var(--maquinaria-gray-text);">expand_more</i>
                    </div>
                    <div class="dropdown-content" id="rolesListContainer">
                        @foreach($roles as $rol)
                            <div class="dropdown-item role-item-opt" 
                                 onclick="document.getElementById('ID_ROL').value = '{{ $rol->NOMBRE_ROL }}'; document.getElementById('roleSelect').classList.remove('active');">
                                {{ $rol->NOMBRE_ROL }}
                            </div>
                        @endforeach
                    </div>
                </div>
                @error('ID_ROL')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>

            {{-- Nivel de acceso: son DOS ejes INDEPENDIENTES, no una sola lista de 4 opciones.
                 Un solo select no podría expresar "global en equipos + local en almacén", que es
                 el caso que motiva la separación (p.ej. un almacenista que ve toda la flota pero
                 solo el stock de su frente). En ambos: 1 = GLOBAL, 2 = LOCAL.

                 La lista de frentes asignados (abajo) es COMPARTIDA: define "cuáles frentes son
                 míos"; cada nivel decide si ese módulo ve los de todos o solo los míos. Por eso
                 un usuario GLOBAL en los dos ejes ignora la lista, y uno LOCAL sin frentes no ve
                 nada en el módulo correspondiente. --}}
            <div>
                <span id="lbl_usuario_nivel_eq_title" class="form-label">Nivel de Acceso — Equipos</span>
                <div class="custom-dropdown" id="levelSelectEquipos" data-filter-type="nivel_equipos">
                    <input type="hidden" name="NIVEL_ACCESO_EQUIPOS" id="input_nivel_equipos" data-filter-value
                           value="{{ old('NIVEL_ACCESO_EQUIPOS', $user->NIVEL_ACCESO_EQUIPOS ?? '') }}" aria-label="Nivel de Acceso a Equipos">
                    <div class="dropdown-trigger" id="trigger_nivel_equipos" onclick="toggleDropdown('levelSelectEquipos', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_nivel_eq_title label_nivel_equipos" style="cursor: default;">
                        <span id="label_nivel_equipos" data-filter-label>
                            @if(old('NIVEL_ACCESO_EQUIPOS', $user->NIVEL_ACCESO_EQUIPOS ?? '') == 1)
                                GLOBAL - TODOS LOS FRENTES
                            @elseif(old('NIVEL_ACCESO_EQUIPOS', $user->NIVEL_ACCESO_EQUIPOS ?? '') == 2)
                                LOCAL - SOLO SUS FRENTES
                            @else
                                Seleccione nivel de acceso...
                            @endif
                        </span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="dropdown-content">
                        <div class="dropdown-item {{ old('NIVEL_ACCESO_EQUIPOS', $user->NIVEL_ACCESO_EQUIPOS ?? '') == 1 ? 'selected' : '' }}" onclick="selectOption('levelSelectEquipos', '1', 'GLOBAL - TODOS LOS FRENTES', 'nivel_equipos')">
                            GLOBAL - TODOS LOS FRENTES
                        </div>
                        <div class="dropdown-item {{ old('NIVEL_ACCESO_EQUIPOS', $user->NIVEL_ACCESO_EQUIPOS ?? '') == 2 ? 'selected' : '' }}" onclick="selectOption('levelSelectEquipos', '2', 'LOCAL - SOLO SUS FRENTES', 'nivel_equipos')">
                            LOCAL - SOLO SUS FRENTES
                        </div>
                    </div>
                </div>
                @error('NIVEL_ACCESO_EQUIPOS')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <span id="lbl_usuario_nivel_alm_title" class="form-label">Nivel de Acceso — Almacén</span>
                <div class="custom-dropdown" id="levelSelectAlmacen" data-filter-type="nivel_almacen">
                    <input type="hidden" name="NIVEL_ACCESO_ALMACEN" id="input_nivel_almacen" data-filter-value
                           value="{{ old('NIVEL_ACCESO_ALMACEN', $user->NIVEL_ACCESO_ALMACEN ?? '') }}" aria-label="Nivel de Acceso a Almacén">
                    <div class="dropdown-trigger" id="trigger_nivel_almacen" onclick="toggleDropdown('levelSelectAlmacen', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_nivel_alm_title label_nivel_almacen" style="cursor: default;">
                        <span id="label_nivel_almacen" data-filter-label>
                            @if(old('NIVEL_ACCESO_ALMACEN', $user->NIVEL_ACCESO_ALMACEN ?? '') == 1)
                                GLOBAL - TODOS LOS ALMACENES
                            @elseif(old('NIVEL_ACCESO_ALMACEN', $user->NIVEL_ACCESO_ALMACEN ?? '') == 2)
                                LOCAL - SOLO SUS ALMACENES
                            @else
                                Seleccione nivel de acceso...
                            @endif
                        </span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="dropdown-content">
                        <div class="dropdown-item {{ old('NIVEL_ACCESO_ALMACEN', $user->NIVEL_ACCESO_ALMACEN ?? '') == 1 ? 'selected' : '' }}" onclick="selectOption('levelSelectAlmacen', '1', 'GLOBAL - TODOS LOS ALMACENES', 'nivel_almacen')">
                            GLOBAL - TODOS LOS ALMACENES
                        </div>
                        <div class="dropdown-item {{ old('NIVEL_ACCESO_ALMACEN', $user->NIVEL_ACCESO_ALMACEN ?? '') == 2 ? 'selected' : '' }}" onclick="selectOption('levelSelectAlmacen', '2', 'LOCAL - SOLO SUS ALMACENES', 'nivel_almacen')">
                            LOCAL - SOLO SUS ALMACENES
                        </div>
                    </div>
                </div>
                @error('NIVEL_ACCESO_ALMACEN')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <span id="lbl_usuario_frente_title" class="form-label">Frentes Asignados</span>

                {{-- Trigger con input de busqueda inline (mismo patron que Rol Asignado):
                     - El usuario tipea y filtra .frente-item-opt en vivo.
                     - Los frentes seleccionados se muestran como chips dentro del mismo
                       trigger (a la izquierda del input).
                     - Antes habia un buscador SEPARADO dentro del dropdown — el cliente
                       lo encontro innecesario; ahora el filtrado vive en el campo
                       principal y la lista solo muestra los items. --}}
                <div class="custom-multiselect" id="frentesSelect">
                    <div class="multiselect-trigger" id="frentesMultiselectTrigger"
                         style="cursor: text; padding: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 4px;"
                         onclick="toggleDropdown('frentesSelect', event)"
                         tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_frente_title">
                        <span id="frentesSelectedCount" style="display:flex;flex-wrap:wrap;gap:4px;padding-left:8px;"></span>
                        <input type="text" id="frentesSearchInput"
                               placeholder="Seleccione o escriba frentes..."
                               autocomplete="off"
                               style="flex: 1; min-width: 120px; border: none; background: transparent; padding: 12px 8px; outline: none; color: var(--maquinaria-text); font-size: 14px; font-family: inherit;"
                               oninput="const val = this.value.toLowerCase().trim(); document.querySelectorAll('.frente-item-opt').forEach(i => i.style.display = i.textContent.toLowerCase().includes(val) ? '' : 'none');"
                               onfocus="document.getElementById('frentesSelect').classList.add('active');"
                               onclick="event.stopPropagation();">
                        <i class="material-icons" style="padding-right: 15px; color: var(--maquinaria-gray-text);">expand_more</i>
                    </div>
                    <div class="multiselect-content" id="frentesMultiselectContent">
                        @php
                            $rawFrente = old('ID_FRENTE_ASIGNADO', isset($user) ? $user->getRawOriginal('ID_FRENTE_ASIGNADO') : '');
                            $selectedFrentes = is_array($rawFrente)
                                ? $rawFrente
                                : array_filter(array_map('trim', explode(',', $rawFrente ?? '')));
                        @endphp
                        @foreach($frentes as $frente)
                            <label class="multiselect-item frente-item-opt" for="frente_{{ $frente->ID_FRENTE }}">
                                <input type="checkbox"
                                    id="frente_{{ $frente->ID_FRENTE }}"
                                    name="ID_FRENTE_ASIGNADO[]"
                                    value="{{ $frente->ID_FRENTE }}"
                                    {{ in_array((string)$frente->ID_FRENTE, array_map('strval', (array)$selectedFrentes)) ? 'checked' : '' }}
                                    onchange="updateFrentesCount()">
                                <span>{{ $frente->NOMBRE_FRENTE }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @error('ID_FRENTE_ASIGNADO')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
                <small style="color: var(--maquinaria-gray-text); font-size: 12px; display: block; margin-top: 5px;">
                    Frentes asignados al usuario
                </small>
            </div>

            <div>
                <span id="lbl_usuario_frente_bloq_title" class="form-label">Frentes Bloqueados</span>

                {{-- Lista NEGRA: frentes que este usuario NO debe ver, independiente de
                     GLOBAL/LOCAL. Pensado para "ve casi todo salvo unos pocos": dejar al
                     usuario GLOBAL y tildar aqui solo los frentes prohibidos (mas comodo
                     que hacerlo LOCAL y tildar muchos asignados). Se RESTA en todo el
                     sistema (equipos, almacen, dashboard, historial, movilizacion). --}}
                <div class="custom-multiselect" id="frentesBloqueadosSelect">
                    <div class="multiselect-trigger" id="frentesBloqueadosMultiselectTrigger"
                         style="cursor: text; padding: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 4px;"
                         onclick="toggleDropdown('frentesBloqueadosSelect', event)"
                         tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_frente_bloq_title">
                        <span id="frentesBloqueadosSelectedCount" style="display:flex;flex-wrap:wrap;gap:4px;padding-left:8px;"></span>
                        <input type="text" id="frentesBloqueadosSearchInput"
                               placeholder="Seleccione frentes a ocultar..."
                               autocomplete="off"
                               style="flex: 1; min-width: 120px; border: none; background: transparent; padding: 12px 8px; outline: none; color: var(--maquinaria-text); font-size: 14px; font-family: inherit;"
                               oninput="const val = this.value.toLowerCase().trim(); document.querySelectorAll('.frente-bloq-item-opt').forEach(i => i.style.display = i.textContent.toLowerCase().includes(val) ? '' : 'none');"
                               onfocus="document.getElementById('frentesBloqueadosSelect').classList.add('active');"
                               onclick="event.stopPropagation();">
                        <i class="material-icons" style="padding-right: 15px; color: var(--maquinaria-gray-text);">expand_more</i>
                    </div>
                    <div class="multiselect-content" id="frentesBloqueadosMultiselectContent">
                        @php
                            $rawFrenteBloq = old('ID_FRENTE_BLOQUEADO', isset($user) ? $user->getRawOriginal('ID_FRENTE_BLOQUEADO') : '');
                            $selectedFrentesBloq = is_array($rawFrenteBloq)
                                ? $rawFrenteBloq
                                : array_filter(array_map('trim', explode(',', $rawFrenteBloq ?? '')));
                        @endphp
                        @foreach($frentes as $frente)
                            <label class="multiselect-item frente-bloq-item-opt" for="fbloq_{{ $frente->ID_FRENTE }}">
                                <input type="checkbox"
                                    id="fbloq_{{ $frente->ID_FRENTE }}"
                                    name="ID_FRENTE_BLOQUEADO[]"
                                    value="{{ $frente->ID_FRENTE }}"
                                    {{ in_array((string)$frente->ID_FRENTE, array_map('strval', (array)$selectedFrentesBloq)) ? 'checked' : '' }}
                                    onchange="updateFrentesBloqueadosCount()">
                                <span>{{ $frente->NOMBRE_FRENTE }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @error('ID_FRENTE_BLOQUEADO')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
                <small style="color: var(--maquinaria-gray-text); font-size: 12px; display: block; margin-top: 5px;">
                    Frentes restringidos (ocultos para este usuario)
                </small>
            </div>

            <div>
                <span id="lbl_permisos_title" class="form-label">Permisos de Sección</span>
                
                <div class="custom-multiselect" id="permissionsSelect">
                    <div class="multiselect-trigger" id="multiselectTrigger" onclick="toggleDropdown('permissionsSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_permisos_title selectedCount" style="cursor: default;">
                        <span id="selectedCount">Seleccione permisos...</span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="multiselect-content" id="multiselectContent">
                        @php
                            $user_perms = old('PERMISOS', $user->PERMISOS ?? []);
                            // Agrupar los permisos por MODULO (segun el prefijo de la clave) para que
                            // la lista sea navegable en vez de un bloque plano gigante. Es SOLO
                            // presentacion: availablePermissions() sigue siendo la fuente plana
                            // (validacion intacta) y cada checkbox conserva su name/value = la clave.
                            $gruposDef = [
                                'user'    => 'Usuarios',
                                'equipos' => 'Equipos',
                                'almacen' => 'Almacén / Inventario',
                                'alertas' => 'Alertas de Documentos',
                                'sistema' => 'Sistema',
                            ];
                            $permisosAgrupados = array_fill_keys(array_keys($gruposDef), []);
                            foreach ($available_permissions as $permKey => $permLabel) {
                                $prefijo = explode('.', $permKey)[0]; // user / equipos / almacen / alertas / super…
                                $grupo = array_key_exists($prefijo, $gruposDef) ? $prefijo : 'sistema';
                                $permisosAgrupados[$grupo][$permKey] = $permLabel;
                            }
                            $permIdx = 0;
                        @endphp
                        @foreach($gruposDef as $grupoKey => $grupoLabel)
                            @if(!empty($permisosAgrupados[$grupoKey]))
                                <div style="padding:9px 14px 3px;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;">{{ $grupoLabel }}</div>
                                @foreach($permisosAgrupados[$grupoKey] as $key => $label)
                                    <label class="multiselect-item" for="perm_{{ $permIdx }}">
                                        <input type="checkbox" id="perm_{{ $permIdx }}" name="PERMISOS[]" value="{{ $key }}" {{ in_array($key, $user_perms) ? 'checked' : '' }} onchange="updateSelectedCount()">
                                        <span><strong>{{ $key }}</strong> <span style="color:#64748b; font-size:12px;">— {{ $label }}</span></span>
                                    </label>
                                    @php $permIdx++; @endphp
                                @endforeach
                            @endif
                        @endforeach
                    </div>
                </div>
                @error('PERMISOS')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('usuarios.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria"
                @cannot('manage.users')
                onclick="event.preventDefault(); if(window.showToast) window.showToast('Acceso denegado: Necesitas el permiso super.admin para guardar cambios de usuarios.', 'error');"
                @endcannot>
                <i class="material-icons">save</i>
                {{ isset($user) ? 'Actualizar' : 'Registrar en el Sistema' }}
            </button>
        </div>
    </form>
</div>

@endsection

@section('extra_js')
<script>
    // Restaurar contadores de frentes (asignados + bloqueados) al cargar (modo edición)
    document.addEventListener('DOMContentLoaded', function () {
        if (window.updateFrentesCount) window.updateFrentesCount();
        if (window.updateFrentesBloqueadosCount) window.updateFrentesBloqueadosCount();
    });
</script>
@endsection
