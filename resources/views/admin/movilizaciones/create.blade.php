@extends('layouts.app')

@section('content')
<div class="admin-container">
    <div class="admin-header">
        <h1 class="admin-title">Registrar Movilización</h1>
    </div>

    <div class="admin-card">
        <form action="{{ route('movilizaciones.store') }}" method="POST">
            @csrf
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <!-- Equipo -->
                <div class="form-group">
                    <label for="selectEquipo" class="form-label">Equipo a Movilizar</label>
                    <select name="ID_EQUIPO" id="selectEquipo" class="form-select" required onchange="updateOrigen()">
                        <option value="">Seleccione un equipo...</option>
                        @foreach($equipos as $eq)
                            <option value="{{ $eq->ID_EQUIPO }}" 
                                    data-origen="{{ $eq->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar' }}"
                                    data-confirmado="{{ $eq->CONFIRMADO_EN_SITIO }}">
                                {{ $eq->CODIGO_PATIO }} - {{ $eq->tipo->nombre ?? '' }} {{ $eq->MARCA }} {{ $eq->MODELO }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Origen (Read Only) -->
                <div class="form-group">
                    <label for="inputOrigen" class="form-label">Origen Actual</label>
                    <input type="text" id="inputOrigen" class="form-input" value="--" readonly style="background: #f1f5f9; color: #64748b;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 30px;">
                <!-- Destino -->
                <div class="form-group">
                    <label for="selectFrenteDestino" class="form-label">Frente de Destino</label>
                    <select name="ID_FRENTE_DESTINO" id="selectFrenteDestino" class="form-select" required>
                        <option value="">Seleccione destino...</option>
                        @foreach($frentes as $frente)
                            <option value="{{ $frente->ID_FRENTE }}">{{ $frente->NOMBRE_FRENTE }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-actions" style="display: flex; gap: 15px; justify-content: flex-end;">
                <a href="{{ route('movilizaciones.index') }}" class="btn-cancel">Cancelar</a>
                <button type="submit" class="btn-submit">Registrar Salida</button>
            </div>
        </form>
    </div>
</div>

<script>
    function updateOrigen() {
        const select = document.getElementById('selectEquipo');
        const option = select.options[select.selectedIndex];
        const origen = option.getAttribute('data-origen');
        document.getElementById('inputOrigen').value = origen || '--';
    }
</script>
@endsection
