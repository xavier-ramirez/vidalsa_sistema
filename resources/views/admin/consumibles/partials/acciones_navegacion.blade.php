{{-- Menú «Acciones» de Consumibles: las otras pantallas del módulo (sin la actual: $actual =
     'lista' | 'graficos' | 'cargar' | 'cupos'), con el estilo del menú de Equipos. Las acciones propias de
     cada pantalla van debajo, en su vista. Cargar se oculta en el teléfono (.hide-on-mobile): su
     tabla no se usa ahí. --}}
@php
    $pantallas = [
        'lista'    => ['ruta' => 'consumibles.index',    'icono' => 'list',      'fondo' => '#e0f2fe', 'color' => '#0284c7', 'texto' => 'Lista de Consumibles', 'movil' => true],
        'graficos' => ['ruta' => 'consumibles.graficos', 'icono' => 'analytics', 'fondo' => '#eff6ff', 'color' => '#3b82f6', 'texto' => 'Gráficos y Reportes',  'movil' => true],
        'cargar'   => ['ruta' => 'consumibles.cargar',   'icono' => 'note_add',  'fondo' => '#fff7ed', 'color' => '#ea580c', 'texto' => 'Cargar consumibles',   'movil' => false],
        'cupos'    => ['ruta' => 'consumibles.cupos',    'icono' => 'local_gas_station', 'fondo' => '#ecfdf5', 'color' => '#059669', 'texto' => 'Cupos de gasoil', 'movil' => true],
    ];
@endphp
@foreach ($pantallas as $clave => $p)
    @continue($clave === $actual)
    <a href="{{ route($p['ruta']) }}" class="dropdown-item-custom{{ $p['movil'] ? '' : ' hide-on-mobile' }}"
        style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
        <div style="background: {{ $p['fondo'] }}; padding: 6px; border-radius: 6px; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: {{ $p['color'] }};">{{ $p['icono'] }}</i>
        </div>
        <span style="font-size: 14px; font-weight: 500;">{{ $p['texto'] }}</span>
    </a>
@endforeach
