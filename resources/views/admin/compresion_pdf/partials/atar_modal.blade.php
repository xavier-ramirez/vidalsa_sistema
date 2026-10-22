{{-- Enlazar a mano a un equipo lo que la carga masiva no enlazó sola: un PDF (póliza, título…),
     un VIN del BL o una unidad de un PDF de varias. Lo abre window.cpdfAbrirAtar (panel.blade.php) con el documento y lo que se
     leyó; el buscador sugiere equipos al escribir (serial, placa o código, también parecidos) y
     con ↑ ↓ y Enter se elige sin ratón. Si el equipo ya tiene ese documento, se pregunta aquí
     mismo antes de reemplazarlo. --}}
<div id="cpdfAtarModal" class="cpdf-atar-velo" hidden onclick="if (event.target === this) window.cpdfCerrarAtar()">
    <div class="cpdf-atar-caja" role="dialog" aria-modal="true" aria-labelledby="cpdfAtarTitulo">
        <div class="cpdf-atar-cab">
            <h3 id="cpdfAtarTitulo"><i class="material-icons">link</i>Enlazar a un equipo</h3>
            <button type="button" class="cpdf-atar-x" onclick="window.cpdfCerrarAtar()" aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="cpdf-atar-cuerpo">
            {{-- Lo que se enlaza, en dos líneas como mucho: el documento y lo leído; debajo la emisión
                 y el vencimiento del PDF, que se comparan con los del que ya tiene cada equipo (esa
                 línea no sale si no trae fechas). Sin el nombre del archivo: ya está en la fila. Al
                 lado, "Ver PDF": el visor se abre encima y al cerrarlo se sigue aquí, para comparar
                 el PDF con las sugerencias (pedido del cliente, 21-10-2026). --}}
            <div class="cpdf-atar-datos">
                <div class="cpdf-atar-datos-txt">
                    <div><span id="cpdfAtarDoc"></span> · <span id="cpdfAtarLeidoEti"></span>
                        <span id="cpdfAtarLeido" class="cpdf-atar-codigo"></span></div>
                    <div id="cpdfAtarFechas"></div>
                </div>
                <button type="button" class="cpdf-atar-pdf" onclick="window.cpdfAtarVerPdf()"><i class="material-icons">description</i>Ver PDF</button>
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
