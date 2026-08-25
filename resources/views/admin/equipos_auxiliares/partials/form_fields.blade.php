{{-- Campos del formulario de Equipo Auxiliar. Compartido entre create.blade.php y edit.blade.php.
     10 campos distribuidos en 2 filas x 5 columnas en PC (grid-responsive-5).
     Estilo identico a /admin/equipos/create: labels bold azul oscuro, form-input-custom, asterisco rojo. --}}

<style>
    /* Compacta verticalmente SOLO el form de auxiliar (menos alto entre filas y
       labels) sin tocar el grid global .grid-responsive-5 que usan otros módulos. */
    .grid-responsive-5.aux-form-compact { row-gap: 12px; }
    .aux-form-compact label[style*="margin-bottom: 8px"],
    .aux-form-compact span[style*="margin-bottom: 8px"] { margin-bottom: 4px !important; }
</style>

<div class="grid-responsive-5 aux-form-compact">
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
                   {{-- Guardas `&&` como en /admin/equipos/create: aux_form_widgets.js lo
                        trae el layout de forma asincrona, asi que enfocar el combo en el
                        primer instante puede pillarlo sin definir. Sin la guarda eso es un
                        TypeError; con ella el foco no hace nada y el siguiente ya abre. --}}
                   onfocus="window.auxTipoOpen && window.auxTipoOpen(this)" oninput="window.auxTipoFilter && window.auxTipoFilter(this)" onblur="setTimeout(()=>window.auxTipoClose && window.auxTipoClose(),150)">
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

    {{-- COMBUSTIBLE y CONSUMO: un auxiliar con motor (soldadora, compresor, luminaria,
         planta) sí quema gasoil y tiene que entrar en la proyección del frente. Las
         opciones salen de Equipo::COMBUSTIBLES — la MISMA lista que los equipos, no una
         paralela. 'NO APLICA' es para contenedores, tanques y la rastra. --}}
    <div>
        <span id="lbl_aux_combustible" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Combustible</span>
        <div class="custom-dropdown @error('COMBUSTIBLE') is-invalid @enderror" id="auxCombustibleSelect">
            <input type="hidden" name="COMBUSTIBLE" id="input_aux_combustible" data-filter-value value="{{ old('COMBUSTIBLE', $auxiliar->COMBUSTIBLE) }}" aria-label="Combustible">
            <div class="dropdown-trigger" onclick="toggleDropdown('auxCombustibleSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_aux_combustible label_aux_combustible" style="cursor: default;">
                <span id="label_aux_combustible" data-filter-label>{{ old('COMBUSTIBLE', $auxiliar->COMBUSTIBLE) ?: 'SELECCIONE' }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                @foreach(\App\Models\Equipo::COMBUSTIBLES as $comb)
                    <div class="dropdown-item" onclick="selectOption('auxCombustibleSelect', '{{ $comb }}', '{{ $comb }}', 'aux_combustible')">{{ $comb }}</div>
                @endforeach
            </div>
        </div>
        @error('COMBUSTIBLE') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        <label for="AUX_CONSUMO_PROMEDIO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Consumo (L/día)
        </label>
        <input type="number" id="AUX_CONSUMO_PROMEDIO" name="CONSUMO_PROMEDIO"
               value="{{ old('CONSUMO_PROMEDIO', \App\Models\Equipo::consumoFormateado($auxiliar->CONSUMO_PROMEDIO)) }}"
               class="form-input-custom no-spinner @error('CONSUMO_PROMEDIO') is-invalid @enderror"
               placeholder="Ej: 30" min="0" max="99999" step="0.01" autocomplete="off">
        @error('CONSUMO_PROMEDIO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <div>
        @php
            $frenteVal  = old('ID_FRENTE_ACTUAL', $auxiliar->ID_FRENTE_ACTUAL);
            $frenteCurr = $frentes->firstWhere('ID_FRENTE', (int) $frenteVal);
            // En EDICIÓN el frente queda bloqueado: reasignar de frente es trabajo de
            // Movilización (que deja CONFIRMADO_EN_SITIO=0). El selector solo aplica al CREAR.
            $esEdicion = $auxiliar->exists ?? false;
        @endphp
        <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Frente de Trabajo <span style="color: var(--maquinaria-red);">*</span>
        </span>
        <div class="custom-dropdown @error('ID_FRENTE_ACTUAL') is-invalid @enderror" id="auxFrenteSelect">
            {{-- El hidden SIEMPRE envía el frente actual: en edición pasa la validación
                 required, y update() lo descarta para conservar el frente y su confirmación. --}}
            <input type="hidden" name="ID_FRENTE_ACTUAL" id="input_aux_frente" data-filter-value value="{{ $frenteVal }}" aria-label="Frente de Trabajo" required>
            @if($esEdicion)
                {{-- Trigger BLOQUEADO: el frente no se cambia por edición. --}}
                <div class="dropdown-trigger" id="trigger_aux_frente" tabindex="-1" aria-disabled="true"
                     title="Para cambiar el frente usa Movilización" style="cursor: not-allowed; opacity: 0.75; background:#f1f5f9;">
                    <span id="label_aux_frente" data-filter-label>{{ $frenteCurr ? $frenteCurr->NOMBRE_FRENTE : 'SIN ASIGNAR' }}</span>
                    <i class="material-icons" style="color:#94a3b8;">lock</i>
                </div>
            @else
                <div class="dropdown-trigger" id="trigger_aux_frente" onclick="toggleDropdown('auxFrenteSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" style="cursor: default;">
                    <span id="label_aux_frente" data-filter-label>{{ $frenteCurr ? $frenteCurr->NOMBRE_FRENTE : 'Seleccione un frente...' }}</span>
                    <i class="material-icons">expand_more</i>
                </div>
                <div class="dropdown-content">
                    @foreach($frentes as $f)
                        <div class="dropdown-item" onclick="selectOption('auxFrenteSelect', '{{ $f->ID_FRENTE }}', '{{ addslashes($f->NOMBRE_FRENTE) }}', 'aux_frente')">{{ $f->NOMBRE_FRENTE }}</div>
                    @endforeach
                </div>
            @endif
        </div>
        @if($esEdicion)
            <span style="margin-top:4px; font-size:11px; color:#94a3b8; display:block; line-height:1.3;">
                Cambiar frente desde Movilización.
            </span>
        @endif
        @error('ID_FRENTE_ACTUAL') <span class="error-message-inline">{{ $message }}</span> @enderror
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
        @php
            // Titulo del equipo vinculado. MISMA regla que usa el buscador en JS
            // (auxHostRender): placa > codigo de patio > serial de chasis > #id. Antes
            // aqui se saltaba el serial y el JS se saltaba el codigo, asi que el MISMO
            // camion salia como "#1327" al abrir la ficha y como su serial de chasis al
            // volver a elegirlo. El #id es el ultimo recurso: no le dice nada a nadie.
            $hostPickedCard = null;
            if (isset($h) && $h) {
                $hostCodigo = trim((string) ($h->CODIGO_PATIO ?? '')) ?: null;
                $hostTitulo = (optional($h->documentacion)->PLACA ?: null)
                    ?? $hostCodigo
                    ?? (trim((string) ($h->SERIAL_CHASIS ?? '')) ?: null)
                    ?? ('#'.$h->ID_EQUIPO);
                $hostPickedCard = [
                    'id'     => $h->ID_EQUIPO,
                    'codigo' => $hostCodigo,
                    'titulo' => $hostTitulo,
                    'marca'  => $h->MARCA,
                    'modelo' => $h->MODELO,
                    'placa'  => optional($h->documentacion)->PLACA,
                ];
            }
        @endphp
        <div id="auxHostPicker" style="position: relative;">
            <input type="hidden" name="ID_EQUIPO_HOST" id="ID_EQUIPO_HOST" value="{{ $hostId }}">
            <div id="hostSearchWrapper" style="position:relative; display:{{ $hostPickedCard ? 'none' : 'block' }};">
                <input type="text" id="hostSearchInput" autocomplete="off"
                       class="form-input-custom @error('ID_EQUIPO_HOST') is-invalid @enderror"
                       placeholder="Buscar por serial motor, serial chasis o placa..."
                       {{-- Mismas guardas que el combo de TIPO, por el mismo motivo. --}}
                       oninput="window.auxHostSearch && window.auxHostSearch(this)"
                       onfocus="window.auxHostSearch && window.auxHostSearch(this)"
                       onblur="setTimeout(()=>window.auxHostClose && window.auxHostClose(),200)">
                <div id="hostResultsBox"
                     style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 20px -5px rgba(15,23,42,0.18); max-height:360px; overflow-y:auto; z-index:50;">
                </div>
            </div>
            @php
                // El codigo de patio solo se muestra aparte cuando NO es ya el titulo.
                $hostLineaCodigo = ($hostPickedCard && $hostPickedCard['codigo']
                    && $hostPickedCard['codigo'] !== $hostPickedCard['titulo'])
                    ? 'Código: '.$hostPickedCard['codigo'] : '';
            @endphp
            <div id="hostSelectedCard" style="display:{{ $hostPickedCard ? 'flex' : 'none' }}; background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%); border:1px solid #93c5fd; border-radius:10px; padding:6px 10px; align-items:center; gap:10px;">
                <div style="background:#1e40af; color:white; padding:5px; border-radius:7px; display:flex; flex-shrink:0;">
                    <i class="material-icons" style="font-size:18px;">directions_car</i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:baseline; gap:8px; min-width:0;">
                        <span id="hostSelectedPrimary" style="font-weight:800; color:#1e293b; font-size:13.5px; line-height:1.25; white-space:nowrap;">{{ $hostPickedCard['titulo'] ?? '' }}</span>
                        <span id="hostSelectedTertiary" style="color:#64748b; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:{{ $hostLineaCodigo ? 'inline' : 'none' }};">{{ $hostLineaCodigo }}</span>
                    </div>
                    <div id="hostSelectedSecondary" style="color:#475569; font-size:12px; line-height:1.25; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ trim(($hostPickedCard['marca'] ?? '').' '.($hostPickedCard['modelo'] ?? '')) ?: '' }}</div>
                </div>
                <button type="button" onclick="window.auxHostClear && window.auxHostClear()" title="Cambiar equipo vinculado"
                        style="background:white; border:1px solid #cbd5e1; color:#475569; cursor:pointer; border-radius:6px; padding:4px 8px; display:flex; align-items:center; gap:4px; font-size:11.5px; font-weight:600; flex-shrink:0;">
                    <i class="material-icons" style="font-size:15px;">swap_horiz</i>
                    Cambiar
                </button>
            </div>
        </div>
        <small style="display:block;margin-top:4px;font-size:11px;color:#94a3b8;">
            Opcional. Máx. {{ \App\Models\EquipoAuxiliar::ANCHOR_MAX_PER_HOST }} por equipo.
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
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 8px; margin: 18px 0 12px 0;">Documentación Legal</h3>

<div class="grid-responsive-5 aux-form-compact">
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
    // ── Uppercase auto: TIPO/CODIGO_INTERNO/SERIAL/MARCA/MODELO/CAPACIDAD ──
    // El backend ya normaliza a UPPER al guardar, pero el usuario debe ver lo
    // que escribe en MAYUSCULAS al instante (mismo patron que /admin/equipos).
    const upperFields = ['TIPO', 'CODIGO_INTERNO', 'SERIAL', 'MARCA', 'MODELO', 'CAPACIDAD'];
    upperFields.forEach(function (id) {
        const el = document.getElementById(id);
        if (!el || el.dataset.upperBound === '1') return;
        el.dataset.upperBound = '1';
        // CSS visual + transformacion al teclear (preserva caret position).
        el.style.textTransform = 'uppercase';
        el.addEventListener('input', function () {
            const start = el.selectionStart, end = el.selectionEnd;
            const upper = el.value.toLocaleUpperCase('es-ES');
            if (el.value !== upper) {
                el.value = upper;
                try { el.setSelectionRange(start, end); } catch (_) {}
            }
        });
        // Forzar UPPER al cargar (por si viene old() en minusculas)
        if (el.value) el.value = el.value.toLocaleUpperCase('es-ES');
    });

    // El selector de tipo y el de equipo vinculado viven ahora en
    // public/js/maquinaria/aux_form_widgets.js (cargado al final de esta vista).
})();
</script>

