{{-- Atar a mano a un equipo lo que la carga masiva no enlazó sola: un PDF (póliza, título…)
     o un VIN del BL. Lo abre window.cpdfAbrirAtar (panel.blade.php) con el documento y lo que se
     leyó; el buscador sugiere equipos al escribir (serial, placa o código, también parecidos) y
     con ↑ ↓ y Enter se elige sin ratón. Si el equipo ya tiene ese documento, se pregunta aquí
     mismo antes de reemplazarlo. Con un PDF de VARIAS unidades (window.cpdfEnlazarAhora) arriba van
     sus equipos, para comprobarlos; el buscador sirve para añadir uno que el PDF nombra y no se
     reconoció (no en un BL ni en un ROTC de flota), y el pie los enlaza a todos. --}}
<div id="cpdfAtarModal" class="cpdf-atar-velo" hidden onclick="if (event.target === this) window.cpdfCerrarAtar()">
    <div class="cpdf-atar-caja" id="cpdfAtarCaja" role="dialog" aria-modal="true" aria-labelledby="cpdfAtarTitulo">
        <div class="cpdf-atar-cab">
            <h3 id="cpdfAtarTitulo"><i class="material-icons">link</i><span id="cpdfAtarTituloTxt">Atar a un equipo</span></h3>
            <button type="button" class="cpdf-atar-x" onclick="window.cpdfCerrarAtar()" aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="cpdf-atar-cuerpo">
            {{-- Lo que se ata, en dos líneas como mucho: el documento y lo leído; debajo la emisión
                 y el vencimiento del PDF, que se comparan con los del que ya tiene cada equipo (esa
                 línea no sale si no trae fechas). Sin el nombre del archivo: ya está en la fila. --}}
            <div class="cpdf-atar-datos">
                <div><span id="cpdfAtarDoc"></span> · <span id="cpdfAtarLeidoEti"></span>
                    <span id="cpdfAtarLeido" class="cpdf-atar-codigo"></span></div>
                <div id="cpdfAtarFechas"></div>
            </div>
            <div id="cpdfAtarSuyos" class="cpdf-atar-lista" hidden></div>
            <label class="cpdf-atar-buscar" id="cpdfAtarBuscarCaja">
                <i class="material-icons">search</i>
                <input type="search" id="cpdfAtarBuscar" placeholder="Serial, placa o código del equipo" autocomplete="off"
                       oninput="window.cpdfAtarEscribe()" onkeydown="window.cpdfAtarTecla(event)">
            </label>
            <div id="cpdfAtarLista" class="cpdf-atar-lista" role="listbox"></div>
        </div>
        <div id="cpdfAtarPie" class="cpdf-atar-pie" hidden>
            <button type="button" class="cpdf-atar-btn gris" onclick="window.cpdfCerrarAtar()">Cancelar</button>
            <button type="button" class="cpdf-atar-btn" id="cpdfAtarEnlazar" onclick="window.cpdfAtarEnlazarTodos()"><i class="material-icons">link</i><span></span></button>
        </div>
    </div>
</div>
