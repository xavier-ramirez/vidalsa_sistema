{{-- Contador flotante de filas elegidas, el MISMO en las tres pestañas de Control de Auditoría
     (Historial, Revisión y Compresión). Tocar el número muestra solo las elegidas (y vuelve a
     tocarlo, todas); "Limpiar" suelta la selección. Cada pestaña pone su id y sus funciones:
       $id        id del chip; el número lleva el id "$id-cuenta"
       $alTocar   función de "ver solo las elegidas" (recibe el evento)
       $alLimpiar función de "Limpiar"
       $revisado  (opcional) con true, el botón "Revisado" de la Revisión de documentos --}}
<div id="{{ $id }}" class="selection-floating-bar hd-chip-sel">
    <div class="selection-counter" onclick="{{ $alTocar }}(event)" style="cursor: pointer;" title="Ver solo las filas elegidas">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="{{ $id }}-cuenta" class="hd-chip-cuenta">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <button type="button" class="btn-bulk-clear" onclick="{{ $alLimpiar }}()">Limpiar</button>
    @if (!empty($revisado))
        <button type="button" class="btn-bulk-action" id="{{ $id }}-revisado" onclick="window.cpdfMarcarRevisadas()" hidden>
            <i class="material-icons">done_all</i> Revisado
        </button>
    @endif
</div>
