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
            {{-- Lo que se ata, dato a dato (rótulo y valor). Una fila sin valor no sale. La emisión
                 y el vencimiento del PDF se comparan con los del que ya tiene cada equipo. --}}
            <dl class="cpdf-atar-datos">
                <dt>Documento</dt><dd id="cpdfAtarDoc"></dd>
                <dt>Archivo</dt><dd id="cpdfAtarArchivo"></dd>
                <dt id="cpdfAtarLeidoEti"></dt><dd id="cpdfAtarLeido" class="cpdf-atar-codigo"></dd>
                <dt>Emisión</dt><dd id="cpdfAtarEmision"></dd>
                <dt>Vence</dt><dd id="cpdfAtarVence"></dd>
            </dl>
            <label class="cpdf-atar-buscar">
                <i class="material-icons">search</i>
                <input type="search" id="cpdfAtarBuscar" placeholder="Serial, placa o código del equipo" autocomplete="off"
                       oninput="window.cpdfAtarEscribe()" onkeydown="window.cpdfAtarTecla(event)">
            </label>
            <div id="cpdfAtarLista" class="cpdf-atar-lista" role="listbox"></div>
        </div>
    </div>
</div>
