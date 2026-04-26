@extends('layouts.estructura_base')
@section('title', 'Reporte de Fallas')

@section('content')
<div class="dashboard-container" style="padding: 40px 20px; position: relative; z-index: 1;">
    <div style="max-width: 560px; margin: 60px auto; text-align: center; background: white; border-radius: 18px; padding: 40px 30px; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06), 0 18px 40px -16px rgba(15, 23, 42, 0.2); border: 1px solid #e2e8f0;">
        <div style="display: inline-flex; align-items: center; justify-content: center; width: 72px; height: 72px; background: #fffbeb; border-radius: 50%; margin-bottom: 18px;">
            <i class="material-icons" style="font-size: 40px; color: #d97706;">report_problem</i>
        </div>

        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1e293b;">
            Reporte de Fallas
        </h1>
        <p style="margin: 0 0 18px 0; font-size: 13px; color: #64748b; letter-spacing: 0.3px;">
            MÓDULO EN CONSTRUCCIÓN
        </p>

        <div style="background: #f8fafc; border-left: 4px solid #f59e0b; padding: 14px 18px; text-align: left; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.5;">
                Este módulo está pendiente de definición de alcance.
                Registrará fallas operativas y de mantenimiento de los equipos
                con seguimiento de estado (abierta, en proceso, cerrada), severidad
                y material asociado.
            </p>
        </div>

        <div style="font-size: 12px; color: #94a3b8; line-height: 1.5;">
            Mientras tanto puedes:<br>
            <a href="{{ route('equipos.index') }}" style="color: #0067b1; text-decoration: none; font-weight: 600;">
                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">agriculture</i>
                Ir al listado de Equipos
            </a>
            &nbsp;·&nbsp;
            <a href="{{ route('menu') }}" style="color: #0067b1; text-decoration: none; font-weight: 600;">
                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">home</i>
                Volver al Menú
            </a>
        </div>
    </div>
</div>
@endsection
