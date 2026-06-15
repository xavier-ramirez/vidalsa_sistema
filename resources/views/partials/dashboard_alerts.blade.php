@php
    $grouped = $expiredList->groupBy('status');
    $vencidas = $grouped->get('expired', collect());
    $proximas = $grouped->get('warning', collect());
@endphp

@if($vencidas->isNotEmpty())
    <div class="js-alert-section-header" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 15px; background: #fef2f2; color: #991b1b; border-bottom: 1px solid #fecaca;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="material-icons" style="font-size: 18px; color: #dc2626;">notifications</i>
            <span style="font-weight: 600; font-size: 0.85rem; letter-spacing: 0.3px;">Vencidas</span>
        </div>
        <span style="background: rgba(248, 113, 113, 0.15); padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 700;">{{ $vencidas->count() }}</span>
    </div>
    @foreach($vencidas as $alert)
        @include('partials.alert_item', ['alert' => $alert])
    @endforeach
@endif

@if($proximas->isNotEmpty())
    <div class="js-alert-section-header" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 15px; background: #fffbeb; color: #92400e; border-bottom: 1px solid #fcd34d; {{ $vencidas->isNotEmpty() ? 'margin-top: 5px; border-top: 1px solid #e5e7eb;' : '' }}">
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="material-icons" style="font-size: 18px; color: #d97706;">notifications</i>
            <span style="font-weight: 600; font-size: 0.85rem; letter-spacing: 0.3px;">Próximas a Vencer</span>
        </div>
        <span style="background: rgba(251, 191, 36, 0.2); padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 700;">{{ $proximas->count() }}</span>
    </div>
    @foreach($proximas as $alert)
        @include('partials.alert_item', ['alert' => $alert])
    @endforeach
@endif

@if($expiredList->isEmpty())
    <div class="empty-state">
        <i class="material-icons" style="color: #cbd5e0;">check_circle</i>
        <p>Todos los documentos están vigentes.</p>
    </div>
@endif
