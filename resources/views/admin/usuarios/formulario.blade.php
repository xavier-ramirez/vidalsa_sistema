@extends('layouts.estructura_base')

@section('title', isset($user) ? 'Editar Usuario' : 'Nuevo Usuario')

@section('content')
<section class="page-title-card" style="text-align: center; margin: 0 auto 10px auto;">
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

            <div>
                <span id="lbl_usuario_nivel_title" class="form-label">Nivel de Acceso</span>
                <div class="custom-dropdown" id="levelSelect">
                    <input type="hidden" name="NIVEL_ACCESO" id="input_nivel" value="{{ old('NIVEL_ACCESO', $user->NIVEL_ACCESO ?? '') }}" aria-label="Nivel de Acceso">
                    <div class="dropdown-trigger" id="trigger_nivel" onclick="toggleDropdown('levelSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_nivel_title label_nivel" style="cursor: default;">
                        <span id="label_nivel">
                            @if(old('NIVEL_ACCESO', $user->NIVEL_ACCESO ?? '') == 1)
                                GLOBAL - ACCESO COMPLETO
                            @elseif(old('NIVEL_ACCESO', $user->NIVEL_ACCESO ?? '') == 2)
                                LOCAL - LIMITADO A UN FRENTE
                            @else
                                Seleccione nivel de acceso...
                            @endif
                        </span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="dropdown-content">
                        <div class="dropdown-item {{ old('NIVEL_ACCESO', $user->NIVEL_ACCESO ?? '') == 1 ? 'selected' : '' }}" onclick="selectOption('levelSelect', '1', 'GLOBAL - ACCESO COMPLETO', 'nivel')">
                            GLOBAL - ACCESO COMPLETO
                        </div>
                        <div class="dropdown-item {{ old('NIVEL_ACCESO', $user->NIVEL_ACCESO ?? '') == 2 ? 'selected' : '' }}" onclick="selectOption('levelSelect', '2', 'LOCAL - LIMITADO A UN FRENTE', 'nivel')">
                            LOCAL - LIMITADO A UN FRENTE
                        </div>
                    </div>
                </div>
                @error('NIVEL_ACCESO')
                    <span class="error-message-inline">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <span id="lbl_usuario_frente_title" class="form-label">Frentes Asignados</span>

                <div class="custom-multiselect" id="frentesSelect">
                    <div class="multiselect-trigger" id="frentesMultiselectTrigger" onclick="toggleDropdown('frentesSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_usuario_frente_title frentesSelectedCount" style="cursor: default;">
                        <span id="frentesSelectedCount">Seleccione frentes de trabajo...</span>
                        <i class="material-icons">expand_more</i>
                    </div>
                    <div class="multiselect-content" id="frentesMultiselectContent">
                        <div style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: white; z-index: 10;">
                            <div style="display: flex; align-items: center; border: 1px solid #cbd5e0; border-radius: 6px; background: #fbfcfd; padding: 0 8px;">
                                <i class="material-icons" style="font-size: 16px; color: #94a3b8;">search</i>
                                <input type="text" placeholder="Buscar frente..." 
                                       style="flex: 1; border: none; background: transparent; padding: 8px; outline: none; font-size: 13px;" 
                                       oninput="const val = this.value.toLowerCase().trim(); document.querySelectorAll('.frente-item-opt').forEach(i => i.style.display = i.textContent.toLowerCase().includes(val) ? '' : 'none');" 
                                       onclick="event.stopPropagation();">
                            </div>
                        </div>
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
                    Selecciona uno o varios frentes de los que este usuario es responsable
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
                        @php $user_perms = old('PERMISOS', $user->PERMISOS ?? []); @endphp
                        @foreach($available_permissions as $key => $label)
                            <label class="multiselect-item" for="perm_{{ $loop->index }}">
                                <input type="checkbox" id="perm_{{ $loop->index }}" name="PERMISOS[]" value="{{ $key }}" {{ in_array($key, $user_perms) ? 'checked' : '' }} onchange="updateSelectedCount()">
                                <span><strong>{{ $key }}</strong> <span style="color:#64748b; font-size:12px;">— {{ $label }}</span></span>
                            </label>
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
            <button type="submit" class="btn-primary-maquinaria">
                <i class="material-icons">save</i>
                {{ isset($user) ? 'Actualizar Información' : 'Registrar en el Sistema' }}
            </button>
        </div>
    </form>
</div>

@endsection

@section('extra_js')
<script>
    // Restaurar contador de frentes al cargar (modo edición)
    document.addEventListener('DOMContentLoaded', function () {
        if (window.updateFrentesCount) window.updateFrentesCount();
    });
</script>
@endsection
