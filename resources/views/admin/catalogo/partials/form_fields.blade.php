<style>
    .catalog-form-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px 15px;
        align-items: start;
    }
    .catalog-field-group {
        display: flex;
        flex-direction: column;
    }
    /* Column Spans */
    .span-2 { grid-column: span 2; }
    .span-4 { grid-column: span 4; }

    .catalog-label {
        display: block;
        font-weight: 700;
        margin-bottom: 5px;
        color: var(--maquinaria-dark-blue, #1a202c);
        font-size: 13px;
    }

    /* Wrapper para input de archivo + preview */
    .file-input-wrapper {
        display: flex;
        gap: 8px;
        align-items: center;
        height: 38px;
    }
    .file-preview-box {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background: white;
        border-radius: 8px;
        border: 1px solid #cbd5e0;
        flex-shrink: 0;
        transition: all 0.2s;
    }

    /* Autocomplete dropdown */
    .custom-form-autocomplete {
        position: relative;
        width: 100%;
    }
    .dropdown-list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #cbd5e0;
        border-radius: 8px;
        margin-top: 4px;
        max-height: 250px;
        overflow-y: auto;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        z-index: 50;
        display: none;
    }
    .dropdown-item {
        padding: 10px 16px;
        border-bottom: 1px solid #f1f5f9;
        color: #4a5568;
        font-size: 14px;
        transition: all 0.2s;
    }
    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-item:hover {
        background-color: #f7fafc;
        color: #2b6cb0;
        padding-left: 20px;
    }

    @media (max-width: 900px) {
        .catalog-form-grid { grid-template-columns: repeat(2, 1fr); }
        .span-2 { grid-column: auto; }
    }
    @media (max-width: 600px) {
        .catalog-form-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="catalog-form-grid">
    <!-- 1. MODELO (Wider) -->
    <div class="catalog-field-group span-2">
        <label for="MODELO" class="catalog-label">Modelo</label>
        <div class="custom-form-autocomplete">
            <input type="text" id="MODELO" name="MODELO"
                   class="form-input-custom @error('MODELO') is-invalid @enderror" 
                   value="{{ old('MODELO', $catalogo->MODELO ?? '') }}" 
                   placeholder="Escriba..." 
                   required 
                   oninput="this.value = this.value.toUpperCase(); filterFormDropdown(this)"
                   onfocus="showFormDropdown(this)"
                   onblur="hideFormDropdownDelayed(this)"
                   autocomplete="off">
            <div class="dropdown-list">
                @foreach($modelosList ?? [] as $modelo)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $modelo }}')">{{ $modelo }}</div>
                @endforeach
            </div>
        </div>
        @error('MODELO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- 2. AÑO (Narrow) -->
    <div class="catalog-field-group">
        <label for="ANIO_ESPEC" class="catalog-label">Año</label>
         <div class="custom-form-autocomplete">
            <input type="text" id="ANIO_ESPEC" name="ANIO_ESPEC"
                   class="form-input-custom no-spinner @error('ANIO_ESPEC') is-invalid @enderror" 
                   value="{{ old('ANIO_ESPEC', $catalogo->ANIO_ESPEC ?? '') }}" 
                   placeholder="Escriba o seleccione..." 
                   required 
                   maxlength="4"
                   oninput="this.value = this.value.replace(/[^0-9]/g, ''); filterFormDropdown(this)"
                   onfocus="showFormDropdown(this)"
                   onblur="hideFormDropdownDelayed(this)"
                   autocomplete="off">
            <div class="dropdown-list">
                @foreach($aniosList ?? [] as $anio)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $anio }}')">{{ $anio }}</div>
                @endforeach
            </div>
        </div>
        @error('ANIO_ESPEC') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- 3. FOTO DEL MODELO (Narrow) -->
    <div class="catalog-field-group">
        <label for="foto_referencial" class="catalog-label">Foto del Modelo</label>
        <div class="file-input-wrapper">
            <label for="foto_referencial" id="preview_referencial" class="file-preview-box" 
                   title="Foto del Modelo" 
                   onmouseover="this.style.borderColor='var(--maquinaria-blue)';" 
                   onmouseout="this.style.borderColor='#cbd5e0';">
                @if(isset($catalogo) && $catalogo->FOTO_REFERENCIAL)
                    <img src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $catalogo->FOTO_REFERENCIAL)]) }}" 
                         style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">
                @else
                    <i class="material-icons" style="font-size: 16px; color: #cbd5e0;">photo_camera</i>
                @endif
            </label>
            <input type="file" id="foto_referencial" name="foto_referencial" accept="image/*" style="display: none;">
            <div style="font-size: 10px; color: #718096; line-height: 1.1;">Click para {{ isset($catalogo) ? 'cambiar' : 'cargar' }}</div>
        </div>
    </div>

    <!-- 4. MOTOR (Wider) -->
    <div class="catalog-field-group">
        <label for="MOTOR" class="catalog-label">Motor</label>
        <input type="text" id="MOTOR" name="MOTOR" 
               class="form-input-custom" 
               value="{{ old('MOTOR', $catalogo->MOTOR ?? '') }}" 
               placeholder="Ej: Cat C9.3" 
               autocomplete="off" 
               oninput="this.value = this.value.toUpperCase()">
        @error('MOTOR') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- 5. COMBUSTIBLE (Strict Selection) -->
    <div class="catalog-field-group">
        <span class="catalog-label">Combustible</span>
        <div class="custom-dropdown @error('COMBUSTIBLE') is-invalid @enderror" id="combustibleSelect">
            <input type="hidden" name="COMBUSTIBLE" id="combustible_value" value="{{ old('COMBUSTIBLE', $catalogo->COMBUSTIBLE ?? '') }}">
            <div class="dropdown-trigger" id="combustible_trigger" onclick="toggleDropdown('combustibleSelect', event)" tabindex="0" role="button" style="cursor: default;">
                <span id="label_combustible">{{ old('COMBUSTIBLE', $catalogo->COMBUSTIBLE ?? '') ?: 'SELECCIONE' }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                @foreach(['GASOLINA', 'DIESEL', 'GASOIL', 'GAS', 'ELÉCTRICO', 'HIBRIDO'] as $val)
                    <div class="dropdown-item" onclick="selectOption('combustibleSelect', '{{ $val }}', '{{ $val }}', 'combustible')">{{ $val }}</div>
                @endforeach
            </div>
        </div>
        @error('COMBUSTIBLE') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- 6. CONSUMO -->
    <div class="catalog-field-group">
        <label for="CONSUMO_PROMEDIO" class="catalog-label">Consumo (L/Día)</label>
        <input type="text" id="CONSUMO_PROMEDIO" name="CONSUMO_PROMEDIO"
               class="form-input-custom"
               value="{{ old('CONSUMO_PROMEDIO', $catalogo->CONSUMO_PROMEDIO ?? '') }}"
               placeholder="Ej: 120"
               autocomplete="off">
    </div>

    <!-- 7. ACEITE MOTOR -->
    <div class="catalog-field-group">
        <label for="ACEITE_MOTOR" class="catalog-label">Aceite Motor</label>
        <input type="text" id="ACEITE_MOTOR" name="ACEITE_MOTOR" 
               class="form-input-custom" 
               value="{{ old('ACEITE_MOTOR', $catalogo->ACEITE_MOTOR ?? '') }}" 
               placeholder="Ej: 15W-40" 
               autocomplete="off" 
               oninput="this.value = this.value.toUpperCase()">
    </div>

    <!-- 8. ACEITE CAJA -->
    <div class="catalog-field-group">
        <label for="ACEITE_CAJA" class="catalog-label">Aceite Caja</label>
        <input type="text" id="ACEITE_CAJA" name="ACEITE_CAJA" 
               class="form-input-custom" 
               value="{{ old('ACEITE_CAJA', $catalogo->ACEITE_CAJA ?? '') }}" 
               placeholder="Ej: SAE 30" 
               autocomplete="off" 
               oninput="this.value = this.value.toUpperCase()">
    </div>

    <!-- 9. LIGA FRENO -->
    <div class="catalog-field-group">
        <label for="LIGA_FRENO" class="catalog-label">Liga Freno</label>
        <input type="text" id="LIGA_FRENO" name="LIGA_FRENO" 
               class="form-input-custom" 
               value="{{ old('LIGA_FRENO', $catalogo->LIGA_FRENO ?? '') }}" 
               placeholder="Ej: DOT 4" 
               autocomplete="off" 
               oninput="this.value = this.value.toUpperCase()">
    </div>

    <!-- 10. REFRIGERANTE -->
    <div class="catalog-field-group">
        <label for="REFRIGERANTE" class="catalog-label">Refrigerante</label>
        <input type="text" id="REFRIGERANTE" name="REFRIGERANTE" 
               class="form-input-custom" 
               value="{{ old('REFRIGERANTE', $catalogo->REFRIGERANTE ?? '') }}" 
               placeholder="Ej: ELC (Rojo)" 
               autocomplete="off" 
               oninput="this.value = this.value.toUpperCase()">
    </div>

    <!-- 11. BATERÍA -->
    <div class="catalog-field-group">
        <label for="TIPO_BATERIA" class="catalog-label">Batería</label>
        <input type="text" id="TIPO_BATERIA" name="TIPO_BATERIA" 
               class="form-input-custom" 
               value="{{ old('TIPO_BATERIA', $catalogo->TIPO_BATERIA ?? '') }}" 
               placeholder="Ej: 12V 1000CCA" 
               oninput="this.value = this.value.toUpperCase()">
    </div>
</div>
