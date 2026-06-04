                @forelse($users as $user)
                    <tr>
                         <td class="table-cell-bordered" style="font-weight: 700; color: var(--maquinaria-dark-blue); padding: 8px 12px; white-space: nowrap;">
                            {{-- Tooltip estilizado (igual que el "detalle" de equipos): al
                                 pasar/enfocar la fila se muestra la fecha de creación. El CSS
                                 global `.admin-table tr:hover .tooltip-bubble` lo dispara. --}}
                            <div class="tooltip-wrapper" style="position: relative; cursor: default; display: inline-block;">
                                {{ $user->NOMBRE_COMPLETO }}
                                <div class="tooltip-bubble" style="pointer-events:none; opacity:0; visibility:hidden; position:absolute; bottom:100%; left:0; transform:translateY(5px); background:#1e293b; color:#fff; padding:6px 10px; border-radius:6px; font-size:11px; font-weight:500; white-space:nowrap; width:max-content; text-align:center; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); transition:all 0.2s ease-in-out; z-index:50; margin-bottom:5px;">
                                    📅 Creado: {{ optional($user->created_at)->format('d/m/Y H:i') ?: 'sin fecha' }}
                                    <div style="position:absolute; top:100%; left:20px; margin-left:-4px; border-width:4px; border-style:solid; border-color:#1e293b transparent transparent transparent;"></div>
                                </div>
                            </div>
                        </td>
                        <td class="table-cell-bordered" style="color: var(--maquinaria-gray-text); font-size: 14px; padding: 8px 12px; white-space: nowrap;">{{ $user->CORREO_ELECTRONICO }}</td>
                        <td class="table-cell-bordered" style="padding: 8px 12px; text-align: left; color: #4a5568; font-weight: 600;">
                            {{ $user->rol->NOMBRE_ROL ?? 'S/R' }}
                        </td>
                        <td class="table-cell-bordered" style="padding: 8px 12px; text-align: left !important; white-space: nowrap;">
                            <span style="color: {{ $user->NIVEL_ACCESO == 1 ? '#2c7a7b' : '#6b46c1' }}; font-weight: 700;">
                                {{ $user->nivel_acceso_texto }}
                            </span>
                        </td>
                        <td class="table-cell-bordered" style="padding: 8px 12px; color: var(--maquinaria-gray-text); white-space: nowrap;">
                            {{ $user->frenteAsignado->NOMBRE_FRENTE ?? 'Global' }}
                        </td>
                        <td class="table-cell-bordered" style="padding: 8px 12px; text-align: left !important;">
                            <span style="color: {{ $user->ESTATUS == 'ACTIVO' ? '#2c7a7b' : '#c53030' }}; font-weight: 700;">
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
                        <td colspan="7" style="text-align: center; padding: 50px; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 48px; display: block; margin-bottom: 10px; color: #cbd5e0;">person_off</i>
                            No se encontraron usuarios registrados o con los criterios de búsqueda.
                        </td>
                    </tr>
                @endforelse
