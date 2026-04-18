@extends('layouts.estructura_base')

@section('content')
<style>
    .audit-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px;
    }
    .audit-table th {
        background: #f8fafc;
        color: #475569;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        padding: 12px 16px;
        text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    .audit-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        font-size: 14px;
        vertical-align: middle;
    }
    .audit-table tr:hover td {
        background: #f8fafc;
    }
    .badge-doc {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    .badge-autor {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
    }
    .btn-view-pdf {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-view-pdf:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
</style>

<div style="padding: 24px; max-width: 1400px; margin: 0 auto; height: 100%; display: flex; flex-direction: column;">
    
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-shrink: 0;">
        <div>
            <h1 style="margin: 0; font-size: 24px; color: #0f172a; font-weight: 800; display: flex; align-items: center; gap: 10px;">
                <i class="material-icons" style="color: #3b82f6;">history</i>
                Auditoría de Documentos PDF
            </h1>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 14px;">
                Registro cronológico de los documentos cargados al sistema por los usuarios.
            </p>
        </div>
    </div>

    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; border: 1px solid #e2e8f0;">
        <div style="flex: 1; overflow: auto;">
            <table class="audit-table">
                <thead style="position: sticky; top: 0; z-index: 10;">
                    <tr>
                        <th style="width: 15%;">Fecha y Hora</th>
                        <th style="width: 25%;">Autor</th>
                        <th style="width: 20%;">Tipo de Documento</th>
                        <th style="width: 30%;">Equipo Asociado</th>
                        <th style="width: 10%; text-align: center;">Ver PDF</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600;">{{ $event->fecha->format('d/m/Y') }}</span>
                                    <span style="font-size: 12px; color: #94a3b8;">{{ $event->fecha->format('h:i A') }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge-autor">
                                    <i class="material-icons" style="font-size: 16px;">person</i>
                                    {{ $event->autor }}
                                </span>
                            </td>
                            <td>
                                <span class="badge-doc">
                                    <i class="material-icons" style="font-size: 16px;">description</i>
                                    {{ $event->tipo }}
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #334155; line-height: 1.3;">{{ $event->equipo_nombre }}</div>
                                <div style="font-size: 12px; color: #94a3b8;">Ref ID: {{ $event->equipo_id }}</div>
                            </td>
                            <td style="text-align: center;">
                                @if($event->link)
                                    <button type="button" class="btn-view-pdf" onclick="openPdfPreview('{{ $event->link }}', '{{ strtolower(str_replace(' ', '_', $event->tipo)) }}', '{{ $event->tipo }}', '{{ $event->equipo_id }}')" title="Visualizar Documento">
                                        <i class="material-icons" style="font-size: 20px;">picture_as_pdf</i>
                                    </button>
                                @else
                                    <span style="color: #cbd5e1; font-size: 12px;">N/A</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                    <i class="material-icons" style="font-size: 48px; opacity: 0.3; margin: 0 auto;">inbox</i>
                                    <span>No hay registro de documentos actualizados todavía.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <div style="padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 13px; text-align: right;">
            Se muestran los últimos registros de subida en la base de datos.
        </div>
    </div>
</div>
@endsection
