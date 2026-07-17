                @forelse($users as $user)
                    <tr>
                         <td class="table-cell-bordered" style="font-size: 14px; font-weight: 600; color: var(--maquinaria-dark-blue); padding: 8px 12px; white-space: nowrap;">
                            {{-- Tooltip estilizado (igual que el "detalle" de equipos): al
                                 pasar/enfocar la fila se muestra la fecha de creación. El CSS
                                 global `.admin-table tr:hover .tooltip-bubble` lo dispara. --}}
                            <div class="tooltip-wrapper" style="position: relative; cursor: default; display: inline-block;">
                                {{ $user->NOMBRE_COMPLETO }}
                                <div class="tooltip-bubble" style="pointer-events:none; opacity:0; visibility:hidden; position:absolute; bottom:100%; left:0; transform:translateY(5px); background:#1e293b; color:#fff; padding:6px 10px; border-radius:6px; font-size:11px; font-weight:500; white-space:nowrap; width:max-content; text-align:left; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); transition:all 0.2s ease-in-out; z-index:50; margin-bottom:5px;">
                                    <div>📅 Creado: {{ optional($user->created_at)->format('d/m/Y H:i') ?: 'sin fecha' }}</div>
                                    {{-- ID_FRENTE_ASIGNADO es CSV: mostramos TODOS los frentes (la
                                         columna de al lado solo pinta el primero). Sin frente = GLOBAL. --}}
                                    @php($frentesAsignados = $user->getNombresFrentesAsignados())
                                    <div style="margin-top:3px;">
                                        📍 Frente{{ count($frentesAsignados) > 1 ? 's' : '' }}:
                                        @forelse($frentesAsignados as $f)
                                            <div style="padding-left:16px;">• {{ $f }}</div>
                                        @empty
                                            <span>Global</span>
                                        @endforelse
                                    </div>
                                    <div style="position:absolute; top:100%; left:20px; margin-left:-4px; border-width:4px; border-style:solid; border-color:#1e293b transparent transparent transparent;"></div>
                                </div>
                            </div>
                        </td>
                        <td class="table-cell-bordered" style="color: var(--maquinaria-gray-text); font-size: 14px; font-weight: 600; padding: 8px 12px; white-space: nowrap;">{{ $user->CORREO_ELECTRONICO }}</td>
                        <td class="table-cell-bordered" style="font-size: 14px; padding: 8px 12px; text-align: left; color: #4a5568; font-weight: 600;">
                            {{ $user->rol->NOMBRE_ROL ?? 'S/R' }}
                        </td>
                        {{-- Dos niveles independientes (equipos / almacén). Se muestran en la
                             misma celda porque casi siempre coinciden; cuando divergen, es justo
                             el dato que el admin necesita ver de un vistazo. --}}
                        <td class="table-cell-bordered" style="font-size: 14px; padding: 8px 12px; text-align: left !important; white-space: nowrap;">
                            <div style="font-size: 14px; line-height: 1.5;">
                                <span style="color: #94a3b8; font-weight: 600;">EQ</span>
                                <span style="color: {{ $user->NIVEL_ACCESO_EQUIPOS == 1 ? '#2c7a7b' : '#6b46c1' }}; font-weight: 600;">
                                    {{ $user->nivel_acceso_equipos_texto }}
                                </span>
                            </div>
                            <div style="font-size: 14px; line-height: 1.5;">
                                <span style="color: #94a3b8; font-weight: 600;">ALM</span>
                                <span style="color: {{ $user->NIVEL_ACCESO_ALMACEN == 1 ? '#2c7a7b' : '#6b46c1' }}; font-weight: 600;">
                                    {{ $user->nivel_acceso_almacen_texto }}
                                </span>
                            </div>
                        </td>
                        <td class="table-cell-bordered" style="font-size: 14px; font-weight: 600; padding: 8px 12px; color: var(--maquinaria-gray-text); white-space: nowrap;">
                            {{ $user->frenteAsignado->NOMBRE_FRENTE ?? 'Global' }}
                        </td>
                        <td class="table-cell-bordered" style="font-size: 14px; padding: 8px 12px; text-align: left !important;">
                            <span style="color: {{ $user->ESTATUS == 'ACTIVO' ? '#2c7a7b' : '#c53030' }}; font-weight: 600;">
                                {{ $user->ESTATUS }}
                            </span>
                        </td>
                        <td style="padding: 4px 12px;">
                            <div style="display: flex; gap: 8px; justify-content: flex-start;">
                                <a href="{{ route('usuarios.edit', $user->ID_USUARIO) }}" 
                                   @cannot('manage.users') 
                                   onclick="event.preventDefault(); showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permisos para editar usuarios.', confirmText: 'Entendido', hideCancel: true });" 
                                   @endcannot
                                   class="btn-action-maquinaria" 
                                   style="color: var(--maquinaria-blue); background: #ebf4ff;" 
                                   title="Editar">
                                    <i class="material-icons" style="font-size: 18px;">edit</i>
                                </a>
                                <button type="button" 
                                    onclick="@can('manage.users') confirmDelete({{ $user->ID_USUARIO }}, '{{ addslashes($user->NOMBRE_COMPLETO) }}') @else showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permisos para eliminar usuarios.', confirmText: 'Entendido', hideCancel: true }); @endcan" 
                                    class="btn-action-maquinaria" 
                                    style="color: var(--maquinaria-red); background: #fff5f5;" 
                                    title="Eliminar">
                                    <i class="material-icons" style="font-size: 18px;">delete</i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="padding: 50px 20px; border: none;">
                            {{-- Flex centrado: garantiza que el icono + texto queden centrados
                                 horizontalmente en todo el ancho de la tabla (el text-align
                                 con el icono en display:block no centraba bien en desktop). --}}
                            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; color: var(--maquinaria-gray-text);">
                                <i class="material-icons" style="font-size: 48px; color: #cbd5e0;">person_off</i>
                                <span style="font-size:14px; text-align:center;">No se encontraron usuarios registrados o con los criterios de búsqueda.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
