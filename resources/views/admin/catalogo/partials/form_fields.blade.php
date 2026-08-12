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

    .catalog-label {
        display: block;
        font-weight: 700;
        margin-bottom: 5px;
        color: var(--maquinaria-dark-blue, #1a202c);
        font-size: 13px;
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
    {{-- TIPO de Equipo VA PRIMERO: al elegirlo, el campo Modelo sugiere los modelos
         de ese tipo (catalogo_create.js → scopeCatalogoModelos). Igual puedes escribir
         uno nuevo. Alimenta la sugerencia de catálogo del alta de equipos. --}}
    <div class="catalog-field-group span-2">
        <label for="TIPO" class="catalog-label">Tipo de Equipo</label>
        <div class="custom-form-autocomplete">
            <input type="text" id="TIPO" name="TIPO"
                   class="form-input-custom @error('TIPO') is-invalid @enderror"
                   value="{{ old('TIPO', $catalogo->TIPO ?? '') }}"
                   placeholder="Escriba o seleccione..."
                   maxlength="35"
                   oninput="this.value = this.value.toUpperCase(); filterFormDropdown(this)"
                   onfocus="showFormDropdown(this)"
                   onblur="hideFormDropdownDelayed(this)"
                   autocomplete="off">
            <div class="dropdown-list">
                @foreach($tipos ?? [] as $t)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $t }}')">{{ $t }}</div>
                @endforeach
            </div>
        </div>
        @error('TIPO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- MODELO — sugerido según el TIPO elegido (o escribe uno nuevo). -->
    <div class="catalog-field-group">
        <label for="MODELO" class="catalog-label">Modelo</label>
        <div class="custom-form-autocomplete">
            <input type="text" id="MODELO" name="MODELO"
                   class="form-input-custom @error('MODELO') is-invalid @enderror"
                   value="{{ old('MODELO', $catalogo->MODELO ?? '') }}"
                   placeholder="Escriba o elija según el tipo..."
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

    {{-- La foto del modelo ya NO se sube desde el formulario: se gestiona haciendo
         click en la foto de cada tarjeta en /admin/catalogo (mismo flujo que el
         catálogo de auxiliares). Ver catalogo.uploadFoto + catUploadPhoto(). --}}

    <!-- 4. MOTOR -->
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

    {{-- El COMBUSTIBLE ya no vive aquí: se mudó a `equipos` (form de la unidad).
         Un mismo MODELO puede traer motor a gasolina o a gasoil según la unidad
         (HILUX 2.7 vs 2.4 diésel, F-350 Triton vs Power Stroke), así que la ficha
         del modelo no podía representarlo. El CONSUMO se mudó por lo mismo: tres
         unidades del MISMO chasis SINOTRUK ZZ4257 gastan 150, 80 y 50 L/día según el
         trabajo que hagan. Aquí solo quedan datos de referencia del modelo: MOTOR,
         aceites, refrigerante, batería. --}}

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
