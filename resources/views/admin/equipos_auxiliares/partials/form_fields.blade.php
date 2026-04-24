{{-- Campos del formulario de Equipo Auxiliar. Compartido entre create.blade.php y edit.blade.php.
     10 campos distribuidos en 2 filas x 5 columnas en PC (grid-responsive-5).
     Estilo identico a /admin/equipos/create: labels bold azul oscuro, form-input-custom, asterisco rojo. --}}

<div class="grid-responsive-5">
    {{-- Fila 1: Identificacion --}}
    <div>
        @php
            $tipoCurrent = old('TIPO', $auxiliar->TIPO);
            $tipoDisplay = $tipos[$tipoCurrent] ?? $tipoCurrent;
        @endphp
        <label for="TIPO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Tipo <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <div class="custom-dropdown @error('TIPO') is-invalid @enderror" id="auxTipoCombo" style="position: relative;">
            <input type="text" id="TIPO" name="TIPO" required autocomplete="off" maxlength="80"
                   value="{{ $tipoDisplay }}"
                   class="form-input-custom"
                   placeholder="Selecciona o escribe..."
                   onfocus="window.auxTipoOpen(this)" oninput="window.auxTipoFilter(this)" onblur="setTimeout(()=>window.auxTipoClose(),150)">
            <div class="dropdown-content" id="auxTipoContent">
                @foreach($tipos as $k => $label)
                    <div class="dropdown-item" data-label="{{ mb_strtolower($label) }}" onmousedown="event.preventDefault(); window.auxTipoPick('{{ addslashes($label) }}')">{{ $label }}</div>
                @endforeach
            </div>
        </div>
        @error('TIPO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        @php $estadoVal = old('ESTADO_OPERATIVO', $auxiliar->ESTADO_OPERATIVO ?? 'OPERATIVO'); @endphp
        <span id="lbl_estado_title" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Estado <span style="color: var(--maquinaria-red);">*</span>
        </span>
        <div class="custom-dropdown @error('ESTADO_OPERATIVO') is-invalid @enderror" id="auxEstadoSelect">
            <input type="hidden" name="ESTADO_OPERATIVO" id="input_aux_estado" data-filter-value value="{{ $estadoVal }}" aria-label="Estado Operativo">
            <div class="dropdown-trigger" id="trigger_aux_estado" onclick="toggleDropdown('auxEstadoSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" style="cursor: default;">
                <span id="label_aux_estado" data-filter-label>{{ $estados[$estadoVal] ?? 'SELECCIONE' }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                @foreach($estados as $k => $label)
                    <div class="dropdown-item" onclick="selectOption('auxEstadoSelect', '{{ $k }}', '{{ addslashes($label) }}', 'aux_estado')">{{ $label }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <div>
        <label for="CODIGO_INTERNO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Código Interno
        </label>
        <input type="text" id="CODIGO_INTERNO" name="CODIGO_INTERNO"
               value="{{ old('CODIGO_INTERNO', $auxiliar->CODIGO_INTERNO) }}"
               class="form-input-custom @error('CODIGO_INTERNO') is-invalid @enderror"
               placeholder="Ej: AUX-001" maxlength="50" autocomplete="off">
    </div>

    <div>
        <label for="SERIAL" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Serial <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <input type="text" id="SERIAL" name="SERIAL" required
               value="{{ old('SERIAL', $auxiliar->SERIAL) }}"
               class="form-input-custom @error('SERIAL') is-invalid @enderror"
               placeholder="Serial del fabricante" maxlength="100" autocomplete="off">
        @error('SERIAL') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        <label for="ANIO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Año
        </label>
        <input type="number" id="ANIO" name="ANIO"
               value="{{ old('ANIO', $auxiliar->ANIO) }}"
               class="form-input-custom no-spinner @error('ANIO') is-invalid @enderror"
               min="1950" max="2100" placeholder="{{ date('Y') }}" autocomplete="off">
    </div>

    {{-- Fila 2: Especificaciones + Asignacion --}}
    <div>
        <label for="MARCA" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Marca <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <input type="text" id="MARCA" name="MARCA" required
               value="{{ old('MARCA', $auxiliar->MARCA) }}"
               class="form-input-custom @error('MARCA') is-invalid @enderror"
               placeholder="Ej: Miller, Caterpillar" maxlength="80" autocomplete="off">
        @error('MARCA') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        <label for="MODELO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Modelo <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <input type="text" id="MODELO" name="MODELO" required
               value="{{ old('MODELO', $auxiliar->MODELO) }}"
               class="form-input-custom @error('MODELO') is-invalid @enderror"
               placeholder="Ej: Bobcat 225" maxlength="80" autocomplete="off">
        @error('MODELO') <span class="error-message-inline">{{ $message }}</span> @enderror
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

    <div>
        @php
            $frenteVal  = old('ID_FRENTE_ACTUAL', $auxiliar->ID_FRENTE_ACTUAL);
            $frenteCurr = $frentes->firstWhere('ID_FRENTE', (int) $frenteVal);
        @endphp
        <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Frente de Trabajo
        </span>
        <div class="custom-dropdown @error('ID_FRENTE_ACTUAL') is-invalid @enderror" id="auxFrenteSelect">
            <input type="hidden" name="ID_FRENTE_ACTUAL" id="input_aux_frente" data-filter-value value="{{ $frenteVal }}" aria-label="Frente de Trabajo">
            <div class="dropdown-trigger" id="trigger_aux_frente" onclick="toggleDropdown('auxFrenteSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" style="cursor: default;">
                <span id="label_aux_frente" data-filter-label>{{ $frenteCurr ? $frenteCurr->NOMBRE_FRENTE : 'Sin asignar' }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                <div class="dropdown-item" onclick="selectOption('auxFrenteSelect', '', 'Sin asignar', 'aux_frente')">Sin asignar</div>
                @foreach($frentes as $f)
                    <div class="dropdown-item" onclick="selectOption('auxFrenteSelect', '{{ $f->ID_FRENTE }}', '{{ addslashes($f->NOMBRE_FRENTE) }}', 'aux_frente')">{{ $f->NOMBRE_FRENTE }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <div>
        @php
            $hostId = old('ID_EQUIPO_HOST', $auxiliar->ID_EQUIPO_HOST);
            $hostPreload = null;
            if ($hostId) {
                $h = \App\Models\Equipo::with('documentacion')->find($hostId);
                if ($h) {
                    $hostPreload = trim(($h->CODIGO_PATIO ?? '#'.$h->ID_EQUIPO).' — '.($h->MARCA ?? '').' '.($h->MODELO ?? '').(optional($h->documentacion)->PLACA ? ' ('.$h->documentacion->PLACA.')' : ''));
                }
            }
        @endphp
        <label for="hostSearchInput" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Equipo Vinculado
        </label>
        <div id="auxHostPicker" style="position: relative;">
            <input type="hidden" name="ID_EQUIPO_HOST" id="ID_EQUIPO_HOST" value="{{ $hostId }}">
            <input type="text" id="hostSearchInput" autocomplete="off"
                   value="{{ $hostPreload }}"
                   class="form-input-custom @error('ID_EQUIPO_HOST') is-invalid @enderror"
                   placeholder="Buscar por serial motor, serial chasis o placa..."
                   oninput="window.auxHostSearch(this)"
                   onfocus="window.auxHostSearch(this)"
                   onblur="setTimeout(()=>window.auxHostClose(),200)">
            <i class="material-icons" id="hostClearBtn"
               style="position:absolute; right:8px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:18px; color:#94a3b8; display:{{ $hostId ? 'block' : 'none' }};"
               onclick="window.auxHostClear()">close</i>
            <div id="hostResultsBox"
                 style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 20px -5px rgba(15,23,42,0.18); max-height:320px; overflow-y:auto; z-index:50;">
            </div>
        </div>
        <small style="display:block;margin-top:4px;font-size:11px;color:#94a3b8;">
            Opcional. Máximo {{ \App\Models\EquipoAuxiliar::ANCHOR_MAX_PER_HOST }} auxiliares por equipo vinculado.
        </small>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     Documentación (opcional): Propiedad + Certificado con vencimiento.
     Estilo e iconografia identicos a /admin/equipos/create: input de meta
     a la izquierda + boton de 30x30 a la derecha (azul con description si
     ya hay PDF, dashed con cloud_upload si no).
     Almacenamiento local via storage/app/public/equipos_auxiliares/{id}/
     ═══════════════════════════════════════════════════════════ --}}
@php
    $hasProp = !empty($auxiliar->LINK_DOC_PROPIEDAD);
    $hasCert = !empty($auxiliar->LINK_CERTIFICADO);
@endphp
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin: 30px 0 20px 0;">Documentación Legal</h3>

<div class="grid-responsive-5">
    {{-- Documento de Propiedad (meta readonly + boton PDF, sin campo editable) --}}
    <div style="position: relative;">
        <label for="doc_propiedad_meta" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Documento de Propiedad</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="text" id="doc_propiedad_meta" class="form-input-custom" value="{{ $hasProp ? 'PDF cargado' : '' }}"
                   placeholder="Sin documento" readonly style="flex: 1; background:#f8fafc; cursor:default;" autocomplete="off">
            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasProp)
                    <a href="{{ asset($auxiliar->LINK_DOC_PROPIEDAD) }}" target="_blank" rel="noopener" title="Ver documento: Propiedad"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="doc_propiedad" title="Reemplazar PDF"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="doc_propiedad" title="Cargar PDF de Propiedad"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;"
                           onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="doc_propiedad" name="doc_propiedad" accept=".pdf" style="display:none;"
                   onchange="document.getElementById('doc_propiedad_meta').value = this.files[0] ? this.files[0].name : '';">
        </div>
        @error('doc_propiedad') <div style="color: var(--maquinaria-red); font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror
    </div>

    {{-- Vencimiento del Certificado + boton PDF integrado (el cuadro "Certificado"
         readonly fue eliminado; el PDF se carga directo desde este bloque). --}}
    <div style="position: relative;">
        <label for="fecha_vencimiento_cert" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Certif.</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="date" id="fecha_vencimiento_cert" name="fecha_vencimiento_cert"
                   value="{{ old('fecha_vencimiento_cert', optional($auxiliar->FECHA_VENCIMIENTO_CERT)->format('Y-m-d')) }}"
                   class="form-input-custom @error('fecha_vencimiento_cert') is-invalid @enderror"
                   style="flex: 1; cursor:pointer;" onclick="try{this.showPicker()}catch(e){}">
            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasCert)
                    <a href="{{ asset($auxiliar->LINK_CERTIFICADO) }}" target="_blank" rel="noopener" title="Ver certificado"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="certificado" title="Reemplazar certificado"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="certificado" title="Cargar PDF de certificado"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;"
                           onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="certificado" name="certificado" accept=".pdf" style="display:none;">
        </div>
        @error('fecha_vencimiento_cert') <span class="error-message-inline">{{ $message }}</span> @enderror
        @error('certificado') <div style="color: var(--maquinaria-red); font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror
    </div>
</div>

<script>
(function () {
    // ── Tipo combobox (app style) ──
    window.auxTipoOpen = function (input) {
        const cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'block';
        window.auxTipoFilter(input);
    };
    window.auxTipoClose = function () {
        const cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'none';
    };
    window.auxTipoFilter = function (input) {
        const q = (input.value || '').toLowerCase().trim();
        document.querySelectorAll('#auxTipoContent .dropdown-item').forEach(it => {
            it.style.display = (!q || it.dataset.label.includes(q)) ? '' : 'none';
        });
    };
    window.auxTipoPick = function (label) {
        const input = document.getElementById('TIPO');
        if (input) input.value = label;
        window.auxTipoClose();
    };

    // ── Equipo Vinculado (host) picker ──
    let _hostDebounce = null;
    let _hostLastQuery = '';

    window.auxHostSearch = function (input) {
        const q = (input.value || '').trim();
        if (q.length < 2) {
            document.getElementById('hostResultsBox').style.display = 'none';
            return;
        }
        if (q === _hostLastQuery) return;
        _hostLastQuery = q;
        clearTimeout(_hostDebounce);
        _hostDebounce = setTimeout(() => {
            fetch('{{ route("equipos-auxiliares.searchHosts") }}?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => window.auxHostRender(data))
            .catch(err => console.error('searchHosts:', err));
        }, 280);
    };

    window.auxHostRender = function (rows) {
        const box = document.getElementById('hostResultsBox');
        if (!box) return;
        if (!rows || !rows.length) {
            box.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:12px;">Sin resultados.</div>';
            box.style.display = 'block';
            return;
        }
        box.innerHTML = rows.map(r => {
            const dis = r.disponible ? '' : 'opacity:0.55; pointer-events:none;';
            const badge = r.disponible
                ? `<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;">Disponible</span>`
                : `<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;">Lleno (${r.auxiliares_anclados}/2)</span>`;
            return `
                <div class="aux-host-card" style="padding:10px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; ${dis}"
                     onmousedown="event.preventDefault(); window.auxHostPick(${r.id}, this)"
                     data-label="${(r.codigo || ('#'+r.id)).replace(/"/g,'&quot;')} — ${(r.marca_modelo || '').replace(/"/g,'&quot;')} ${r.placa ? '('+r.placa+')' : ''}"
                     onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:4px;">
                        <strong style="color:#1e293b; font-size:13px;">${r.codigo || ('#'+r.id)}</strong>
                        ${badge}
                    </div>
                    <div style="font-size:12px; color:#475569; line-height:1.35;">
                        ${r.marca_modelo || '<em style="color:#94a3b8;">Sin marca/modelo</em>'}
                        ${r.tipo ? `<span style="color:#64748b;"> · ${r.tipo}</span>` : ''}
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap;">
                        ${r.placa ? `<span><b>Placa:</b> ${r.placa}</span>` : ''}
                        ${r.serial_chasis ? `<span><b>Chasis:</b> ${r.serial_chasis}</span>` : ''}
                        ${r.serial_motor ? `<span><b>Motor:</b> ${r.serial_motor}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');
        box.style.display = 'block';
    };

    window.auxHostPick = function (id, el) {
        const hidden = document.getElementById('ID_EQUIPO_HOST');
        const search = document.getElementById('hostSearchInput');
        const clear  = document.getElementById('hostClearBtn');
        if (hidden) hidden.value = id;
        if (search) search.value = el.dataset.label || ('#' + id);
        if (clear) clear.style.display = 'block';
        window.auxHostClose();
    };

    window.auxHostClose = function () {
        const box = document.getElementById('hostResultsBox');
        if (box) box.style.display = 'none';
    };

    window.auxHostClear = function () {
        const hidden = document.getElementById('ID_EQUIPO_HOST');
        const search = document.getElementById('hostSearchInput');
        const clear  = document.getElementById('hostClearBtn');
        if (hidden) hidden.value = '';
        if (search) search.value = '';
        if (clear) clear.style.display = 'none';
        _hostLastQuery = '';
        window.auxHostClose();
    };
})();
</script>
