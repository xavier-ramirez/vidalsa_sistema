<style>
    .stat-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid transparent;
        margin-bottom: 6px;
    }
    .stat-item:hover {
        background-color: #f8fafc;
        border-color: #e2e8f0;
    }
    .stat-name {
        font-size: 13px;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 180px;
    }
    .stat-count {
        background: #f1f5f9;
        color: #64748b;
        font-weight: 700;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
    }
</style>

<!-- Main Total Card -->
<div style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden; margin-bottom: 8px;">
    <!-- Abstract Shapes for "Dynamic" look -->
    <div style="position: absolute; top: -10px; right: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
    <div style="position: absolute; bottom: 10px; left: -10px; width: 40px; height: 40px; background: rgba(255,255,255,0.05); border-radius: 50%;"></div>
    
    <div style="position: relative; z-index: 2;">
        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; color: #cbd5e0;">
            <i class="material-icons" style="font-size: 14px;">dataset</i>
            Total Registros
        </div>
        
        <div style="display: flex; align-items: baseline; gap: 4px;">
            <span style="font-size: 42px; font-weight: 800; line-height: 1; letter-spacing: -1px;">
                {{ $totalCount }}
            </span>
            <span style="font-size: 14px; opacity: 0.8; font-weight: 500;">Modelos</span>
        </div>
    </div>
</div>

<!-- Desglose por clase (Vehículos / Auxiliares) -->
<div style="background: white; border-radius: 12px; padding: 12px 15px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); display: flex; flex-direction: column;">
    <div style="display: flex; align-items: center; margin-bottom: 12px;">
        <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
            <i class="material-icons" style="font-size: 16px; color: #3b82f6;">category</i>
            Por Clase
        </h4>
    </div>

    <div class="stat-item">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 6px; height: 6px; border-radius: 50%; background: #0067b1;"></div>
            <span class="stat-name">Vehículos</span>
        </div>
        <span class="stat-count">{{ $countVehiculos ?? 0 }}</span>
    </div>
    <div class="stat-item">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 6px; height: 6px; border-radius: 50%; background: #c2410c;"></div>
            <span class="stat-name">Auxiliares</span>
        </div>
        <span class="stat-count">{{ $countAuxiliares ?? 0 }}</span>
    </div>
</div>
