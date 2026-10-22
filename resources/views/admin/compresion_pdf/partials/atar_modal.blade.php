{{-- Enlazar a mano a un equipo lo que la carga masiva no enlazó sola: un PDF (póliza, título…),
     un VIN del BL o una unidad de un PDF de varias. window.cpdfAbrirAtar (panel.blade.php) abre el
     PDF en el visor y monta esta caja A SU LADO (pedido del cliente, 21-10-2026: ver el documento
     mientras se elige el equipo), con el aspecto del panel de datos del visor; al cerrar el visor
     vuelve aquí, escondida. Arriba el documento y
     lo leído; el buscador sugiere equipos al escribir (serial, placa o código, también parecidos)
     y con ↑ ↓ y Enter se elige sin ratón. Si el equipo ya tiene ese documento, se pregunta aquí
     mismo antes de reemplazarlo. --}}
<div id="cpdfAtarCasa" hidden>
    <div class="cpdf-atar-caja" id="cpdfAtarCaja" role="dialog" aria-labelledby="cpdfAtarTitulo">
        <div class="cpdf-atar-cuerpo">
            {{-- Sin X propia: la caja se cierra con el visor (su X, arriba). --}}
            <h3 id="cpdfAtarTitulo" class="cpdf-atar-tit">Enlazar a un equipo</h3>
            {{-- Lo que se enlaza, con rótulo y valor como los datos de cada equipo: el documento y lo
                 leído; debajo la emisión y el vencimiento del PDF, que se comparan con los del que ya
                 tiene cada equipo (la fecha que el PDF no trae no sale). Sin el nombre del archivo:
                 ya está en el título del visor y en la fila. --}}
            <div class="cpdf-atar-datos">
                <div class="cpdf-atar-campo"><span class="cpdf-atar-rot">Documento</span><span class="cpdf-atar-val" id="cpdfAtarDoc"></span></div>
                <div class="cpdf-atar-campo"><span class="cpdf-atar-rot" id="cpdfAtarLeidoEti"></span><span class="cpdf-atar-val cpdf-atar-codigo" id="cpdfAtarLeido"></span></div>
                <div class="cpdf-atar-campo" id="cpdfAtarEmisionCampo"><span class="cpdf-atar-rot">Emisión</span><span class="cpdf-atar-val" id="cpdfAtarEmision"></span></div>
                <div class="cpdf-atar-campo" id="cpdfAtarVenceCampo"><span class="cpdf-atar-rot">Vence</span><span class="cpdf-atar-val" id="cpdfAtarVence"></span></div>
            </div>
            <label class="cpdf-atar-buscar">
                <i class="material-icons">search</i>
                <input type="search" id="cpdfAtarBuscar" placeholder="Serial, placa o código del equipo" autocomplete="off"
                       oninput="window.cpdfAtarEscribe()" onkeydown="window.cpdfAtarTecla(event)">
            </label>
            <div id="cpdfAtarLista" class="cpdf-atar-lista" role="listbox"></div>
        </div>
    </div>
</div>
