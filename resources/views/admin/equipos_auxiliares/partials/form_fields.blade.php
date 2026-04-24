{{-- Campos del formulario de Equipo Auxiliar. Compartido entre create.blade.php y edit.blade.php.
     Replica visualmente el form de /admin/equipos/create: titulos h3 azules con border-bottom,
     grid-responsive-5, labels bold en azul oscuro, clase form-input-custom, asterisco rojo en requeridos. --}}

<!-- Identificación -->
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px;">Identificación</h3>

<div class="grid-responsive-5">
    <div>
        <label for="TIPO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Tipo de Auxiliar <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <select name="TIPO" id="TIPO" required class="form-input-custom @error('TIPO') is-invalid @enderror">
            @foreach($tipos as $k => $label)
                <option value="{{ $k }}" {{ old('TIPO', $auxiliar->TIPO) === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @error('TIPO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        <label for="ESTADO_OPERATIVO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Estado Operativo <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <select name="ESTADO_OPERATIVO" id="ESTADO_OPERATIVO" required class="form-input-custom @error('ESTADO_OPERATIVO') is-invalid @enderror">
            @foreach($estados as $k => $label)
                <option value="{{ $k }}" {{ old('ESTADO_OPERATIVO', $auxiliar->ESTADO_OPERATIVO ?? 'OPERATIVO') === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="CODIGO_INTERNO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Código Interno / Etiqueta
        </label>
        <input type="text" id="CODIGO_INTERNO" name="CODIGO_INTERNO"
               value="{{ old('CODIGO_INTERNO', $auxiliar->CODIGO_INTERNO) }}"
               class="form-input-custom @error('CODIGO_INTERNO') is-invalid @enderror"
               placeholder="Ej: AUX-001" maxlength="50" autocomplete="off">
    </div>

    <div>
        <label for="SERIAL" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Serial
        </label>
        <input type="text" id="SERIAL" name="SERIAL"
               value="{{ old('SERIAL', $auxiliar->SERIAL) }}"
               class="form-input-custom @error('SERIAL') is-invalid @enderror"
               placeholder="Serial del fabricante" maxlength="100" autocomplete="off">
    </div>

    <div>
        <label for="ANIO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Año
        </label>
        <input type="number" id="ANIO" name="ANIO"
               value="{{ old('ANIO', $auxiliar->ANIO) }}"
               class="form-input-custom no-spinner @error('ANIO') is-invalid @enderror"
               min="1950" max="2100" placeholder="Ej: {{ date('Y') }}" autocomplete="off">
    </div>
</div>

<!-- Especificaciones -->
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin: 30px 0 20px 0;">Especificaciones</h3>

<div class="grid-responsive-5">
    <div>
        <label for="MARCA" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Marca
        </label>
        <input type="text" id="MARCA" name="MARCA"
               value="{{ old('MARCA', $auxiliar->MARCA) }}"
               class="form-input-custom @error('MARCA') is-invalid @enderror"
               placeholder="Ej: Miller, Caterpillar" maxlength="80" autocomplete="off">
    </div>

    <div>
        <label for="MODELO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Modelo
        </label>
        <input type="text" id="MODELO" name="MODELO"
               value="{{ old('MODELO', $auxiliar->MODELO) }}"
               class="form-input-custom @error('MODELO') is-invalid @enderror"
               placeholder="Ej: Bobcat 225" maxlength="80" autocomplete="off">
    </div>

    <div>
        <label for="CAPACIDAD" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Capacidad
        </label>
        <input type="text" id="CAPACIDAD" name="CAPACIDAD"
               value="{{ old('CAPACIDAD', $auxiliar->CAPACIDAD) }}"
               class="form-input-custom @error('CAPACIDAD') is-invalid @enderror"
               placeholder="Ej: 300A, 50kVA, 20 pies" maxlength="80" autocomplete="off">
    </div>
</div>

<!-- Asignación -->
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin: 30px 0 20px 0;">Asignación</h3>

<div class="grid-responsive-5">
    <div>
        <label for="ID_FRENTE_ACTUAL" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Frente de Trabajo
        </label>
        <select name="ID_FRENTE_ACTUAL" id="ID_FRENTE_ACTUAL" class="form-input-custom @error('ID_FRENTE_ACTUAL') is-invalid @enderror">
            <option value="">— Sin asignar —</option>
            @foreach($frentes as $f)
                <option value="{{ $f->ID_FRENTE }}" {{ (string) old('ID_FRENTE_ACTUAL', $auxiliar->ID_FRENTE_ACTUAL) === (string) $f->ID_FRENTE ? 'selected' : '' }}>
                    {{ $f->NOMBRE_FRENTE }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="ID_EQUIPO_HOST" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Equipo Host (Camión)
        </label>
        <input type="number" id="ID_EQUIPO_HOST" name="ID_EQUIPO_HOST"
               value="{{ old('ID_EQUIPO_HOST', $auxiliar->ID_EQUIPO_HOST) }}"
               class="form-input-custom no-spinner @error('ID_EQUIPO_HOST') is-invalid @enderror"
               placeholder="ID del equipo (opcional)" autocomplete="off">
        <small style="display:block;margin-top:4px;font-size:11px;color:#94a3b8;">
            Máximo 2 auxiliares por equipo host.
        </small>
    </div>
</div>

