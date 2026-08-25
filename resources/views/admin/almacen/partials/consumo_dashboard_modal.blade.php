{{-- ════════════════════════════════════════════════════════════════════════
     Modal "Dashboard de Consumo" — COMPARTIDO por /admin/almacen y
     /admin/almacen/movimientos (ambos lo incluyen y lo abren desde su botón
     Acciones con window.abrirConsumoDashboard()).

     INDEPENDIENTE de los filtros generales del módulo: tiene sus PROPIOS filtros
     (categoría + rango de meses Desde/Hasta). Datos: GET almacen.consumoDashboard
     (JSON) — consumo real (SALIDA) de todos los almacenes visibles.

     Chart.js lo pide este modal al abrirse, con window.ensureChartJS() — ya no viene
     del layout, que lo cargaba en todas las páginas para tres pantallas.
     Las funciones se cuelgan de window para sobrevivir la navegación SPA.
═══════════════════════════════════════════════════════════════════════════ --}}
<style>
    /* TIPOGRAFÍA DE LOS CONTROLES — UNA sola regla para todo el modal.
       Los <input>, <select>, <button> y <textarea> NO heredan la fuente de la página: el
       navegador les impone la suya de sistema. Por eso los filtros de este modal salían en
       Arial/Segoe mientras el resto de la app va en Nunito, y el mismo filtro se veía con
       otra letra que en /admin/equipos —allí los controles son .dropdown-trigger, que ya
       lleva font-family:inherit en estilos_globales.css por este mismo motivo (está
       documentado ahí para los <button>)—.
       Va aquí, con el modal como ámbito, y NO repetida en cada control: afecta de una vez a
       Descripción, Categoría, Frente de destino, los dos selectores de mes y los botones,
       y un control nuevo lo hereda sin que haya que acordarse. */
    #consumoDashModal input,
    #consumoDashModal select,
    #consumoDashModal button,
    #consumoDashModal textarea { font-family:inherit; }

    .cdash-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10050; align-items:flex-start; justify-content:center; padding:24px 14px; overflow-y:auto; }
    .cdash-overlay.open { display:flex; }
    .cdash-modal { background:#f1f5f9; border-radius:16px; width:100%; max-width:980px; box-shadow:0 20px 40px -12px rgba(0,0,0,0.35); overflow:hidden; animation:slideDown .2s ease-out; }
    .cdash-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 20px; background:#fff; border-bottom:1px solid #e2e8f0; }
    .cdash-head h3 { margin:0; font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:9px; }
    .cdash-head h3 .material-icons { color:var(--maquinaria-blue,#0067b1); }
    .cdash-x { cursor:pointer; color:#64748b; border:none; background:transparent; display:flex; padding:4px; border-radius:8px; transition:background .15s; }
    .cdash-x:hover { background:#f1f5f9; color:#0f172a; }
    .cdash-body { padding:18px 20px 22px; }
    /* Barra de filtros PROPIA del dashboard (no depende de los filtros del módulo). */
    .cdash-filtros { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px; margin-bottom:16px; }
    .cdash-filtros .f-group { display:flex; flex-direction:column; gap:3px; min-width:0; }
    /* Descripción y Categoría comparten el MISMO ancho: son los dos filtros principales
       y la fila se lee pareja. Crecen juntos al ensanchar el modal. */
    .cdash-filtros .f-group-desc,
    .cdash-filtros .f-group-cat { flex:1 1 220px; }
    .cdash-filtros input[type="month"] { box-sizing:border-box; height:36px; width:130px; max-width:100%; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; background:#fff; outline:none; cursor:pointer; }
    /* El navegador dibuja el <input type="month"> en español como "septiembre de 2026"
       (vacío: "---------- de ----"). Esta pseudo oculta ESE separador "de" nativo; los
       campos de mes y año siguen visibles. Solo WebKit/Blink (Chrome/Edge/Safari). */
    .cdash-filtros input[type="month"]::-webkit-datetime-edit-text { color:transparent; }
    /* Caja de texto compartida por Descripción y Categoría (mismo look). */
    .cdash-inp-box { display:flex; align-items:center; height:36px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; overflow:hidden; }
    .cdash-inp-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .cdash-inp-box input { flex:1; border:none; background:transparent; outline:none; padding:0 8px; font-size:13px; color:#0f172a; min-width:0; }
    .cdash-inp-box i.material-icons { padding:0 6px; color:#64748b; font-size:16px; }
    .cdash-inp-box i.clr { cursor:pointer; }
    .cdash-cat-wrap { position:relative; width:100%; min-width:150px; }
    .cdash-cat-box { cursor:pointer; }
    .cdash-cat-box input { cursor:pointer; }
    .cdash-cat-list { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 10px 22px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; padding:4px; z-index:20; }
    .cdash-cat-list.open { display:block; }
    .cdash-cat-item { padding:7px 10px; border-radius:6px; font-size:13px; font-weight:600; color:#1e293b; cursor:pointer; }
    .cdash-cat-item:hover { background:#f0f4f8; }
    /* Filtro de CATEGORÍA en mayúsculas (placeholder, texto tecleado y opciones). */
    #cdashCatInput { text-transform: uppercase; }
    #cdashCatInput::placeholder { text-transform: uppercase; }
    #cdashCatList .cdash-cat-item { text-transform: uppercase; }
    /* Filtros avanzados: boton cuadrado + panel colgante. Mismo patron que
       /admin/equipos (btnAdvancedFilter / advancedFilterPanel), para que el gesto
       sea el mismo en los dos modulos: el boton al lado del ultimo filtro visible
       y el panel abriendose hacia abajo alineado a su derecha. */
    /* Etiqueta de campo con los MISMOS valores que el panel avanzado de /admin/equipos
       (ver el <span> de "Modelo" en su index): 12px / 600 / #64748b. Estaba en 11px, 700 y
       #0f172a, o sea más pequeña, más gruesa y casi negra — el mismo filtro se leía
       distinto según el módulo. */
    .cdash-adv-field { display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:600; color:#64748b; }
    /* La tipografía de estos controles NO se declara aquí: la pone la regla de arriba, que
       cubre todo el modal de una vez (ver "TIPOGRAFÍA DE LOS CONTROLES"). */
    .cdash-adv-field input, .cdash-adv-field select { height:36px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; background:#fff; outline:none; min-width:150px; }
    .cdash-adv-field input:focus, .cdash-adv-field select:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .cdash-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .cdash-card { background:#fff; border:1px solid #e9eef5; border-radius:14px; padding:16px 18px; min-width:0;
        box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .cdash-card.full { grid-column:1 / -1; }
    .cdash-card h4 { margin:0 0 14px 0; font-size:13px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px; letter-spacing:.2px; }
    .cdash-card h4::before { content:''; width:4px; height:15px; border-radius:3px; background:linear-gradient(180deg,#0ea5e9,#0067b1); flex:0 0 auto; }
    /* Ícono para descargar cada gráfico individual (cámara, arriba a la derecha de la tarjeta). */
    .cdash-chart-dl { margin-left:auto; display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; padding:0; border:1px solid #e2e8f0; border-radius:7px; background:#fff; color:#64748b; cursor:pointer; transition:background .15s, color .15s, border-color .15s; }
    .cdash-chart-dl:hover { background:#eff6ff; color:#0067b1; border-color:#bfdbfe; }
    .cdash-chart-dl .material-icons { font-size:16px; }
    .cdash-canvas-wrap { position:relative; height:240px; }
    /* Top productos: son barras HORIZONTALES (indexAxis:'y'), asi que cada producto es
       una fila y el alto reparte su grosor.
       ESTE VALOR ES SOLO EL SUELO. El alto de verdad lo calcula el JS al pintar el grafico
       (ver "El alto del panel NO puede seguir siendo fijo") contando las lineas reales de
       cada etiqueta, porque desde que las descripciones largas se parten en dos lineas un
       alto fijo hacia que las barras se pisaran. Al ser un estilo en linea, el del JS gana
       sobre esta clase; estos 650 son lo que se ve antes de que corra y el minimo que
       respeta el calculo. Ya NO hay que recalcularlo a mano si cambia el numero de barras
       (AlmacenController::TOP_PRODUCTOS_GRAFICO): se ajusta solo. */
    .cdash-canvas-wrap.tall { height:650px; }
    .cdash-empty { color:#94a3b8; font-size:13px; text-align:center; padding:40px 0; }
    .cdash-loading { text-align:center; color:#64748b; font-size:14px; padding:50px 0; font-weight:600; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .cdash-loading .cdash-spin { animation:cdashSpin .8s linear infinite; font-size:28px; color:#0067b1; }
    @keyframes cdashSpin { 100% { transform:rotate(360deg); } }
    .cdash-adv-wrap { position:relative; flex-shrink:0; }

    /* 36px, no 45 como en equipos: alli el boton va en una barra de filtros de
       pagina completa; aqui convive con inputs de 36px y uno de 45 sobresaldria.

       Los COLORES si son los de equipos, y no los de .btn-primary-maquinaria: alla
       el boton se pisa la clase con estilos propios y sale BLANCO con borde e icono
       grises, no azul. El azul de la clase lo hacia parecer el boton principal de la
       barra, cuando es un accesorio de los filtros.

       Se apunta por ID y no por clase porque .btn-primary-maquinaria tiene la MISMA
       especificidad que una clase: un cambio en el orden de carga devolveria el azul
       sin avisar. Con el id gana siempre. */
    #cdashAdvBtn {
        position:relative; height:36px; width:36px; min-width:36px; padding:0;
        display:flex; align-items:center; justify-content:center; border-radius:8px;
        background:#fff; border:1px solid #cbd5e0; color:#64748b; box-shadow:none;
    }
    #cdashAdvBtn:hover { background:#f8fafc; }

    /* Con filtros puestos vira a rojizo, igual que en equipos. Esta es la UNICA
       senal de que el dashboard esta acotado: antes convivia con un punto azul en
       la esquina, dos dueños del mismo estado. */
    #cdashAdvBtn.con-filtros {
        background:#fee2e2; border-color:#ef4444; color:#ef4444;
    }
    #cdashAdvBtn .material-icons { font-size:20px; }

    .cdash-adv-panel {
        position:absolute; top:calc(100% + 6px); right:0; z-index:20;
        width:340px; max-width:calc(100vw - 32px);
        background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px;
        box-shadow:0 10px 30px rgba(15,23,42,.18); padding:14px;
        display:flex; flex-direction:column; gap:10px;
    }
    .cdash-adv-panel h4 {
        margin:0 0 4px 0; font-size:14px; font-weight:700; color:#334155;
        display:flex; justify-content:space-between; align-items:center; gap:8px;
    }
    /* Mismo "Limpiar" que el panel avanzado de /admin/equipos: 12.5px, peso normal, gris
       y subrayado SIEMPRE (allí es un <span> con esos valores). Aquí estaba en azul de
       marca y peso 700, que lo hacía competir con el título del panel, y el subrayado
       solo aparecía al pasar el ratón. La tipografía la pone la regla del modal. */
    .cdash-adv-limpiar {
        background:none; border:none; padding:0; cursor:pointer;
        font-size:12.5px; font-weight:400; color:#64748b;
        text-decoration:underline;
    }
    /* Dentro del panel cada filtro ocupa su propia linea: hay sitio de sobra y
       asi el rango de meses se lee como un rango y no como dos casillas sueltas. */
    /* Hijo DIRECTO (>) y no cualquier descendiente: los campos sueltos del panel se
       llevan el ancho entero, pero los que van dentro de .cdash-adv-fila deben
       repartirselo. Sin el >, este !important los aplastaba a 100% cada uno y la
       fila se deshacia. */
    .cdash-adv-panel > .cdash-adv-field { flex:1 1 100% !important; }

    /* Dos campos por fila dentro del panel (Desde / Hasta), a mitades.
       El min-width:0 va DOS veces a proposito, y ninguna sobra: en el <label> porque un
       item flex no encoge por debajo de su contenido sin el, y en el <input> porque
       .cdash-adv-field le pone min-width:150px y dos de esos (150+150+10) no caben en los
       312px utiles del panel. Sin cualquiera de los dos, la fila se sale por la derecha. */
    .cdash-adv-fila { display:flex; gap:10px; }
    .cdash-adv-fila .cdash-adv-field { flex:1 1 0; min-width:0; }
    .cdash-adv-fila input[type="month"] { min-width:0; }
    .cdash-adv-panel input[type="month"] { width:100%; }

    /* 768px, el mismo corte que usa el resto de la aplicacion (46 reglas). Estaba en
       760 y eso dejaba una franja de 8px —entre 761 y 768— donde la pagina ya habia
       pasado a movil y este modal seguia en escritorio, con los dos filtros apretados
       a 305px uno al lado del otro. */
    @media (max-width: 768px) {
        .cdash-grid { grid-template-columns:1fr; }
        .cdash-body { padding-left:14px; padding-right:14px; }   /* más ancho útil en móvil */
        /* Reparto en telefono: Descripcion se lleva la fila entera —es el filtro
           principal y apretarlo lo deja ilegible— y Categoria comparte la suya CON el
           boton de avanzados (36px + 8 de hueco), para que no caiga a una tercera fila
           y quede lejos del filtro al que acompana. Desde/Hasta ya no viven aqui: se
           mudaron al panel de filtros avanzados. */
        .cdash-filtros { gap:8px; }
        .cdash-filtros .f-group { flex:1 1 0; min-width:0; }
        .cdash-filtros .f-group-desc { flex:1 1 100%; }
        .cdash-filtros .f-group-cat  { flex:1 1 calc(100% - 44px); }
        /* El panel no cabe a 340px en un telefono. NO se usa `left:0; right:0`: su bloque
           contenedor es .cdash-adv-wrap, que mide lo que el boton —36px—, asi que eso
           dejaba el panel de 36px de ancho y el contenido se le salia por los lados.
           Se queda anclado a la derecha del boton y se estira hacia la izquierda hasta
           el ancho util del modal. Los 56px son los rellenos horizontales que lo separan
           del borde de la pantalla: 14 del overlay + 14 del cuerpo, a cada lado. */
        .cdash-adv-panel { left:auto; right:0; width:calc(100vw - 56px); max-width:340px; }
        .cdash-filtros input[type="month"] { width:100%; min-width:0; font-size:12px; padding:0 6px; }
        .cdash-filtros input[type="month"]::-webkit-calendar-picker-indicator { display:none; }
    }
</style>

<div id="consumoDashModal" class="cdash-overlay" onclick="if(event.target===this) window.cerrarConsumoDashboard()">
    <div class="cdash-modal">
        <div class="cdash-head">
            {{-- Solo el título: el subtítulo descriptivo se quitó para que el encabezado
                 ocupe menos alto y quede más contenido a la vista sin hacer scroll. --}}
            <h3><i class="material-icons">analytics</i> Dashboard de Consumo</h3>
            <div style="display:flex;align-items:center;gap:4px;">
                {{-- Descarga los DATOS (XLSX) con los filtros puestos, no una foto de los
                     graficos: una imagen se ve pero no se puede trabajar. Las camaras de
                     cada tarjeta siguen bajando su grafico como PNG. --}}
                <button type="button" class="cdash-x" onclick="window._cdashDescargarExcel(this)" aria-label="Descargar Excel" title="Descargar los datos filtrados en Excel"><i class="material-icons">download</i></button>
                <button type="button" class="cdash-x" onclick="window.cerrarConsumoDashboard()" aria-label="Cerrar"><i class="material-icons">close</i></button>
            </div>
        </div>
        <div class="cdash-body">
            {{-- Filtros propios del dashboard. En la barra van solo los dos de uso corriente:
                 Descripción (el principal) y Categoría, y al lado el botón que despliega los
                 avanzados. El frente de destino y el rango Desde/Hasta ya NO viven aquí: se
                 recogieron en ese panel para no ocupar una fila entera siempre visible.
                 Sin títulos: cada control se identifica por su placeholder/valor. --}}
            <div class="cdash-filtros">
                <div class="f-group f-group-desc">
                    {{-- .cdash-cat-wrap: reutiliza el posicionamiento (position:relative) para
                         que el dropdown de recomendaciones (#cdashDescList) caiga bajo el input. --}}
                    <div class="cdash-cat-wrap">
                        <div class="cdash-inp-box" id="cdashDescBox">
                            <i class="material-icons">search</i>
                            <input type="text" id="cdashDescripcion" placeholder="Descripción del producto…" autocomplete="off"
                                   oninput="window._cdashDescInput()"
                                   {{-- Enter consulta YA y cierra las recomendaciones. El clearTimeout mata el
                                        debounce del tecleo, o 350 ms después saldría una segunda consulta igual. --}}
                                   onkeydown="if(event.key==='Enter'){event.preventDefault();clearTimeout(window._cdashDescTimer);window._cdashDescCloseSug();window._cdashFetch();}"
                                   onblur="setTimeout(function(){window._cdashDescCloseSug();},180)">
                            <i class="material-icons clr" id="cdashDescClear" style="display:none;" onclick="window._cdashDescClear()">close</i>
                        </div>
                        {{-- Recomendaciones (nombres de producto) — mismo look que la lista de Categoría. --}}
                        <div class="cdash-cat-list" id="cdashDescList"></div>
                    </div>
                </div>
                <div class="f-group f-group-cat">
                    <div class="cdash-cat-wrap">
                        <input type="hidden" id="cdashCategoria" value="">
                        <div class="cdash-inp-box cdash-cat-box" id="cdashCatBox" onmousedown="window._cdashCatToggle(event)">
                            {{-- Caret, NO lupa: esto es un desplegable de categorías, no un
                                 buscador. Con la lupa, al lado de la de Descripción, parecían
                                 dos buscadores del mismo campo. --}}
                            <i class="material-icons">expand_more</i>
                            <input type="text" id="cdashCatInput" placeholder="Categoría" autocomplete="off"
                                   oninput="window._cdashCatFilter(this.value)"
                                   onfocus="window._cdashCatOpen()"
                                   onblur="setTimeout(function(){window._cdashCatClose()},180)">
                            <i class="material-icons clr" id="cdashCatClear" style="display:none;" onmousedown="event.preventDefault();window._cdashCatSelect('',CDASH_CAT_LBL);">close</i>
                        </div>
                        <div class="cdash-cat-list" id="cdashCatList"></div>
                    </div>
                </div>

            {{-- Filtros avanzados. Mismo patrón que /admin/equipos: botón cuadrado con
                 filter_list y un panel que cuelga debajo. Aquí viven los filtros que NO
                 son de uso corriente —frente de destino y el rango de meses—, que antes
                 ocupaban una fila entera siempre visible.

                 VA DENTRO de .cdash-filtros, justo detrás de Categoría, y no como hermano
                 de la barra: fuera de ella caía a una línea propia debajo de los filtros,
                 lejos del control al que acompaña. En teléfono comparte fila con Categoría
                 por lo mismo (ver el flex-basis del @media de arriba).

                 Con algun filtro puesto el boton vira a rojizo, igual que en equipos:
                 recogidos en un panel, sin esa senal no habria forma de saber que el
                 dashboard esta acotado. Lo aplica _cdashMarcarAvanzados(). --}}
            <div class="cdash-adv-wrap">
                <button type="button" id="cdashAdvBtn" class="btn-primary-maquinaria"
                        title="Filtros avanzados: frente de destino y rango de meses"
                        onclick="window._cdashAdvToggle(event)">
                    <i class="material-icons">filter_list</i>
                </button>

                <div id="cdashAdvPanel" class="cdash-adv-panel" style="display:none;">
                    <h4>
                        Filtros Avanzados
                        <button type="button" class="cdash-adv-limpiar" onclick="window._cdashAdvLimpiar()">Limpiar</button>
                    </h4>
                {{-- Frente: buscador con sugerencias, no un <select>. Son decenas de
                     frentes y desplegarlos todos obligaba a recorrer la lista a ojo.
                     Misma mecánica que Categoría: el hidden guarda el ID (que es lo que
                     viaja al backend) y el input visible solo sirve para buscar. --}}
                <label class="cdash-adv-field" style="flex:1 1 240px;"><span>Frente de destino</span>
                    <div class="cdash-cat-wrap">
                        <input type="hidden" id="cdashFrente" value="">
                        {{-- SIN icono, a peticion del cliente: llevaba una lupa pegada al
                             cuadro de texto y sobraba. Ojo si se piensa reponer: la de
                             Descripcion es la unica lupa del modal —es el unico campo de
                             texto libre—; Categoria usa flecha por ser desplegable (ver su
                             comentario mas arriba) y este campo tiene esa misma mecanica,
                             asi que lo suyo seria la flecha, nunca la lupa. --}}
                        <div class="cdash-inp-box cdash-cat-box" id="cdashFrenteBox" onmousedown="window._cdashFrenteToggle(event)">
                            <input type="text" id="cdashFrenteInput" placeholder="Todos los frentes" autocomplete="off"
                                   oninput="window._cdashFrenteFilter(this.value)"
                                   onfocus="window._cdashFrenteOpen()"
                                   onblur="setTimeout(function(){window._cdashFrenteClose()},180)">
                            <i class="material-icons clr" id="cdashFrenteClear" style="display:none;" onmousedown="event.preventDefault();window._cdashFrenteSelect('',CDASH_FRE_LBL);">close</i>
                        </div>
                        <div class="cdash-cat-list" id="cdashFrenteList"></div>
                    </div>
                </label>
                {{-- Desde y Hasta comparten fila: son los dos extremos del MISMO rango y
                     leerlos uno debajo del otro obligaba a recomponer mentalmente el
                     periodo. Cada uno se lleva la mitad (flex:1 1 0 + min-width:0), asi
                     que encogen juntos y caben tambien en el panel estrecho del telefono. --}}
                <div class="cdash-adv-fila">
                <label class="cdash-adv-field"><span>Desde (mes)</span>
                    <input type="month" id="cdashDesde" title="Desde (mes)" onchange="window._cdashFetch()" onclick="try{ this.showPicker(); }catch(e){}">
                </label>
                <label class="cdash-adv-field"><span>Hasta (mes)</span>
                    <input type="month" id="cdashHasta" title="Hasta (mes)" onchange="window._cdashFetch()" onclick="try{ this.showPicker(); }catch(e){}">
                </label>
                </div>
                </div>
            </div>
            </div>
            <div id="cdashLoading" class="cdash-loading"><i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span></div>
            <div id="cdashContent" style="display:none;">
                <div class="cdash-grid">
                    <div class="cdash-card full"><h4>Consumo por mes<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'consumo-por-mes')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap"><canvas id="cdashChartMes"></canvas></div></div>
                    <div class="cdash-card full"><h4>Top {{ \App\Http\Controllers\AlmacenController::TOP_PRODUCTOS_GRAFICO }} productos consumidos<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'top-20-consumidos')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap tall"><canvas id="cdashChartTop"></canvas></div></div>
                    <div class="cdash-card full"><h4>Consumo por almacén<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'consumo-por-almacen')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap"><canvas id="cdashChartAlm"></canvas></div></div>
                </div>
            </div>
            <div id="cdashEmpty" class="cdash-empty" style="display:none;">No hay consumo registrado para los filtros seleccionados.</div>
        </div>
    </div>
</div>

<script>
    // URL del endpoint (sin querystring). El dashboard NO usa los filtros del módulo:
    // arma su propio querystring desde sus controles (desde/hasta/categoría).
    window.CONSUMO_DASH_URL = "{{ route('almacen.consumoDashboard') }}";
    window.CONSUMO_DASH_EXPORT_URL = "{{ route('almacen.consumoDashboardExport') }}";

    // Instancias de Chart para destruirlas antes de re-renderizar (evita el error
    // "Canvas is already in use" al reabrir el modal o al cambiar un filtro).
    window._cdashCharts = window._cdashCharts || {};
    // El <select> de categoría se llena una sola vez (con lo que devuelve el endpoint).
    window._cdashCatsCargadas = false;
    // Las recomendaciones del filtro Descripción (nombres de producto) también se cargan
    // UNA sola vez: el modal pide la lista con con_productos=1 en el primer fetch y la cachea.
    window._cdashProdsCargados = false;
    // El <select> de "Frente de destino" del panel avanzado también se llena una sola vez.
    window._cdashFrentesCargados = false;

    // Formato de número estilo VE: miles con punto, decimales con coma. Sin decimales
    // si es entero (las unidades suelen serlo, pero soporta fraccionarios).
    window.cdashFmt = function (n) {
        n = Number(n) || 0;
        var dec = (n % 1 === 0) ? 0 : 2;
        return n.toLocaleString('es-VE', { minimumFractionDigits: dec, maximumFractionDigits: 2 });
    };

    // "2026-06" → "Jun 2026" (mes en palabra, no en número). Robusto si no llega bien formado.
    window.cdashMesLabel = function (ym) {
        var M = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var p = String(ym || '').split('-');
        if (p.length < 2) return String(ym || '');
        var i = parseInt(p[1], 10) - 1;
        return (M[i] || p[1]) + ' ' + p[0];
    };

    window.cerrarConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (m) m.classList.remove('open');
    };


    // Carga html2canvas bajo demanda y ejecuta el callback. Lo usan tanto la descarga
    // del dashboard completo como la de cada gráfico. La inyección la hace el cargador
    // compartido (js/maquinaria/lazy_loader.js), que ya garantiza una sola descarga.
    window._cdashConHtml2Canvas = function (cb) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable, así que un
             asset sin versión se queda pegado en el navegador PARA SIEMPRE y una
             actualización de la librería no llegaría nunca. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/html2canvas.min.js') . '?v=' . @filemtime(public_path('js/html2canvas.min.js')) }}",
            function () { return typeof html2canvas !== 'undefined'; }
        ).then(cb).catch(function () {
            alert('No se pudo cargar la librería de captura. Revisa tu conexión.');
        });
    };

    // Captura un elemento del DOM y lo baja como PNG. Los botones de cámara se ocultan
    // en el CLON que html2canvas renderiza (onclone) — no en el DOM real: así la foto
    // sale limpia sin que la pantalla parpadee. Mismo patrón que descargarPanelHtmlFDM
    // (fleet_dashboard.js) y capturaPanelHtml (consumibles/graficos).
    window._cdashCapturarPng = function (elemento, nombre) {
        if (!elemento) return;
        window._cdashConHtml2Canvas(function () {
            html2canvas(elemento, {
                backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false,
                onclone: function (doc) {
                    doc.querySelectorAll('.cdash-chart-dl').forEach(function (b) { b.style.display = 'none'; });
                }
            }).then(function (canvas) {
                var a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = (nombre || 'grafico') + '.png';
                document.body.appendChild(a); a.click(); a.remove();
            }).catch(function () {});
        });
    };

    // Descarga UN gráfico como PNG. Captura la TARJETA completa (título + gráfico +
    // fondo blanco con su borde), no solo el canvas: así la foto se entiende sola.
    // `btn` es el propio botón de cámara — de él se cuelga el .cdash-card contenedor.
    window._cdashDescargarGrafico = function (btn, nombre) {
        var card = btn && btn.closest ? btn.closest('.cdash-card') : null;
        window._cdashCapturarPng(card, nombre);
    };

    window.abrirConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (!m) return;
        m.classList.add('open');
        window._cdashFetch();
    };

    // Lee los filtros del modal y arma el querystring. FUENTE UNICA: la usan el fetch de
    // los graficos Y la descarga a Excel, para que el archivo salga con EXACTAMENTE lo
    // que se esta viendo en pantalla.
    window._cdashParams = function () {
        var desde  = (document.getElementById('cdashDesde') || {}).value || '';
        var hasta  = (document.getElementById('cdashHasta') || {}).value || '';
        var cat    = (document.getElementById('cdashCategoria') || {}).value || '';
        var desc   = ((document.getElementById('cdashDescripcion') || {}).value || '').trim();
        var frente = (document.getElementById('cdashFrente') || {}).value || '';

        // Los <input type="month"> dan "YYYY-MM", pero el backend filtra por FECHA (dia)
        // con whereDate. Si se manda el mes crudo, "<= YYYY-MM" se toma como YYYY-MM-00
        // y EXCLUYE todo el mes (el dashboard quedaba en 0 al elegir "Hasta"). Por eso
        // AMBOS se expanden igual: Desde -> primer dia del mes; Hasta -> ultimo dia.
        if (desde && desde.length === 7) desde = desde + '-01';
        if (hasta && hasta.length === 7) {
            var hp = hasta.split('-');
            var ultimoDia = new Date(parseInt(hp[0], 10), parseInt(hp[1], 10), 0).getDate();
            hasta = hasta + '-' + String(ultimoDia).padStart(2, '0');
        }

        var p = new URLSearchParams();
        if (desde)  p.set('desde', desde);
        if (hasta)  p.set('hasta', hasta);
        if (cat)    p.set('categoria', cat);
        if (desc)   p.set('descripcion', desc);
        if (frente) p.set('frente', frente);
        return p;
    };

    // Descarga los DATOS filtrados en XLSX (4 hojas: detalle + las 3 agregaciones).
    // Via fetch -> blob y NO window.open: asi se muestra el spinner mientras el backend
    // arma el archivo, y se avisa si algo falla en vez de abrir una pestana en blanco.
    window._cdashDescargarExcel = function (btn) {
        if (btn && btn.dataset.bajando === '1') return;   // doble clic mientras genera
        if (btn) { btn.dataset.bajando = '1'; btn.style.opacity = '.5'; }
        if (window.showPreloader) window.showPreloader();

        var qs = window._cdashParams().toString();
        var url = window.CONSUMO_DASH_EXPORT_URL + (qs ? ('?' + qs) : '');

        window.apiFetch(url, { headers: { 'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } })
            .then(function (r) {
                if (!r.ok) throw new Error('No se pudo generar el Excel.');
                // Si respondio HTML (p.ej. redireccion por sesion vencida) no lo bajamos
                // como .xlsx corrupto.
                var ct = (r.headers.get('Content-Type') || '').toLowerCase();
                if (ct.indexOf('spreadsheet') === -1 && ct.indexOf('octet-stream') === -1) {
                    throw new Error('La sesion expiro o no hay permiso para exportar.');
                }
                return r.blob();
            })
            .then(function (blob) {
                var pad = function (n) { return (n < 10 ? '0' : '') + n; };
                var d = new Date();
                var nombre = 'Dashboard_Consumo_' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                           + '_' + pad(d.getHours()) + '-' + pad(d.getMinutes()) + '.xlsx';
                var burl = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = burl; a.download = nombre; a.style.display = 'none';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                setTimeout(function () { try { URL.revokeObjectURL(burl); } catch (e) {} }, 1500);
            })
            .catch(function (e) {
                if (window.toast) window.toast(e.message || 'Error al exportar el Excel.', 'error');
            })
            .finally(function () {
                if (window.hidePreloader) window.hidePreloader();
                if (btn) { btn.dataset.bajando = ''; btn.style.opacity = ''; }
            });
    };

    // Lee los filtros PROPIOS del modal y pide los datos. Independiente del módulo.
    // Punto único por el que pasan TODOS los filtros: aquí se refresca la señal del
    // botón avanzado, así ninguna vía puede cambiar un filtro sin actualizarla.
    // conservarSug=true: NO cierra las recomendaciones de Descripción. Lo pasa así el
    // filtrado en vivo mientras se teclea, porque ahí el usuario SIGUE escribiendo y
    // necesita la lista delante — cerrarla en cada consulta era lo que "contraía el
    // buscador" a media palabra. Los demás caminos (Enter, elegir de la lista, cambiar
    // otro filtro) la cierran como siempre: en esos el usuario ya terminó de elegir.
    window._cdashFetch = function (conservarSug) {
        // El modal ya no está en el DOM → no hay nada que refrescar. Esta consulta puede
        // llegar TARDE: el filtro Descripción la programa con 350 ms de retardo, y en ese
        // hueco el usuario puede haber navegado a otro módulo, con lo que la SPA ya
        // reemplazó el contenido y estos ids no existen. Sin la salida temprana, ldEl es
        // null y la línea siguiente revienta con TypeError al escribir en .style.
        // Los tres ids viven en este mismo partial, así que comprobar uno vale por todos.
        var ldEl = document.getElementById('cdashLoading');
        if (!ldEl) return;
        // Cierra las sugerencias de Descripción para que el spinner de carga quede
        // visible (mismo feedback que al filtrar por Categoría).
        if (!conservarSug && window._cdashDescCloseSug) window._cdashDescCloseSug();
        // Los filtros avanzados van recogidos: el COLOR del boton es lo unico que
        // avisa de que hay alguno puesto. Se refresca aqui y no en cada control,
        // porque por aqui pasan todos sin excepcion.
        if (window._cdashMarcarAvanzados) window._cdashMarcarAvanzados();
        ldEl.style.display = 'flex';
        ldEl.innerHTML = '<i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span>';
        document.getElementById('cdashContent').style.display = 'none';
        document.getElementById('cdashEmpty').style.display = 'none';

        var p = window._cdashParams();
        // Pide la lista de nombres para las recomendaciones SOLO la primera vez (luego se cachea).
        if (!window._cdashProdsCargados) p.set('con_productos', '1');
        var qs = p.toString();

        // Chart.js va EN PARALELO con el fetch: el modal ya no depende de que el layout
        // lo hubiera cargado en todas las páginas, lo pide al abrirse (idempotente, así
        // que reabrirlo no vuelve a descargar nada).
        var chartListo = window.ensureChartJS();

        Promise.all([
            window.apiFetch(window.CONSUMO_DASH_URL + (qs ? ('?' + qs) : ''), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); }),
            chartListo
        ])
            .then(function (res) { window._cdashRender(res[0]); })
            .catch(function () {
                var ldErr = document.getElementById('cdashLoading');
                ldErr.innerHTML = '<i class="material-icons" style="font-size:28px;color:#ef4444;">error_outline</i><span>No se pudo cargar el dashboard.</span>';
            });
    };

    // Su único llamador la invoca tras el Promise.all de arriba, así que aquí Chart ya
    // está cargado: sobra comprobarlo (si la carga falla, el .catch de allí es quien
    // avisa). Antes hacía falta porque dependía de que el layout lo hubiera traído.
    window._cdashRender = function (data) {

        if (!window._cdashCatsCargadas && Array.isArray(data.categorias)) {
            window._cdashCatsData = data.categorias;
            window._cdashCatsCargadas = true;
            window._cdashCatRenderList();
        }

        // Frentes de destino: se cachean la primera vez que llegan y alimentan las
        // sugerencias del buscador (antes rellenaban los <option> de un select).
        if (!window._cdashFrentesCargados && Array.isArray(data.frentes)) {
            window._cdashFrentesCargados = true;
            window._cdashFrentesData = data.frentes;
        }

        // Recomendaciones del filtro Descripción: se cachean la primera vez que llegan.
        if (!window._cdashProdsCargados && Array.isArray(data.productos)) {
            window._cdashProdsData = data.productos;
            window._cdashProdsCargados = true;
        }

        var sinDatos = (!data.por_mes || !data.por_mes.length) &&
                       (!data.top_productos || !data.top_productos.length);
        document.getElementById('cdashLoading').style.display = 'none';
        if (sinDatos) {
            document.getElementById('cdashContent').style.display = 'none';
            document.getElementById('cdashEmpty').style.display = 'block';
            return;
        }
        document.getElementById('cdashEmpty').style.display = 'none';
        document.getElementById('cdashContent').style.display = 'block';

        // Destruir charts previos.
        Object.keys(window._cdashCharts).forEach(function (key) {
            if (window._cdashCharts[key]) { window._cdashCharts[key].destroy(); window._cdashCharts[key] = null; }
        });

        var fmt = window.cdashFmt;

        // chartjs-plugin-datalabels queda registrado GLOBALMENTE en Chart: lo hace el propio
        // ensureChartJS() de arriba, y también /admin/consumibles/graficos y el dashboard de
        // flota. Como la app es SPA, ese registro sobrevive a la navegación y este dashboard
        // —que pinta sus valores con el plugin propio cdValLabels— mostraría CADA cantidad
        // dos veces: la del plugin global dentro de la barra y la de cdValLabels fuera. Por
        // eso los TRES gráficos de aquí lo apagan uno por uno.
        var CD_SIN_DATALABELS = { display: false };

        // Estilo COMÚN, formal y coherente (paleta corporativa azul, sin arcoíris).
        var cdTooltip = {
            backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#fff', bodyColor: '#e2e8f0',
            padding: 10, cornerRadius: 8, displayColors: false,
            titleFont: { weight: '700', size: 12 }, bodyFont: { size: 12 }
        };
        var cdGrid  = { color: 'rgba(148,163,184,0.18)', drawBorder: false, borderDash: [4, 4] };
        var cdTick  = { color: '#94a3b8', font: { size: 11 } };
        // Degradado vertical (claro arriba → marca abajo) para barras verticales.
        function cdVGrad(c, a, b) { var ar = c.chart.chartArea; if (!ar) return b; var g = c.chart.ctx.createLinearGradient(0, ar.top, 0, ar.bottom); g.addColorStop(0, a); g.addColorStop(1, b); return g; }
        // Degradado horizontal (marca izq → claro der) para barras horizontales.
        function cdHGrad(c, a, b) { var ar = c.chart.chartArea; if (!ar) return a; var g = c.chart.ctx.createLinearGradient(ar.left, 0, ar.right, 0); g.addColorStop(0, a); g.addColorStop(1, b); return g; }

        // Plugin: dibuja la CANTIDAD sobre cada barra/segmento (visible SIN pasar el mouse).
        // Soporta barras verticales (encima), horizontales (al final; dentro si la barra es
        // muy larga) y dona (en el centro del segmento).
        // Alto aproximado del texto del valor (font-size 11px). Se usa para saber si la
        // etiqueta cabe encima de la barra sin salirse del area del grafico.
        var CD_ALTO_VALOR = 11;
        var cdValLabels = {
            id: 'cdValLabels',
            afterDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                var horizontal = chart.options.indexAxis === 'y';
                var isDoughnut = chart.config.type === 'doughnut';
                var area = chart.chartArea;
                chart.data.datasets.forEach(function (ds, di) {
                    chart.getDatasetMeta(di).data.forEach(function (el, i) {
                        var v = ds.data[i];
                        if (v == null || v === 0) return;
                        var txt = fmt(v);
                        ctx.save();
                        ctx.font = "700 11px 'Inter','Segoe UI',sans-serif";
                        if (isDoughnut) {
                            var p = el.tooltipPosition ? el.tooltipPosition() : { x: el.x, y: el.y };
                            ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                            ctx.fillText(txt, p.x, p.y);
                        } else if (horizontal) {
                            var dentro = area && el.x > area.left + (area.right - area.left) * 0.82;
                            ctx.textBaseline = 'middle';
                            if (dentro) { ctx.fillStyle = '#fff'; ctx.textAlign = 'right'; ctx.fillText(txt, el.x - 6, el.y); }
                            else { ctx.fillStyle = '#334155'; ctx.textAlign = 'left'; ctx.fillText(txt, el.x + 6, el.y); }
                        } else {
                            // Barras verticales: el valor va ENCIMA de la barra, pero la barra
                            // mas alta llega al techo del area y el texto quedaba recortado —
                            // en el mes de mayor consumo no se veia el numero. Si no cabe
                            // arriba, se pinta DENTRO en blanco, igual que en las horizontales.
                            var cabeArriba = !area || (el.y - 4 - CD_ALTO_VALOR) >= area.top;
                            ctx.textAlign = 'center';
                            if (cabeArriba) {
                                ctx.fillStyle = '#334155'; ctx.textBaseline = 'bottom';
                                ctx.fillText(txt, el.x, el.y - 4);
                            } else {
                                ctx.fillStyle = '#fff'; ctx.textBaseline = 'top';
                                ctx.fillText(txt, el.x, el.y + 5);
                            }
                        }
                        ctx.restore();
                    });
                });
            }
        };

        // ── 1) Consumo por mes (barras, azul con degradado) ──────────────────
        var mes = data.por_mes || [];
        window._cdashCharts.mes = new Chart(document.getElementById('cdashChartMes'), {
            type: 'bar',
            plugins: [cdValLabels],
            data: {
                labels: mes.map(function (x) { return window.cdashMesLabel(x.mes); }),
                datasets: [{ label: 'Consumo', data: mes.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdVGrad(c, '#38bdf8', '#0067b1'); },
                    hoverBackgroundColor: function (c) { return cdVGrad(c, '#0ea5e9', '#005a9e'); },
                    borderRadius: 6, borderSkipped: false, maxBarThickness: 44 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, datalabels: CD_SIN_DATALABELS, tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) { return fmt(c.parsed.y) + ' und'; } } }) },
                scales: {
                    x: { grid: { display: false, drawBorder: false }, ticks: cdTick },
                    y: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) }
                }
            }
        });

        // ── 2) Top productos (barras horizontales, escala secuencial azul) ───
        // Azul corporativo un tono más oscuro que "Consumo por mes" (#005a9e en vez de
        // #0067b1): distingue los dos gráficos sin salirse de la paleta de la app.
        var top = data.top_productos || [];

        // Rótulo del eje = Nº DE PARTE principal (identifica el filtro exacto). Muchos
        // filtros comparten descripción, así que rotular por descripción se veía
        // "combinado". El total ya es por producto (ID_PRODUCTO), no por descripción.
        //
        // Se ENVUELVE EN VARIAS LÍNEAS cuando no cabe: las descripciones largas se salían
        // del eje y Chart.js las cortaba, así que no se sabía qué producto era.
        // window.wrapLabel (dom_helpers.js) devuelve un array al partir, que es justo lo
        // que Chart.js pinta como tick multilínea; es el mismo helper que usa el Dashboard
        // de Flota, para que los dos corten igual. El límite baja en móvil, donde el eje
        // dispone de menos ancho.
        var cdTopMax = window.innerWidth < 480 ? 16 : 24;
        var cdTopLabels = top.map(function (x) {
            var t = x.parte || x.nombre || '';
            return window.wrapLabel ? window.wrapLabel(t, cdTopMax) : t;
        });

        // El alto del panel NO puede seguir siendo fijo: con etiquetas de dos líneas, 25
        // barras en los 650px de .tall se pisaban unas con otras. Se reserva el alto real
        // de cada etiqueta (sus líneas) MÁS un hueco por barra, igual que hace el Dashboard
        // de Flota, y se aplica al contenedor —que es quien manda, porque el gráfico va con
        // maintainAspectRatio:false—. El mínimo conserva los 650 de antes para que con
        // pocos productos el panel no encoja y descoloque el modal.
        (function () {
            var cont = document.getElementById('cdashChartTop');
            cont = cont && cont.parentElement;
            if (!cont) return;
            var lineas = cdTopLabels.reduce(function (s, l) { return s + (Array.isArray(l) ? l.length : 1); }, 0);
            var alto = Math.min(1500, Math.max(650, lineas * 15 + cdTopLabels.length * 12 + 60));
            cont.style.height = alto + 'px';
        })();

        window._cdashCharts.top = new Chart(document.getElementById('cdashChartTop'), {
            type: 'bar',
            plugins: [cdValLabels],
            data: {
                labels: cdTopLabels,
                datasets: [{ label: 'Consumo', data: top.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdHGrad(c, '#005a9e', '#38bdf8'); },
                    {{-- El hover OSCURECE, igual que en "Consumo por mes". Con el degradado
                         invertido (hover más claro que la barra) los dos gráficos reaccionaban
                         al revés uno del otro. --}}
                    hoverBackgroundColor: function (c) { return cdHGrad(c, '#005a9e', '#0ea5e9'); },
                    borderRadius: 5, borderSkipped: false }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, datalabels: CD_SIN_DATALABELS, tooltip: Object.assign({}, cdTooltip, {
                    // Título en NEGRITA (titleFont weight 700): nombre + cantidad y unidad.
                    // El cuerpo (normal) lleva nº de parte y equipos, uno debajo del otro.
                    // La cantidad va SOLO en el título (no se repite en el cuerpo).
                    callbacks: {
                        title: function (items) {
                            var c = items[0] || {}; var d = top[c.dataIndex] || {};
                            // c.label es el RESPALDO y ahora puede venir partido en varias
                            // líneas (array): unirlo con espacio, o saldría "LINEA1,LINEA2".
                            var suelto = Array.isArray(c.label) ? c.label.join(' ') : (c.label || '');
                            return [ (d.nombre || suelto), fmt(c.parsed.x) + '   ' + (d.um || 'UND') ];
                        },
                        label: function (c) {
                            var d = top[c.dataIndex] || {};
                            var out = [];
                            // Nº de parte: lista completa (el eje ya muestra el principal).
                            if (d.partes && d.partes.length) {
                                out.push('Nº de parte: ' + d.partes.slice(0, 6).join(' / ') +
                                         (d.partes.length > 6 ? ' …' : ''));
                            }
                            if (d.equipos && d.equipos.length) {
                                out.push('Equipos que lo usan:');
                                d.equipos.slice(0, 8).forEach(function (e) { out.push('•  ' + e); });
                                if (d.equipos.length > 8) out.push('… y ' + (d.equipos.length - 8) + ' más');
                            }
                            return out;
                        }
                    }
                }) },
                scales: {
                    x: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) },
                    y: { grid: { display: false, drawBorder: false }, ticks: { color: '#475569', font: { size: 10 }, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 28 ? l.slice(0, 28) + '…' : l; } } }
                }
            }
        });

        // ── 3) Consumo por almacén (dona con total al centro) ────────────────
        var alm = data.por_almacen || [];
        // Paleta CATEGÓRICA (un color por almacén, sin relación de orden). Arranca en el
        // azul corporativo. Todos en versión OSCURA (700/600) a propósito: cdValLabels
        // escribe la cantidad en BLANCO dentro del segmento, y sobre un ámbar o un verde
        // claro esa cifra no se leía. Con estos tonos el texto blanco contrasta en los ocho.
        var paleta = ['#0067b1', '#0f766e', '#4f46e5', '#b45309', '#db2777', '#15803d', '#c2410c', '#475569'];
        var almTotal = alm.reduce(function (s, x) { return s + (Number(x.total) || 0); }, 0);
        window._cdashCharts.alm = new Chart(document.getElementById('cdashChartAlm'), {
            type: 'doughnut',
            data: {
                labels: alm.map(function (x) { return x.nombre; }),
                datasets: [{ data: alm.map(function (x) { return x.total; }),
                    backgroundColor: alm.map(function (_, i) { return paleta[i % paleta.length]; }),
                    borderColor: '#fff', borderWidth: 2, hoverOffset: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '64%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#475569', font: { size: 11 }, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 12 } },
                    datalabels: CD_SIN_DATALABELS,
                    tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) {
                        var pct = almTotal ? Math.round((c.parsed / almTotal) * 100) : 0;
                        return c.label + ': ' + fmt(c.parsed) + ' (' + pct + '%)';
                    } } })
                }
            },
            plugins: [cdValLabels, {
                id: 'cdashCenter',
                beforeDraw: function (chart) {
                    var ar = chart.chartArea; if (!ar) return;
                    var ctx = chart.ctx, cx = (ar.left + ar.right) / 2, cy = (ar.top + ar.bottom) / 2;
                    ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#0f172a'; ctx.font = "800 20px 'Inter','Segoe UI',sans-serif"; ctx.fillText(fmt(almTotal), cx, cy - 6);
                    ctx.fillStyle = '#94a3b8'; ctx.font = "700 10px 'Inter','Segoe UI',sans-serif"; ctx.fillText('TOTAL', cx, cy + 12);
                    ctx.restore();
                }
            }]
        });
    };

    // ── Filtro Descripción (texto libre sobre el NOMBRE del producto) ──────────
    // Filtra EN VIVO mientras se escribe, con dos condiciones que antes no estaban y que
    // eran justo lo que estorbaba:
    //
    //   · Desde CDASH_DESC_MIN caracteres. Con 1 o 2 la búsqueda es tan amplia que no dice
    //     nada y se gastaba una consulta por tecla.
    //   · SIN cerrar las recomendaciones: se llama a _cdashFetch(true). Antes cada consulta
    //     las cerraba —el cierre vive dentro de _cdashFetch— y el buscador se "contraía" a
    //     media palabra, así que no había forma de seguir escribiendo mirando la lista ni de
    //     elegir de ella.
    //
    // El campo VACÍO sí consulta: es quitar el filtro, y hay que hacerlo o el gráfico se
    // quedaría filtrado por lo anterior con el input en blanco. Entre 1 y 2 caracteres no se
    // consulta nada: son estados de paso mientras se escribe o se borra.
    //
    // Enter y elegir de la lista siguen consultando al instante, y esos SÍ cierran las
    // recomendaciones (ahí el usuario ya terminó). Comparte estética con Categoría.
    var CDASH_DESC_MIN = 3;
    window._cdashDescTimer = null;
    window._cdashDescInput = function () {
        var inp = document.getElementById('cdashDescripcion');
        var val = inp ? inp.value.trim() : '';
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.toggle('active', !!val);
        window._cdashDescRenderSug();   // recomendaciones en vivo

        clearTimeout(window._cdashDescTimer);
        if (val.length >= CDASH_DESC_MIN || val.length === 0) {
            // 350 ms tras la última tecla: no se consulta por pulsación.
            window._cdashDescTimer = setTimeout(function () { window._cdashFetch(true); }, 350);
        }
    };
    window._cdashDescClear = function () {
        var inp = document.getElementById('cdashDescripcion'); if (inp) inp.value = '';
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = 'none';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.remove('active');
        window._cdashDescCloseSug();
        clearTimeout(window._cdashDescTimer);   // que el debounce del tecleo no repita la consulta
        window._cdashFetch();   // la X sí consulta: quitar el filtro es una decisión del usuario
    };

    // ── Recomendaciones del filtro Descripción (nombres de producto) ───────────
    // Mismo look que la lista de Categoría (.cdash-cat-list/.cdash-cat-item) y mismos
    // escapes (escHtml/escAttr, definidos más abajo). Los nombres se cargaron 1 vez en
    // _cdashProdsData. Solo filtran/rellenan el input — NO tocan el back más allá del fetch.
    window._cdashProdsData = window._cdashProdsData || [];
    window._cdashDescRenderSug = function () {
        var list = document.getElementById('cdashDescList'); if (!list) return;
        var inp = document.getElementById('cdashDescripcion');
        var q = (inp ? inp.value : '').trim().toLowerCase();
        if (!q) { list.classList.remove('open'); list.innerHTML = ''; return; }
        // Primero los que EMPIEZAN por lo escrito, luego los que lo CONTIENEN. Máx 12.
        var starts = [], contains = [];
        window._cdashProdsData.forEach(function (n) {
            var i = String(n).toLowerCase().indexOf(q);
            if (i === 0) starts.push(n); else if (i > 0) contains.push(n);
        });
        var res = starts.concat(contains).slice(0, 12);
        if (!res.length) { list.classList.remove('open'); list.innerHTML = ''; return; }
        list.innerHTML = res.map(function (n) {
            var safe = escAttr(n);
            return '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashDescSelectSug(\'' + safe + '\');">' + escHtml(n) + '</div>';
        }).join('');
        window._cdashCerrarListas('cdashDescList');
        list.classList.add('open');
    };
    window._cdashDescSelectSug = function (name) {
        var inp = document.getElementById('cdashDescripcion'); if (inp) inp.value = name;
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = 'block';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.add('active');
        window._cdashDescCloseSug();
        clearTimeout(window._cdashDescTimer);   // que el debounce del tecleo no repita la consulta
        window._cdashFetch();   // elegir de la lista consulta al instante y cierra la lista
    };
    window._cdashDescCloseSug = function () {
        var list = document.getElementById('cdashDescList'); if (list) list.classList.remove('open');
    };

    window._cdashCatsData = window._cdashCatsData || [];
    // El nombre de categoría es texto libre editable desde el catálogo de productos: hay que
    // escaparlo en LOS DOS contextos donde se interpola, o una categoría llamada
    // `<img src=x onerror=...>` ejecuta al abrir la lista (XSS almacenado).
    //   escAttr → dentro de la cadena JS del onmousedown (comillas + < > & para cerrar el atributo).
    //   escHtml → como texto visible del <div>.
    // Ambos son los helpers centrales (dom_helpers.js): la pareja que estaba escrita aquí
    // se repetía casi igual en recepcion/index y _machinery.
    var escHtml = window.escapeHtml;
    var escAttr = window.escapeAttrJs;
    // Rótulo del campo cuando NO hay categoría elegida. Una sola definición: la comparten
    // la ✕, la opción "todas" de la lista y el respaldo de _cdashCatSelect — antes el mismo
    // literal estaba escrito en cuatro sitios.
    var CDASH_CAT_LBL = 'Categoría';
    window._cdashCatRenderList = function (filter) {
        var list = document.getElementById('cdashCatList'); if (!list) return;
        var q = (filter || '').toLowerCase();
        // La opción DICE "Todas las categorías" (describe qué hace al elegirla) pero deja el
        // campo rotulado con CDASH_CAT_LBL: el nombre del filtro, no su valor. Sin esto,
        // limpiar desde la lista devolvía el rótulo viejo y contradecía a la ✕.
        var html = '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'\',CDASH_CAT_LBL);">Todas las categorías</div>';
        window._cdashCatsData.forEach(function (c) {
            var s = String(c);
            if (q && s.toLowerCase().indexOf(q) === -1) return;
            var safe = escAttr(s);
            html += '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'' + safe + '\',\'' + safe + '\');">' + escHtml(s) + '</div>';
        });
        list.innerHTML = html;
    };
    // Los desplegables del modal: las tres listas (Descripción, Categoría, Frente) y el
    // panel de Filtros avanzados. Solo UNO puede estar abierto: al abrir cualquiera se
    // cierran los demás, para que no queden dos desplegados tapándose entre sí.
    // El panel entra en el reparto porque cuelga de la misma fila de filtros y se
    // montaba encima de la lista de Descripción o de Categoría.
    window._cdashListas = ['cdashDescList', 'cdashCatList', 'cdashFrenteList'];
    window._cdashCerrarListas = function (excepto) {
        window._cdashListas.forEach(function (id) {
            if (id === excepto) return;
            var l = document.getElementById(id);
            if (l) l.classList.remove('open');
        });
        // La lista de Frente vive DENTRO del panel avanzado: al abrirla el panel se
        // queda, y al cerrar el panel se lleva su lista por delante.
        if (excepto !== 'cdashFrenteList' && excepto !== 'cdashAdvPanel') {
            var f = document.getElementById('cdashFrenteList');
            if (f) f.classList.remove('open');
            if (window._cdashAdvCerrar) window._cdashAdvCerrar();
        }
    };

    window._cdashCatOpen = function () {
        window._cdashCerrarListas('cdashCatList');
        var l = document.getElementById('cdashCatList');
        if (l) { l.classList.add('open'); window._cdashCatRenderList(); }
    };
    window._cdashCatClose = function () { var l = document.getElementById('cdashCatList'); if (l) l.classList.remove('open'); };
    window._cdashCatFilter = function (v) { window._cdashCatRenderList(v); window._cdashCatOpen(); };

    // Alterna en MOUSEDOWN, no en click: el foco llega antes que el click y volvía a
    // abrir la lista, asi que al segundo clic nunca se recogía. Al cerrar se quita el
    // foco a mano para que onfocus no la reabra.
    window._cdashCatToggle = function (ev) {
        var l = document.getElementById('cdashCatList');
        if (l && l.classList.contains('open')) {
            ev.preventDefault();
            window._cdashCatClose();
            var inp = document.getElementById('cdashCatInput'); if (inp) inp.blur();
            return;
        }
        window._cdashCatOpen();
    };
    window._cdashCatSelect = function (val, label) {
        var h = document.getElementById('cdashCategoria'); if (h) h.value = val;
        var inp = document.getElementById('cdashCatInput'); if (inp) { inp.value = ''; inp.placeholder = label || CDASH_CAT_LBL; }
        var box = document.getElementById('cdashCatBox'); if (box) box.classList.toggle('active', !!val);
        var clr = document.getElementById('cdashCatClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        window._cdashCatClose();
        window._cdashFetch();
    };

    // ── Filtro FRENTE DE DESTINO ───────────────────────────────────────────────
    // Copia exacta de la mecánica de Categoría, con una diferencia: aquí el valor que
    // viaja al backend es el ID del frente y lo que se busca es su NOMBRE, así que el
    // hidden y el texto visible no guardan lo mismo.
    window._cdashFrentesData = window._cdashFrentesData || [];
    var CDASH_FRE_LBL = 'Todos los frentes';

    window._cdashFrenteRenderList = function (filter) {
        var list = document.getElementById('cdashFrenteList'); if (!list) return;
        var q = (filter || '').toLowerCase();
        var html = '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashFrenteSelect(\'\',CDASH_FRE_LBL);">Todos los frentes</div>';
        window._cdashFrentesData.forEach(function (f) {
            var nombre = String(f.nombre || '');
            if (q && nombre.toLowerCase().indexOf(q) === -1) return;
            html += '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashFrenteSelect(\'' + escAttr(String(f.id)) + '\',\'' + escAttr(nombre) + '\');">' + escHtml(nombre) + '</div>';
        });
        list.innerHTML = html;
    };
    window._cdashFrenteOpen = function () {
        window._cdashCerrarListas('cdashFrenteList');
        var l = document.getElementById('cdashFrenteList');
        if (l) { l.classList.add('open'); window._cdashFrenteRenderList(); }
    };
    window._cdashFrenteClose = function () { var l = document.getElementById('cdashFrenteList'); if (l) l.classList.remove('open'); };
    window._cdashFrenteFilter = function (v) { window._cdashFrenteRenderList(v); window._cdashFrenteOpen(); };
    window._cdashFrenteToggle = function (ev) {
        var l = document.getElementById('cdashFrenteList');
        if (l && l.classList.contains('open')) {
            ev.preventDefault();
            window._cdashFrenteClose();
            var inp = document.getElementById('cdashFrenteInput'); if (inp) inp.blur();
            return;
        }
        window._cdashFrenteOpen();
    };
    // ── Filtros avanzados (frente + rango de meses) ──────────────────────────
    // Mismo gesto que en /admin/equipos: el botón abre un panel colgante y un clic
    // fuera lo cierra. El listener del documento se registra UNA vez —el <script>
    // del modal se re-ejecuta en cada navegación SPA— o se acumularía uno por visita.
    window._cdashAdvToggle = function (ev) {
        if (ev) ev.stopPropagation();
        var panel = document.getElementById('cdashAdvPanel');
        if (!panel) return;
        var abrir = (panel.style.display === 'none' || !panel.style.display);
        // Al abrirlo se recogen las listas de Descripción y Categoría: el panel cuelga
        // justo encima de ellas.
        if (abrir) window._cdashCerrarListas('cdashAdvPanel');
        panel.style.display = abrir ? 'flex' : 'none';
        if (!abrir) window._cdashFrenteClose();
    };

    window._cdashAdvCerrar = function () {
        var panel = document.getElementById('cdashAdvPanel');
        if (panel) panel.style.display = 'none';
        // Su lista de Frente se va con él: si no, quedaba flotando sin panel debajo.
        var f = document.getElementById('cdashFrenteList');
        if (f) f.classList.remove('open');
    };

    if (!window._cdashAdvFueraBound) {
        window._cdashAdvFueraBound = true;
        document.addEventListener('mousedown', function (ev) {
            var panel = document.getElementById('cdashAdvPanel');
            if (!panel || panel.style.display === 'none') return;
            // Dentro del panel o sobre el propio botón: no se cierra.
            if (ev.target.closest('#cdashAdvPanel') || ev.target.closest('#cdashAdvBtn')) return;
            if (window._cdashAdvCerrar) window._cdashAdvCerrar();
        });
    }

    // Pinta el boton de rojizo cuando hay algun filtro avanzado puesto. Es la unica
    // senal de que el dashboard esta acotado: recogidos en un panel, sin ella no habria
    // forma de saberlo. Mismo gesto que en /admin/equipos.
    window._cdashMarcarAvanzados = function () {
        var btn = document.getElementById('cdashAdvBtn');
        if (!btn) return;
        var hay = !!(
            (document.getElementById('cdashFrente') || {}).value ||
            (document.getElementById('cdashDesde')  || {}).value ||
            (document.getElementById('cdashHasta')  || {}).value
        );
        btn.classList.toggle('con-filtros', hay);
    };

    window._cdashAdvLimpiar = function () {
        var d = document.getElementById('cdashDesde'); if (d) d.value = '';
        var h = document.getElementById('cdashHasta'); if (h) h.value = '';
        // Por su propio camino: deja el hidden, el placeholder y la X coherentes.
        window._cdashFrenteSelect('', '');
    };

    window._cdashFrenteSelect = function (val, label) {
        var h = document.getElementById('cdashFrente'); if (h) h.value = val;
        var inp = document.getElementById('cdashFrenteInput');
        if (inp) { inp.value = ''; inp.placeholder = label || CDASH_FRE_LBL; }
        var box = document.getElementById('cdashFrenteBox'); if (box) box.classList.toggle('active', !!val);
        var clr = document.getElementById('cdashFrenteClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        window._cdashFrenteClose();
        window._cdashFetch();
    };
</script>
