@forelse($catalogos as $catalogo)
    <tr>
        <td class="table-cell-custom table-cell-center table-cell-bordered" style="padding: 0; width: 160px;">
            @if($catalogo->FOTO_REFERENCIAL)
                <div class="table-image-wrapper" style="width: 100%; height: 90px; display: flex; align-items: center; justify-content: center;">
                    <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                         data-src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $catalogo->FOTO_REFERENCIAL)]) }}" 
                         alt="Foto" 
                         class="lazy-catalog-img"
                         style="max-height: 100%; max-width: 100%; object-fit: contain; opacity: 0; transition: opacity 0.3s;">
                </div>
            @else
                <div class="table-image-wrapper placeholder" style="width: 100%; height: 90px; display: flex; align-items: center; justify-content: center;">
                    <i class="material-icons" style="font-size: 24px;">image_not_supported</i>
                </div>
            @endif
        </td>
        <td class="table-cell-custom table-cell-bordered" data-label="Modelo / Año">
            <!-- Desktop View -->
            <div class="desktop-only-flex" style="display: flex; flex-direction: column; gap: 6px;">
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Modelo</div>
                    <div class="catalog-Model" style="font-size: 14px; font-weight: 800; color: #1e293b;">{{ $catalogo->MODELO }}</div>
                </div>
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Año</div>
                    <div style="font-size: 14px; font-weight: 800; color: #1e293b;">{{ $catalogo->ANIO_ESPEC }}</div>
                </div>
            </div>
            <!-- Mobile View -->
            <div class="mobile-only-grid" style="display: none; grid-template-columns: 1fr 1fr; gap: 6px 10px;">
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Modelo</div>
                    <div class="catalog-Model" style="font-size: 12px; font-weight: 800; color: #1e293b; overflow-wrap: break-word;">{{ $catalogo->MODELO }}</div>
                </div>
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Motor</div>
                    <div style="font-size: 12px; font-weight: 800; color: #1e293b; overflow-wrap: break-word;">{{ $catalogo->MOTOR ?: 'N/A' }}</div>
                </div>
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Año</div>
                    <div style="font-size: 12px; font-weight: 800; color: #1e293b;">{{ $catalogo->ANIO_ESPEC }}</div>
                </div>
                <div>
                    <div class="catalog-label" style="font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Combustible</div>
                    <div style="font-size: 12px; font-weight: 800; color: #1e293b; overflow-wrap: break-word;">{{ $catalogo->COMBUSTIBLE ?: '-' }}</div>
                </div>
            </div>
        </td>
        <td class="table-cell-custom table-cell-bordered" data-label="Motor / Energía / Consumo">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px 10px;">
                <div>
                    <div class="catalog-label">Motor</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->MOTOR ?: 'N/A' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Combustible</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->COMBUSTIBLE ?: '-' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Batería</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->TIPO_BATERIA ?: '-' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Consumo</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->CONSUMO_PROMEDIO ? $catalogo->CONSUMO_PROMEDIO . ' L/DIA' : '-' }}</div>
                </div>
            </div>
        </td>
        <td class="table-cell-custom table-cell-bordered" data-label="Mantenimiento">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px 10px;">
                <div>
                    <div class="catalog-label">Aceite Motor:</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->ACEITE_MOTOR ?: '-' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Aceite Caja:</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->ACEITE_CAJA ?: '-' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Liga Freno:</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->LIGA_FRENO ?: '-' }}</div>
                </div>
                <div>
                    <div class="catalog-label">Refrigerante:</div>
                    <div style="font-size: 15px; color: #000;">{{ $catalogo->REFRIGERANTE ?: '-' }}</div>
                </div>
            </div>
        </td>
        <td style="padding: 4px 12px; text-align: center;" data-label="Acciones">
            <div style="display: flex; gap: 8px; justify-content: center; width: 100%;">
                <a href="{{ route('catalogo.edit', $catalogo->ID_ESPEC) }}" class="btn-details-mini" title="Editar" style="flex: 1; background: #ebf4ff; color: var(--maquinaria-blue);">
                    <i class="material-icons">edit</i>
                </a>
                @can('equipos.assign')
                <div style="flex: 1;">
                    <button type="button"
                        onclick="confirmDeleteCatalogo('{{$catalogo->ID_ESPEC}}', '{{ addslashes($catalogo->MODELO) }}')"
                        class="btn-details-mini"
                        style="background: #fee2e2; color: #ef4444; width: 100%; height: 35px; border: none; display: flex; align-items: center; justify-content: center; border-radius: 6px;"
                        title="Eliminar">
                        <i class="material-icons">delete</i>
                    </button>
                </div>
                @endcan
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">
            <i class="material-icons" style="font-size: 48px; display: block; margin-bottom: 10px;">inventory_2</i>
            No hay modelos registrados que coincidan con los filtros.
        </td>
    </tr>
@endforelse