{{-- Selector de TIPO y de EQUIPO VINCULADO. Vive en un archivo aparte porque esta
     ficha y el alta unificada de /admin/equipos/create usan los MISMOS nueve
     manejadores; estaban escritos en las dos vistas y se habian separado.

     El <script src> NO puede ir aqui: esta vista se pinta dentro de .main-viewport y
     la navegacion SPA lo dejaria muerto. executeScripts() recorre el contenido nuevo y,
     para un script externo, salta el que ya este en el documento; el nodo INERTE que
     acaba de meter `mainViewport.innerHTML = ...` cuenta como tal (misma URL absoluta
     de asset()), asi que se descartaba a si mismo y no se ejecutaba nunca. Entrando por
     un enlace de la app los nueve manejadores quedaban sin definir y el combo de TIPO
     reventaba con TypeError al enfocarlo. Como <script> inline —lo que era antes de
     extraerlo— si corria, de ahi que el fallo apareciera con la extraccion.

     Ahora lo pide el layout con ModuleManager + cargarScriptUnaVez en cuanto ve
     #auxTipoCombo, que es el detector que comparten esta ficha y /admin/equipos/create.
     La URL con ?v= la sigue poniendo el layout, asi que el cache-busting no cambia. --}}
<script>window.AUX_HOST_SEARCH_URL = '{{ route("equipos-auxiliares.searchHosts") }}';</script>
