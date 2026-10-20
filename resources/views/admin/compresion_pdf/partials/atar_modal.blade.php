{{-- Atar a mano a un equipo lo que la carga masiva no enlazó sola: un PDF (póliza, título…)
     o un VIN del BL. Lo abre window.cpdfAbrirAtar (panel.blade.php) con el documento y lo que se
     leyó; el buscador sugiere equipos al escribir (serial, placa o código, también parecidos) y
     con ↑ ↓ y Enter se elige sin ratón. Si el equipo ya tiene ese documento, se pregunta aquí
     mismo antes de reemplazarlo. --}}
<div id="cpdfAtarModal" class="cpdf-atar-velo" hidden onclick="if (event.target === this) window.cpdfCerrarAtar()">
    <div class="cpdf-atar-caja" role="dialog" aria-modal="true" aria-labelledby="cpdfAtarTitulo">
        <div class="cpdf-atar-cab">
            <h3 id="cpdfAtarTitulo"><i class="material-icons">link</i>Atar a un equipo</h3>
            <button type="button" class="cpdf-atar-x" onclick="window.cpdfCerrarAtar()" aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="cpdf-atar-cuerpo">
            {{-- Una fila: el documento a la izquierda y lo leído a la derecha. --}}
            <div class="cpdf-atar-top">
                <div class="cpdf-atar-doc">
                    <span class="cpdf-atar-ico"><i class="material-icons">description</i></span>
                    <div>
                        <strong id="cpdfAtarDoc"></strong>
                        <span id="cpdfAtarArchivo"></span>
                    </div>
                </div>
                <div class="cpdf-atar-leido">
                    <span id="cpdfAtarLeidoEti"></span>
                    <b id="cpdfAtarLeido"></b>
                </div>
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
