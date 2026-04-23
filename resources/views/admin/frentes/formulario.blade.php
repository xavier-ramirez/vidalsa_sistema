@extends('layouts.estructura_base')



@section('title', 'Gestión de Frentes')

@section('content')
    <section class="page-title-card" style="text-align: center; margin: 0 auto 10px auto;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000; font-size: 28px;"
                id="formTitle">{{ (isset($frente) && $frente->exists) ? 'Edición de Frente de Trabajo' : 'Registro de Frente de Trabajo' }}</span>
        </h1>
    </section>






    <!-- Form -->
    <div class="admin-card" style="max-width: 800px; margin: 0 auto;">

        <!-- Top Search Bar (Standardized Smart Dropdown) -->
        <div style="margin-bottom: 30px; display: flex; justify-content: center;">
            <div style="width: 100%; max-width: 500px; position: relative;">

                <div class="custom-dropdown" id="frenteSearchDropdown" style="width: 100%;">
                    <div class="dropdown-trigger"
                        style="background: #fff; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">

                        <div
                            style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>

                        <input type="text" id="filterSearchInput" placeholder="Buscar frente para editar..."
                            style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                            autocomplete="off" oninput="window.filterFrentesDropdown(this)">

                        <div style="display: flex; align-items: center; padding-right: 10px;">
                            <i id="btn_clear_search_frente" class="material-icons"
                                style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: none;"
                                onclick="event.stopPropagation(); window.clearFrentesSearchSPA();">close</i>
                        </div>
                    </div>

                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" id="frenteItemsList" style="max-height: 250px; overflow-y: auto;">
                            @if(isset($allFrentes))
                                @foreach($allFrentes as $f)
                                    <div class="dropdown-item search-result-item" data-name="{{ $f->NOMBRE_FRENTE }}"
                                        onclick="selectFrenteSPA('{{ $f->ID_FRENTE }}')">
                                        {{ $f->NOMBRE_FRENTE }}
                                    </div>
                                @endforeach
                            @endif
                            <div id="no-results-msg"
                                style="display: none; padding: 10px 15px; color: #94a3b8; font-size: 14px;">No se
                                encontraron resultados</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <form
            action="{{ (isset($frente) && $frente->exists) ? route('frentes.update', $frente->ID_FRENTE) : route('frentes.store') }}"
            method="POST" id="frenteForm" onsubmit="if(window.showPreloader) window.showPreloader();">
            @csrf
            @if(isset($frente) && $frente->exists) @method('PUT') @endif
            <input type="hidden" id="ID_FRENTE" name="ID_FRENTE" value="{{ $frente->ID_FRENTE ?? '' }}">

            <div class="form-grid">
                <!-- Row 1 -->
                <div>
                    <label for="NOMBRE_FRENTE" class="form-label">Nombre del Frente <span
                            style="color: red;">*</span></label>
                    <input type="text" id="NOMBRE_FRENTE" name="NOMBRE_FRENTE" class="form-input-custom"
                        style="background: white;" placeholder="Ej: MINA 1"
                        value="{{ old('NOMBRE_FRENTE', $frente->NOMBRE_FRENTE ?? '') }}" required autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Tipo de Frente <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="tipoSelect">
                        <input type="hidden" name="TIPO_FRENTE" id="input_tipo"
                            value="{{ old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('tipoSelect', event)"
                            style="background: white; cursor: default;">
                            <span
                                id="label_tipo">{{ old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? 'Seleccione Tipo...') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'OPERACION', 'OPERACION', 'tipo')">OPERACION</div>
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'RESGUARDO', 'RESGUARDO', 'tipo')">RESGUARDO</div>
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'ESPECIAL', 'ESPECIAL', 'tipo')">ESPECIAL</div>
                        </div>
                    </div>
                </div>

                <!-- Row 2 -->
                <div>
                    <label for="UBICACION" class="form-label">Ubicación / Geografía <span
                            style="color: red;">*</span></label>
                    <input type="text" id="UBICACION" name="UBICACION" class="form-input-custom" style="background: white;"
                        placeholder="Ej: Sector 4" value="{{ old('UBICACION', $frente->UBICACION ?? '') }}" required
                        autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Estatus del Proyecto <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="statusSelect">
                        <input type="hidden" name="ESTATUS_FRENTE" id="input_estatus"
                            value="{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('statusSelect', event)"
                            style="background: white; cursor: default;">
                            <span
                                id="label_estatus">{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? 'Seleccione Estatus...') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'ACTIVO', 'ACTIVO', 'estatus')">ACTIVO</div>
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'FINALIZADO', 'FINALIZADO', 'estatus')">FINALIZADO
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 1 -->
                <div
                    style="grid-column: span 2; border-bottom: 2px solid #f1f5f9; padding: 10px 0; margin-top: 20px; color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">
                    Responsable Principal
                </div>

                <!-- Responsable 1 Inputs -->
                <div class="resp-grid" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_1_NOM" class="form-label">Nombre Completo <span style="color: red;">*</span></label>
                        <input type="text" id="RESP_1_NOM" name="RESP_1_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_1_NOM', $frente->RESP_1_NOM ?? '') }}" required autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_1_CAR" class="form-label">Cargo <span style="color: red;">*</span></label>
                        <input type="text" id="RESP_1_CAR" name="RESP_1_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Gerente"
                            value="{{ old('RESP_1_CAR', $frente->RESP_1_CAR ?? '') }}" required autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_1_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_1_CED" name="RESP_1_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_1_CED', $frente->RESP_1_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma <span style="font-weight: normal; font-size: 11px; color: #64748b;">(Opcional)</span></span>
                        <div class="custom-dropdown" id="resp1EquSelect">
                            <input type="hidden" name="RESP_1_EQU" id="input_resp1_equ" value="{{ old('RESP_1_EQU', $frente->RESP_1_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp1EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp1_equ">{{ old('RESP_1_EQU', $frente->RESP_1_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp1EquSelect', '', 'SIN FILTRO', 'resp1_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp1EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp1_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 2 -->
                <div
                    style="grid-column: span 2; border-bottom: 2px solid #f1f5f9; padding: 10px 0; margin-top: 20px; color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">
                    Segundo Responsable
                </div>

                <!-- Responsable 2 Inputs -->
                <div class="resp-grid" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_2_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_2_NOM" name="RESP_2_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_2_NOM', $frente->RESP_2_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_2_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_2_CAR" name="RESP_2_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Supervisor"
                            value="{{ old('RESP_2_CAR', $frente->RESP_2_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_2_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_2_CED" name="RESP_2_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_2_CED', $frente->RESP_2_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp2EquSelect">
                            <input type="hidden" name="RESP_2_EQU" id="input_resp2_equ" value="{{ old('RESP_2_EQU', $frente->RESP_2_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp2EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp2_equ">{{ old('RESP_2_EQU', $frente->RESP_2_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp2EquSelect', '', 'SIN FILTRO', 'resp2_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp2EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp2_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 3 -->
                <div
                    style="grid-column: span 2; border-bottom: 2px solid #f1f5f9; padding: 10px 0; margin-top: 20px; color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">
                    Tercer Responsable
                </div>

                <!-- Responsable 3 Inputs -->
                <div class="resp-grid" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_3_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_3_NOM" name="RESP_3_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_3_NOM', $frente->RESP_3_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_3_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_3_CAR" name="RESP_3_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Jefe de Taller"
                            value="{{ old('RESP_3_CAR', $frente->RESP_3_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_3_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_3_CED" name="RESP_3_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_3_CED', $frente->RESP_3_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp3EquSelect">
                            <input type="hidden" name="RESP_3_EQU" id="input_resp3_equ" value="{{ old('RESP_3_EQU', $frente->RESP_3_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp3EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp3_equ">{{ old('RESP_3_EQU', $frente->RESP_3_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp3EquSelect', '', 'SIN FILTRO', 'resp3_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp3EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp3_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 4 -->
                <div
                    style="grid-column: span 2; border-bottom: 2px solid #f1f5f9; padding: 10px 0; margin-top: 20px; color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">
                    Cuarto Responsable
                </div>

                <!-- Responsable 4 Inputs -->
                <div class="resp-grid" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_4_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_4_NOM" name="RESP_4_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_4_NOM', $frente->RESP_4_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_4_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_4_CAR" name="RESP_4_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Residente"
                            value="{{ old('RESP_4_CAR', $frente->RESP_4_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_4_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_4_CED" name="RESP_4_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_4_CED', $frente->RESP_4_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp4EquSelect">
                            <input type="hidden" name="RESP_4_EQU" id="input_resp4_equ" value="{{ old('RESP_4_EQU', $frente->RESP_4_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp4EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp4_equ">{{ old('RESP_4_EQU', $frente->RESP_4_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp4EquSelect', '', 'SIN FILTRO', 'resp4_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp4EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp4_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="frentes-btn-row"
                style="margin-top: 40px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; align-items: center;">
                <a href="{{ route('menu') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>



                <button type="submit" class="btn-primary-maquinaria"
                    style="padding: 12px 30px; font-size: 14px; height: auto;">
                    <i class="material-icons" style="font-size: 20px;"
                        id="submitBtnIcon">{{ (isset($frente) && $frente->exists) ? 'save' : 'add_circle' }}</i>
                    <span
                        id="submitBtnText">{{ (isset($frente) && $frente->exists) ? 'Guardar Cambios' : 'Registrar' }}</span>
                </button>
            </div>
        </form>

        @if(isset($frente) && $frente->exists)

        @endif
    </div>
@endsection