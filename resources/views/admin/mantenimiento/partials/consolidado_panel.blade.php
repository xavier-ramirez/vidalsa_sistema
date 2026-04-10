{{-- Consolidado Nacional Panel - AJAX rendered --}}
<div>
    <!-- Stats Summary -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:20px;">
        <div class="mant-stat-card">
            <div class="mant-stat-icon red"><i class="material-icons">warning</i></div>
            <div>
                <div class="mant-stat-label">Total Fallas</div>
                <div class="mant-stat-value">{{ $totalFallas }}</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon amber"><i class="material-icons">error_outline</i></div>
            <div>
                <div class="mant-stat-label">Abiertas</div>
                <div class="mant-stat-value">{{ $fallasAbiertas }}</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon green"><i class="material-icons">check_circle</i></div>
            <div>
                <div class="mant-stat-label">Resueltas</div>
                <div class="mant-stat-value">{{ $fallasResueltas }}</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon blue"><i class="material-icons">location_on</i></div>
            <div>
                <div class="mant-stat-label">Frentes Activos</div>
                <div class="mant-stat-value">{{ $reportes->count() }}</div>
            </div>
        </div>
    </div>

    @if($totalFallas === 0)
        <div class="mant-empty">
            <i class="material-icons">check_circle</i>
            <p>No se registraron fallas para el {{ \Carbon\Carbon::parse($fecha)->format('d/m/Y') }}</p>
        </div>
    @else

    <!-- Desglose por Frente -->
    <div class="mant-card">
        <div class="mant-card-header">
            <span class="mant-card-title"><i class="material-icons">business</i> Desglose por Frente — {{ \Carbon\Carbon::parse($fecha)->format('d/m/Y') }}</span>
        </div>
        <table class="mant-table">
            <thead>
                <tr>
                    <th>Frente</th>
                    <th>Total</th>
                    <th>Abiertas</th>
                    <th>Resueltas</th>
                    <th>Proporción</th>
                </tr>
            </thead>
            <tbody>
                @foreach($porFrente as $pf)
                <tr>
                    <td style="font-weight:700;">{{ $pf['frente'] }}</td>
                    <td style="font-weight:800;">{{ $pf['total'] }}</td>
                    <td>
                        @if($pf['abiertas'] > 0)
                            <span class="badge-estado abierta">{{ $pf['abiertas'] }}</span>
                        @else
                            <span style="color:#16a34a; font-weight:700;">0</span>
                        @endif
                    </td>
                    <td><span style="color:#16a34a; font-weight:700;">{{ $pf['resueltas'] }}</span></td>
                    <td style="width:200px;">
                        @php $pct = $totalFallas > 0 ? round($pf['total'] / $totalFallas * 100) : 0; @endphp
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1; height:8px; background:#f1f5f9; border-radius:4px; overflow:hidden;">
                                <div style="width:{{ $pct }}%; height:100%; background:linear-gradient(90deg,#3b82f6,#0067b1); border-radius:4px;"></div>
                            </div>
                            <span style="font-size:11px; font-weight:700; color:#64748b; min-width:32px;">{{ $pct }}%</span>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Desglose por Tipo de Falla -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="mant-card">
            <div class="mant-card-header">
                <span class="mant-card-title"><i class="material-icons">category</i> Por Tipo</span>
            </div>
            @foreach($porTipo as $tipo => $count)
            <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f8fafc;">
                <span style="font-size:13px; font-weight:600; color:#334155;">{{ $tipo }}</span>
                <span style="font-size:14px; font-weight:800; color:#1e293b;">{{ $count }}</span>
            </div>
            @endforeach
        </div>

        <div class="mant-card">
            <div class="mant-card-header">
                <span class="mant-card-title"><i class="material-icons">flag</i> Por Prioridad</span>
            </div>
            @php
                $prioColors = ['CRITICA' => '#dc2626', 'ALTA' => '#ea580c', 'MEDIA' => '#ca8a04', 'BAJA' => '#16a34a'];
            @endphp
            @foreach($porPrioridad as $prio => $count)
            <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f8fafc;">
                <span class="badge-prioridad {{ strtolower($prio) }}">{{ $prio }}</span>
                <span style="font-size:14px; font-weight:800; color:#1e293b;">{{ $count }}</span>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Listado completo de fallas del día -->
    <div class="mant-card" style="margin-top:16px;">
        <div class="mant-card-header">
            <span class="mant-card-title"><i class="material-icons">list</i> Todas las Fallas del {{ \Carbon\Carbon::parse($fecha)->format('d/m/Y') }}</span>
        </div>
        <table class="mant-table">
            <thead>
                <tr>
                    <th>Frente</th>
                    <th style="text-align:center;">Foto</th>
                    <th>Equipo</th>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th>Prioridad</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach($reportes as $rep)
                    @foreach($rep->fallas as $f)
                    <tr>
                        <td style="font-size:12px; font-weight:600;">{{ $rep->frente->NOMBRE_FRENTE ?? '' }}</td>
                        <td style="text-align:center; width:80px;">
                            @php
                                $fotoCons = ($f->equipo->especificaciones && $f->equipo->especificaciones->FOTO_REFERENCIAL)
                                    ? $f->equipo->especificaciones->FOTO_REFERENCIAL
                                    : $f->equipo->FOTO_EQUIPO;
                            @endphp
                            @if($fotoCons)
                                <div class="table-image-wrapper" style="width:70px; height:45px; margin:0 auto;">
                                    <img src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $fotoCons)]) }}"
                                        alt="Equipo" loading="lazy" onload="this.style.opacity='1'" style="opacity:0;">
                                </div>
                            @else
                                <div class="table-image-wrapper placeholder" style="width:70px; height:45px; margin:0 auto;">
                                    <span class="material-icons" style="font-size:18px;">image_not_supported</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            <div style="font-size:12px; font-weight:700;">{{ $f->equipo->tipo->nombre ?? 'S/T' }}</div>
                            <div style="font-size:11px; color:#64748b;">{{ $f->equipo->MARCA ?? '' }} {{ $f->equipo->MODELO ?? '' }}</div>
                        </td>
                        <td style="font-size:12px;">{{ $f->TIPO_FALLA }}</td>
                        <td style="font-size:12px; max-width:200px;">
                            <div style="overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">{{ $f->DESCRIPCION_FALLA }}</div>
                        </td>
                        <td><span class="badge-prioridad {{ strtolower($f->PRIORIDAD) }}">{{ $f->PRIORIDAD }}</span></td>
                        <td><span class="badge-estado {{ strtolower(str_replace(' ', '_', $f->ESTADO_FALLA)) }}">{{ str_replace('_', ' ', $f->ESTADO_FALLA) }}</span></td>
                    </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    @endif
</div>
