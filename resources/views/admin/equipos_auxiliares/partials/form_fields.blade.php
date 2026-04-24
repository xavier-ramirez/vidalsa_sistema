{{-- Campos de formulario compartidos entre create.blade.php y edit.blade.php --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(230px, 1fr));gap:14px;">

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">TIPO <span style="color:#dc2626;">*</span></label>
        <select name="TIPO" required class="form-input-custom">
            @foreach($tipos as $k => $label)
                <option value="{{ $k }}" {{ old('TIPO', $auxiliar->TIPO) === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @error('TIPO') <div style="color:#dc2626;font-size:11px;">{{ $message }}</div> @enderror
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">ESTADO OPERATIVO <span style="color:#dc2626;">*</span></label>
        <select name="ESTADO_OPERATIVO" required class="form-input-custom">
            @foreach($estados as $k => $label)
                <option value="{{ $k }}" {{ old('ESTADO_OPERATIVO', $auxiliar->ESTADO_OPERATIVO ?? 'OPERATIVO') === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">MARCA</label>
        <input type="text" name="MARCA" value="{{ old('MARCA', $auxiliar->MARCA) }}" class="form-input-custom" maxlength="80">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">MODELO</label>
        <input type="text" name="MODELO" value="{{ old('MODELO', $auxiliar->MODELO) }}" class="form-input-custom" maxlength="80">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">SERIAL</label>
        <input type="text" name="SERIAL" value="{{ old('SERIAL', $auxiliar->SERIAL) }}" class="form-input-custom" maxlength="100">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">CÓDIGO INTERNO / ETIQUETA</label>
        <input type="text" name="CODIGO_INTERNO" value="{{ old('CODIGO_INTERNO', $auxiliar->CODIGO_INTERNO) }}" class="form-input-custom" maxlength="50">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">CAPACIDAD</label>
        <input type="text" name="CAPACIDAD" value="{{ old('CAPACIDAD', $auxiliar->CAPACIDAD) }}" placeholder="ej: 300A, 50kVA, 20 pies"
               class="form-input-custom" maxlength="80">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">AÑO</label>
        <input type="number" name="ANIO" value="{{ old('ANIO', $auxiliar->ANIO) }}" class="form-input-custom no-spinner" min="1950" max="2100">
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">FRENTE</label>
        <select name="ID_FRENTE_ACTUAL" class="form-input-custom">
            <option value="">— Sin asignar —</option>
            @foreach($frentes as $f)
                <option value="{{ $f->ID_FRENTE }}" {{ (string) old('ID_FRENTE_ACTUAL', $auxiliar->ID_FRENTE_ACTUAL) === (string) $f->ID_FRENTE ? 'selected' : '' }}>
                    {{ $f->NOMBRE_FRENTE }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">EQUIPO HOST (camión que lo lleva)</label>
        <input type="number" name="ID_EQUIPO_HOST" value="{{ old('ID_EQUIPO_HOST', $auxiliar->ID_EQUIPO_HOST) }}"
               placeholder="ID del equipo (vacío = sin anclar)" class="form-input-custom no-spinner">
        <div style="color:#94a3b8;font-size:11px;margin-top:3px;">
            Máximo 2 auxiliares por equipo host. Ancla/desancla vía vista detalle.
        </div>
    </div>

</div>

<div style="margin-top:14px;">
    <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">OBSERVACIONES</label>
    <textarea name="OBSERVACIONES" rows="3" maxlength="500" class="form-input-custom">{{ old('OBSERVACIONES', $auxiliar->OBSERVACIONES) }}</textarea>
</div>
