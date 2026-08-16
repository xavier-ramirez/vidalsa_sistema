{{-- Botón CÁMARA de la card de distribución. Está en un partial propio porque las dos
     vistas de distribution_stats (por Frente y por Tipo) lo llevan igual: así el markup
     vive en un solo sitio y no se copia dos veces.

     Reusa window.descargarPanelHtmlFDM (fleet_dashboard.js), el mismo captador de los
     paneles del Dashboard de Flota — se carga en todas las páginas desde estructura_base,
     ya resuelve la carga diferida de html2canvas y oculta los botones en la foto. NO se
     escribe una captura nueva aquí.

     Captura #distributionStatsContainer (el envoltorio de index.blade), no la <ul>: así la
     foto sale con su título y se entiende sola.

     NOTA: esta card está duplicada en pintarDistribucion() de equipos-offline.js. Allí NO
     se agrega este botón A PROPÓSITO: html2canvas se descarga bajo demanda y sin conexión
     no llegaría, así que el botón fallaría justo cuando no hay red. --}}
<button type="button"
        onclick="event.stopPropagation(); window.descargarPanelHtmlFDM('distributionStatsContainer', '{{ $nombre }}')"
        title="Descargar imagen"
        class="fdm-cam"
        style="margin-left: auto;">
    <i class="material-icons">photo_camera</i>
</button>
