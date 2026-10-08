# MAPA — Sistema VIDALSA

Mapa corto del proyecto para orientarse **sin leer todo el código**. Si algo no está aquí,
está en los comentarios del propio archivo (el proyecto se comenta en español y explica el
*porqué*, no el *qué*).

> Última revisión: **24-09-2026**. El detalle del almacén (tablas, flujos, estados de la Nota
> de Entrega) está en `BOCETO_INVENTARIO.md`.

## 1. Qué es y cómo se corre

Laravel 12 + Blade + JS a mano (sin build: no hay Vite/npm en producción). Dos mundos en la
misma app: **Flota** (equipos, documentos, mapa) y **Almacén** (inventario, notas, traspasos).
MySQL/MariaDB. Los archivos viven en **Google Drive** y se sirven por un proxy propio.

| Cosa | Dónde |
|---|---|
| Local | Apache de XAMPP, vhost en `http://127.0.0.1:8000` (la BD local es una COPIA del servidor) |
| Pruebas | `php artisan test` (403, PHPUnit, `tests/Feature`) — ver §7 |
| Despliegue | `docker/start.sh`: `migrate --force` + `schedule:work` + php-fpm. Hosting: EasyPanel |
| Tareas | `routes/console.php` (verificación de documentos, compresión de PDF, limpieza de caché) |

**El local corre con las tres cachés de Laravel puestas** (config, route, view). Medido: el
arranque del framework es el cuello (un PHP suelto responde en 4 ms), y con las cachés los
módulos abren entre un 20 % y un 37 % más rápido. **Consecuencia:** al tocar `routes/` hay
que correr `php artisan route:cache`, y al tocar `.env` o `config/`, `php artisan config:cache`
— si no, el cambio no se ve. Las vistas se recompilan solas por fecha.

Dos cosas del entorno de Windows que ya están resueltas y **no hay que volver a diagnosticar**:

- **`APP_KEY` por `SetEnv` en el vhost** (`C:\xampp\apache\conf\extra\httpd-vhosts.conf`). El
  Apache de XAMPP usa hilos y el lector de `.env` de Laravel no es seguro entre hilos: cada
  tanto una petición arrancaba sin la clave y respondía 500. Si se regenera la clave, hay que
  copiarla también ahí.
- **OPcache en 256 MB** (`php.ini`). Con 768 MB Apache se caía con `VirtualProtect() failed`.

## 2. Convenciones que hay que respetar

- **Columnas en MAYÚSCULAS** y PK propias: `usuarios.ID_USUARIO`, `equipos.ID_EQUIPO`,
  `verificacion_documento_registro.ID_REGISTRO`… `$user->id` es `null`: usar `getKey()`.
- **Usuarios**: tabla `usuarios` (no `users`). Permisos = clave literal en `usuarios.PERMISOS`
  (`can('super.admin')`), sin alias ni roles intermedios. Ojo con `Usuario::PERMISOS_EXPLICITOS`
  (`almacen.productos`, `almacen.movimiento`, `almacen.nota.eliminar`, `user.delete`,
  `docs.carga.masiva`): **ni super.admin las hereda**.
- **El `@can` de una vista esconde botones, NO protege la ruta.** Toda acción nueva necesita su
  middleware en el constructor del controlador (o un `abort_unless`).
- **Visibilidad por frentes**: `Usuario::aplicarScopeIds()` / `aplicarBloqueoIds()`.
  `ID_FRENTE_BLOQUEADO` resta a todos, incluso a los globales.
- **SPA propia** (`navegacion.js`): las páginas se inyectan en `.main-viewport`. Por eso:
  - los `<script>` del layout **no llevan `defer`** (rompe el orden);
  - el JS de una vista se re-ejecuta en cada visita → guardas `window.__xxxBound` / `Init`;
  - el CSS/JS versionan con `?v=filemtime`.
- **Textos con tildes rotas**: cast `App\Casts\MojibakeFix` (no duplicar la lógica).
- **Caché**: driver `database` por defecto. Las listas caras se cachean con una *versión* que
  se sube al escribir (`App\Support\CacheVersion::bump`, de-duplicada por request). **Excepción:**
  la lista de eventos del Control de Auditoría pesa ~3 MB y va al caché de **archivo**
  (`Cache::store('file')`): en MySQL costaba 116 ms guardarla y dejaba filas huérfanas.
- **Preloader**: `showPreloader/hidePreloader` con contador de referencias (`preloader.js`).
- **Nada de `100vw`** en anchos (cuenta la barra de scroll); nada de estilos inline nuevos en
  el layout.

## 3. Mapa por módulo

| Módulo | Ruta | Controlador | Vistas | JS |
|---|---|---|---|---|
| Menú/Inicio | `/menu` | `DashboardController` | `admin/partials`, `layouts/estructura_base` | `menu.js`, `layout_ui.js` |
| Equipos | `/admin/equipos` | `EquipoController` | `admin/equipos/**` | `equipos_index.js`, `equipos_form.js`, `equipos_bulk.js` |
| Auxiliares | `/admin/equipos-auxiliares` | `EquipoAuxiliarController` | `admin/equipos_auxiliares/**` | `aux_form_widgets.js`, `auxiliares_bulk.js` |
| Catálogo de modelos | `/admin/catalogo` | `CaracteristicaModeloController` | `admin/catalogo/**` | `catalogo_index.js`, `vincular_ficha.js` |
| Fallas | `/admin/fallas` | `FallaController` | `admin/fallas/**` | `fallas_index.js`, `falla_create_modal.js` |
| Movilizaciones | `/admin/movilizaciones` | `MovilizacionController` | `admin/movilizaciones/**` | `movilizaciones_index.js` |
| Mapa | `/mapa` (fuera de `admin`) | `MapaController`, `OleoductoController` | `admin/mapa*` | `mapa_index.js` |
| Almacén | `/admin/almacen` | `AlmacenController` | `admin/almacen/**` | inline + `almacen-offline.js`, `producto_suggest.js` |
| Consumibles | `/admin/consumibles` | `ConsumiblesController` | `admin/consumibles/**` | `consumibles_index.js`, `consumibles_graficos.js` |
| Traspasos / Devoluciones y correcciones de nota | `/admin/almacen/recepcion`, `/admin/almacen/ajuste-nota` | `TraspasoController`, `AjusteNotaController` | dentro de almacén | `ajuste_nota.js` |
| Usuarios / Frentes | `/admin/usuarios`, `/admin/frentes` | `UserController`, `FrenteTrabajoController` | `admin/usuarios/**`, `admin/frentes/**` | `usuarios_index.js`, `frentes_spa.js` |
| **Control de Auditoría** | `/admin/historial-documentos` | `HistorialDocumentosController` + `CompresionPdfController` + `CargaMasivaDocumentosController` | `admin/historial_documentos/**`, `admin/compresion_pdf/panel.blade.php` | `historial_documentos_index.js` |
| Archivos de Drive | `/storage/google/{id}` | `GoogleDriveController` (proxy + copia local + miniaturas) | — | — |
| Offline (PWA) | — | `OfflineController`, `OfflineSyncController` | — | `public/js/offline/*`, `*-offline.js` |

Rutas: todo en `routes/web.php`, bajo `auth` → `password.change.check` → `prefix('admin')`, y
lo sensible dentro de `can:super.admin`.

## 4. Servicios y comandos

- `GoogleDriveService` — subidas, papelera, **copia local** del PDF recién subido y miniaturas.
  **Un PDF cortado a medias NO se sube** (`comprobarPdfCompleto`: firma `%PDF-` y marca
  `%%EOF` en los últimos 2 KB). Es la puerta por la que entran todos —ficha, carga masiva
  y auxiliares—; una migración que MUEVE lo que ya existe pasa `$comprobar=false`. Nació de
  un ROTC truncado del equipo 23: nadie se enteró al subirlo y después no lo pudo leer nada
  (el OCR sacó 68 caracteres de 2.957 y Gemini lo rechaza). Lanza `App\Exceptions\PdfNoValido`
  y NO un RuntimeException pelado: Drive caído también lanza eso, y hay que poder decir
  **422 «vuelve a escanearlo»** en vez de **503 «reintente»**, que haría reintentar lo roto.
  El registro de un equipo nuevo (`EquipoController::store`) sube con `uploadFile` directo,
  así que llama a la comprobación a mano.
  Nunca instanciar Drive dentro de una transacción larga. **Un documento reemplazado va a la
  PAPELERA de Drive, nunca se borra para siempre** (`enviarAPapelera`): de la papelera se
  recupera con un clic si alguien reemplazó por error, y `files->delete` es definitivo. La
  única excepción son las copias de usar y tirar del OCR (`LectorDocumentoPdf`), que la
  prueba `DrivePapeleraTest` deja documentada y vigila.
- `LectorDocumentoPdf` — **OCR con Drive** (copia como Documento de Google, exporta texto y
  borra la copia) + extracción de título, póliza, ROTC y RACDA.
- `CargaMasivaDocumentos` — soltar varios PDF y repartirlos a su ficha, equipo o auxiliar (§5).
- `CorrectorFichaDocumento` — **único** sitio que escribe en la ficha desde la verificación.
- `CompresorPdf` + `docs:comprimir` — comprime PDFs pesados de noche. `disponible()` (¿está
  Ghostscript?) **lanza un proceso**: su respuesta se recuerda 10 min o la pestaña de
  Compresión tardaba 374 ms en abrir en vez de 111. Al cambiar un PDF por el comprimido
  (`EnlacesDocumentos::cambiar`) su **revisión se va con él** (`pasarRevisiones`): la revisión
  va atada al DRIVE_ID y, sin eso, la noche releía lo ya revisado y podía pisar lo decidido a mano.
- `VerificarDocumentos` (`docs:verificar-documentos`) — compara ficha ↔ PDF (ver §5).
- `InventarioService`, `TraspasoService`, `DevolucionService`, `LogisticaAlmacenService`,
  `CompatibilidadProductoService` — reglas del almacén.
- `Gps51Service` — posición de los equipos por API compartida (lento, por tandas).
- `ExcelLogoCorporativo` (trait) — **único** encabezado de los listados en Excel
  (`encabezadoCorporativo`: logo, título, EDICION/REVISION/FECHA y "Exportado por"): Equipos y su
  hoja de auxiliares, Auxiliares, Anclajes de auxiliares, Movilizaciones, Alertas de documentos
  y Equipos con GPS. No volver a copiarlo a mano. Tienen diseño PROPIO (viejo, a propósito sin
  tocar): Equipos anclados y Consumibles ("FECHA DE IMPRESIÓN", sin fila 4), Análisis de flota y
  los dos de Almacén.
- `ConvertsImageToWebp` (trait) — toda foto que se sube pasa por aquí: **WebP calidad 85** y
  reescalada, antes de ir a Drive.
- `app/Support/` — `CacheVersion`, `DocumentacionDeEquipo` (**único** sitio que arma lo que se
  escribe en `documentacion` al cambiar un PDF o su fecha), `EnlacesDocumentos` (¿es la BD del
  servidor?), `PanelDocumentos` (datos de las pestañas de auditoría), `OfflineVersion`,
  `VersionVistas`.

## 5. Reglas de negocio que no se adivinan leyendo el código

**Documentos (Control de Auditoría).** Cada equipo tiene título, póliza, ROTC y RACDA en
`documentacion` (+ sus fechas). `docs:verificar-documentos` los lee con OCR y compara:
- **Manda el documento**: lo que dice el PDF se escribe en la ficha… salvo **placa y serial**
  (nunca), PDF de otro vehículo o **PDF anterior** (`VerificacionDocumento::documentoAnterior`:
  vence >45 días antes que la ficha → no se toca). Si se leyó a medias o no se confirmó el
  vehículo, solo se ponen las **fechas que la ficha tiene vacías** (emisión y vencimiento).
- Lo que no puede resolver la tarea queda "para revisar a mano" en el visor. Una fila que ya
  revisó una persona, al releerse, solo recibe fechas vacías y sigue como ella la dejó.
- El panel del **visor de PDF** trae siempre la **Fecha de Emisión** (título, póliza, ROTC, RACDA;
  opcional, `equipos.updateMetadata`) para ponerla a mano; en Auditoría viene ya rellena con la
  del documento si la ficha no la tiene.
- Botón **Revisado** de la tabla (varias filas, sin visor): antes de marcarlas pone solo las
  fechas que la ficha tiene vacías y el documento trae (`CorrectorFichaDocumento::fechasVacias`,
  mismas puertas que la tarea, incluido el PDF anterior visto por su vencimiento).
- Al pulsar **"Revisar ahora"** se relee (una vez por pulsación, NO cada noche: hay PDF que
  nunca traen la fecha) solo lo que tiene un problema: "Datos distintos" y "No se pudo leer".
  Lo que ya **coincide no se relee nunca** con el botón, aunque le falte una fecha. Lo pidió el
  cliente dos veces: **no releer en masa** (p. ej. todos los títulos) salvo que lo pida él.
- **ROTC**: se reconoce por "R.O.T.C." o "Operadoras de Transporte de Carga"; de la tabla de
  flota salen número ("Número de ROTC") y emisión ("Fecha y Hora de Emisión").
- Letras que el OCR confunde por su forma (G/0, H/1, B/8, R/P…) se aceptan solo en la lista cerrada
  `LectorDocumentoPdf::LETRAS_PARECIDAS` (`malLeido()`); un serial cortado no cuenta como igual.
- Fecha de emisión: título viejo "Dado a los…", título nuevo del INTT "12 FEBRERO 2026" (o la
  línea de control "20260212/EL/…"); póliza "Fecha de Emisión" o, sin ella, el inicio de la
  vigencia del SEGURO (nunca la del recibo); anexo de flota, la fecha de su firma.
- Horarios en **constantes**: `VerificarDocumentos::HORARIO` (20:00–00:00, 4 lectores en
  paralelo con `--parte/--de`) y `ComprimirDocumentos::HORARIO` (02:00–05:00). Sin trabajo, el
  programador no lanza nada. Botón **"Revisar ahora"** = `pedirAhora()` (caché 12 h).
- Un "No se pudo leer" se vuelve a intentar 3 veces esa noche y **una vez por noche** después.
- La lista del Historial se deja hecha tras cada cambio y cada 5 min (`historial-documentos:calentar`),
  una por alcance de frentes, no por usuario: abrir el módulo nunca la reconstruye.
- En el Historial, la **subida de un PDF y los datos guardados con ella salen en una sola
  fila** (misma ficha, documento, autor y <2 min).
- **Alertas de vencimiento** (menú y reporte "Alertas de documentos"): *próximo a vencer* =
  vence en los próximos **30 días** (`DashboardController`). Ese reporte trae todos los frentes;
  los que el cliente no controla (POR DEFINIR, vendidos, CVG Puerto Ordaz, Gobernación Apure,
  MOP) se quitan al armar sus informes, no en el sistema.

**Carga masiva de documentos** (menú Acciones de Auditoría). Se sueltan varios PDF y cada uno
se lee y se propone a su ficha; **nada se escribe hasta que se aplica la fila**.
- **El modal SOLO sirve para soltar archivos.** El estado de cada PDF —de qué ficha es, si falta
  la fecha, si no se pudo leer— se ve en la tabla de **Revisión de documentos**, la misma donde
  sale lo que lee la tarea de la noche, y desde ahí se aplica o se descarta. Antes el modal
  tenía su propia lista con su propio "Aplicar": eran dos tablas de documentos en el mismo
  módulo (pedido 23-09-2026: una sola). Por eso cada análisis deja su fila con
  `ORIGEN='carga_masiva'` y estado **Por aplicar** / **Sin ficha reconocida** / **Otro
  documento (no se asoció)** / **Aplicado**.
  La tarea de la noche NO borra esas filas (ver el `delete` de hermanas en `procesar`).
- **El modal solo espera a la SUBIDA a Drive** (con el spinner de la app) y se cierra. La
  LECTURA (OCR de Drive, ~8 s por PDF) va en segundo plano: `ColaCargaMasiva`, una fila en
  `storage/app/private/carga_masiva_cola` con **un solo lector a la vez** (candado), que arranca
  tras responder (`defer`). Treinta lecturas sueltas ocuparían los 12 procesos de php-fpm. Lo
  que se quede sin leer lo recoge `docs:carga-masiva-pendientes` (programador, cada minuto).
  Con `php artisan serve` (un proceso) la siguiente subida espera a que acabe esa lectura.
- **Permiso propio y EXCLUSIVO `docs.carga.masiva`**: ni super.admin lo hereda
  (`Usuario::PERMISOS_EXPLICITOS`). Protege las tres rutas, el controlador y el botón del menú.
  Subir de uno en uno sigue siendo `user.edit`: eso toca UNA ficha que el usuario está mirando,
  esto reparte a ciegas por media flota. La migración `2026_09_23_100000` se la dio a los
  super.admin que ya podían usarla, para que nadie se quede fuera al desplegar.
- **El tipo lo elige el usuario, siempre** (desde el 30-09-2026 no hay "Reconocerlo solo"; el
  controlador lo exige). El servidor **comprueba** que el PDF sea ese documento
  (`esOtroDocumento`): primero su rótulo; si no cuadra o no hay rótulo, decide Gemini, al que se
  le dice qué se busca (`LectorGemini::leer(..., $esperado)`). Si la IA confirma lo elegido gana
  al rótulo, pero sale para revisar. Lo que es otro documento **no se asocia**: queda con estado
  `otro_documento`, sin equipos (no se puede aplicar) y con Descartar. Sin IA ni rótulo se
  acepta lo elegido, para revisar.
- **El rótulo**: gana el que aparece ANTES en el texto, no un orden fijo
  (`detectarTipo`). Un documento se anuncia en su encabezado y lo de después son menciones: el
  título del INTT se presenta en el carácter 3 y en su letra pequeña, por el 2.879, nombra el
  "certificado de circulación" — con el orden fijo se repartía como ROTC (visto en la prueba
  real del 23-09-2026 con dos títulos reales).
- **Los SEIS documentos del equipo**, los mismos de la ficha: título, póliza, ROTC, RACDA,
  Certificado asociado (`adicional` → `LINK_DOC_ADICIONAL`, vence) y Compraventa (`adicional_2`
  → `LINK_DOC_ADICIONAL_2`, no vence). De los cuatro primeros se comprueba que el PDF lo sea;
  **el certificado y la compraventa no traen rótulo fijo**: se toman como se eligen.
- **Equipos AUXILIARES**: si ningún equipo reconoce el documento, se busca un auxiliar **por
  serial** (no tienen placa). Solo admiten dos papeles —título y certificado—, y dónde va cada
  uno lo dice `EquipoAuxiliar::DOCS` / `DOCS_VENCE`, que ya existían. Una
  póliza, un ROTC o un RACDA nunca van a un auxiliar: no tiene columna donde ponerlos.
- **Un archivo por petición**: el OCR lo hace Drive (~8 s por PDF) y treinta juntos se caerían
  por timeout. Para leer un PDF hay que **subirlo antes**, por eso lo analizado y no aplicado
  se borra de Drive al cerrar (`descartar()`). **Descartar es solo de super.admin** (manda el
  PDF a la papelera de Drive, como Eliminar un documento).
- **Nunca pisa solo**: si la ficha ya tiene ese documento hace falta marcar "reemplazar", y un
  *documento anterior* se niega **incluso** marcándolo. Las mismas puertas en equipo y auxiliar.
- **Modo ensayo**: pasa por todas las comprobaciones y dice qué haría, sin escribir ni borrar.
  Es la forma de probar contra los datos de verdad sin tocarlos.
- Un **RACDA** es de la empresa y nombra muchas unidades: se aplica a todas las que lo
  necesiten (tope 40 por archivo).
- **Documento de embarque (BL)** (desde el 30-09-2026). Un Bill of Lading ampara decenas de
  unidades con UN PDF, así que no es una casilla de `documentacion`: tablas `embarques` (nº de
  BL único, buque, puertos, fecha, PDF) y `embarque_equipo` (un equipo, un embarque; guarda el
  VIN impreso). Lo lee `App\Support\BillOfLading` (formato CONGENBILL: "B/L NO.", "Port of
  loading", anexo de VIN) y se reconoce **solo por VIN / serial de chasis**. La propuesta nombra
  los VIN que no están en el sistema. **Se enlaza solo** al leerlo (`enlazarEmbarque`, desde el
  01-10-2026) a los equipos que reconoce por VIN, salvo si la lista la leyó la IA; lo que pide
  una decisión —el mismo BL con otro PDF o un equipo que ya está en otro BL, que piden
  "reemplazar"— se queda **Por aplicar** y la fila lo dice. El MISMO PDF soltado otra vez (misma
  huella md5) no cuenta como otro PDF: enlaza lo que falte sin cambiarle el archivo al embarque. La ficha lo pide aparte
  (`equipos/{id}/embarque`, como los anexos) y lo enseña solo si hay. El PDF está en
  `EnlacesDocumentos::DOCUMENTOS`: el job no lo retira mientras lo use un embarque y la
  compresión nocturna lo incluye.
- La búsqueda de fichas (`consulta()`) parte de `equipos` con LEFT JOIN a `documentacion`: los
  equipos sin fila de documentación (288 el 30-09-2026) antes no se hallaban ni por serial.

**Segundo lector: Gemini (`App\Services\LectorGemini`).** El lector de siempre es el OCR de
Drive + las reglas de `LectorDocumentoPdf`; la IA **solo entra donde ese no alcanza** y nunca
decide nada: propone y una persona confirma.
- **Carga masiva**: si el rótulo no confirma el tipo elegido, no hay texto, no se da con el
  equipo o falta la fecha de vencimiento, se le da el PDF entero (UNA consulta por archivo:
  `vistoPorIa` la guarda para las dos cosas). Lo que devuelve **rellena huecos, no
  pisa** lo que el OCR ya leyó (`mezclarLoDeIa`); si no aporta nada, la propuesta queda como
  estaba. Cuando ayuda, la fila de la tabla lo dice en su motivo y queda para revisar.
- **Revisión nocturna** (`docs:verificar-documentos`): solo los que quedan **"No se pudo
  leer"**, con el mismo modelo que la carga masiva. Lo leído se guarda en `LEIDO['ia']` y se resume en el MOTIVO;
  **no se aplica solo** (un ilegible ya sale en "para revisar"). Se apaga con `--sin-ia`.
- **Una sola vez por archivo**: un ilegible se relee 3 veces esa noche y vuelve a la cola cada
  noche; la marca `LEIDO['ia']` evita que los mismos documentos se coman el cupo entero todas
  las noches (`yaPasoPorLaIa`). Si tampoco pudo leerlo, la marca queda vacía y no se repite.
- **Ritmo y cupo** (plan gratis): un documento a la vez en todo el servidor (candado
  `gemini_lectura`), espera entre uno y otro por el tope por minuto y una cuenta diaria.
  **Un solo modelo** (`gemini-3.5-flash-lite`, ~500 al día): el de 20 al día se quitó porque
  se agotaba enseguida. El día del cupo se cuenta como Google (medianoche del Pacífico = 03:00
  en Venezuela), y si Google responde que se acabó el del día, no se le vuelve a preguntar hasta
  que renueve. Agotado el cupo, o sin `GEMINI_API_KEY`, todo sigue exactamente como antes.
- La clave va en el `.env` del servidor — **nunca** en el repositorio (`config/services.php`
  la lee de `GEMINI_API_KEY`, con los topes por env para no tocar código si se paga un plan).

**Mapa (`/mapa`, `mapa_index.js`).** Leaflet y sus librerías se piden A LA VEZ y se ejecutan en
orden; Leaflet lo baja `window.cargarLeaflet` (`lazy_loader.js`), el MISMO cargador del mapa del
modal de GPS de Equipos, así nunca se baja dos veces. En la primera visita `initMapa` mantiene el
spinner hasta que el mapa existe (aunque el modal ya hubiera traído Leaflet solo). Los créditos "Elaborado
por / Fuente" van SOLO en la foto exportada (`CREDITOS` → `dibujarCreditos`), no en pantalla.
Todo lo que llega tarde (proyectos, capas, municipios) mira `desmontado`: si se salió del mapa
no pinta. El spinner lo cuida la **generación** del contador (`window.preloaderGeneracion`, sube
con cada `hidePreloader(true)`): `spinOn/spinOff` del mapa no devuelven una referencia pedida
antes de que una navegación pusiera el contador a cero — restarla destapaba la pantalla nueva.
- **Capa Equipos (GPS51)** = un solo panel "Equipos con GPS" en la COLUMNA DE LA DERECHA
  (`.mapa-layout` > `#mapa-lateral`, ver `mapa.blade.php`), no flotando sobre el mapa: conteos,
  filtros frente / tipo / serial con los desplegables de Equipos (`uicomponents.js`) y la lista de
  equipos, sin colores. Los filtros (`eqPasaFiltro`) deciden también qué puntos se pintan. No hay
  otra leyenda, ni buscador aparte, ni reparto por frente: para eso están los filtros (pedido
  22-09-2026). La columna se abre y se cierra con la capa, y **cada vez hay que avisarle a Leaflet
  del ancho nuevo** (`eqAvisarTamano` → `invalidateSize`, dos veces: al momento y al acabar la
  animación); sin eso el mapa se queda con el tamaño viejo y sale a medio dibujar. Desde 1400 px el
  menú de la app se encoge para dejarle la columna libre; entre 901 y 1399 baja el tablero entero;
  por debajo de 900 el panel va DEBAJO del mapa.
- **Los equipos van AGRUPADOS** (Leaflet.markercluster, servido desde `/vendor/leaflet`): de lejos
  los que caen en el mismo sitio se juntan en una banderita con su número (`eqIconoGrupo` +
  `.mapa-eq-grupo`) y desde `EQ_GRUPO_HASTA_ZOOM` se ven todos sueltos. Dos reglas que no se
  adivinan leyendo el código:
  1. **Mover un marcador exige avisar al agrupador** (`refreshClusters`): lleva su propio árbol de
     posiciones y, sin el aviso, el icono se mueve pero su grupo sigue calculado con la coordenada
     vieja.
  2. **La capa se monta de una vez.** Mientras `capaEquipos.montando`, NADIE llama a `eqPintar`:
     el mapa sale entero, ya agrupado. La lista (`equiposGps`) trae para cada equipo su posición
     fresca (2 min) o, si caducó, la ÚLTIMA conocida (7 días, `TTL_ULTIMA`, marcada `vieja`) y la deja en
     `pendientes`: si a lo sumo una tanda no trae ninguna, el mapa sale AL INSTANTE y las tandas lo
     ponen al día en caliente (los marcadores solo se mueven). Si faltan más, espera a las tandas o
     a `EQ_MONTAJE_TOPE_MS` (2,5 s; eran 8 y el spinner se hacía eterno con la caché vacía). Antes se veían aparecer los equipos a goteo y juntarse en grupos
     una y otra vez con cada tanda.
  3. **`vieja` no es "al día":** no cuenta como respondida (`_sinRespuesta`, el pie lo avisa), no
     dibuja la flecha de marcha ni el "desde hace" del motor, la ficha dice "Últimos datos
     conocidos" y su dirección no se pide hasta que llega la nueva. `/direccion` devuelve también
     la coordenada a la que corresponde, y se guarda bajo esa.
- **La lista del panel se pinta por bloques** (`EQ_LISTA_BLOQUE` / `eqVisibles`), no entera: crece
  al bajar y vuelve al primer bloque al cambiar de filtro o al apagar la capa.
- **Excel del panel** (`mapa.equiposGps.exportar`): recibe los `ids` de lo filtrado (el servidor
  igual recorta a los frentes del usuario). Posición: la fresca (2 min), si no la ÚLTIMA
  conocida (`Gps51Service::frescasOUltimas`, 7 días; su fecha va en ÚLTIMA SEÑAL) y solo lo que no tiene ninguna se pide a
  GPS51 con tope de 20 s. Direcciones en UNA consulta (`Gps51Service::direcciones`: GPS51
  devuelve los puntos desordenados, se emparejan por coordenada). Identificación: placa → serial
  de chasis → serial de motor → código de patio → etiqueta: SOLO `MapaController::identificar`
  (llega al navegador en `ident` / `ident_por`), para el panel, las fichas y el Excel. Encabezado = el de Equipos (`ExcelLogoCorporativo::encabezadoCorporativo`).

**Fotos de equipos.** `Equipo::fotoParaMostrar()` decide, en este orden: foto del **color** de
la unidad en su ficha del catálogo → foto del **modelo** (`FOTO_REFERENCIAL`) → foto propia de
la unidad (`FOTO_EQUIPO`, hoy sin pantalla que la suba). Una ficha = modelo + año + TIPO.

**Almacén.** El stock vive por almacén (`almacen_stock`) y se mueve con
`movimientos_inventario`; las salidas se documentan con la **Nota de Entrega** (formato
congelado por nota). Cada presentación (UM) es un producto aparte: la conversión es manual.
Deshacer un movimiento es un borrado **duro** a propósito.
- **Foto del producto** (`productos_inventario.FOTO`): miniatura a la izquierda de la
  descripción; se sube desde "Detalles del producto" (foto en círculo con el icono de la cámara
  encima, sin fondo; antes de subir se encuadra en el recorte compartido con el Catálogo,
  `partials/recorte_foto`) y exige
  `almacen.productos`. Tocar la miniatura la abre en grande (`almVerFoto`). Va a una carpeta
  **privada** de Drive (`google.product_folder`): la cuenta del sistema tiene que ser editora, o
  Drive da 403. El navegador la recibe por `/storage/google/{id}` con sesión, nunca el enlace de
  Drive. El código del producto **no tiene columna**: va dentro de la descripción, pequeño y
  encima del nombre.
- **Devoluciones**: una `DEVOLUCION` queda ligada a la `SALIDA` de su nota
  (`ID_MOVIMIENTO_RELACIONADO`). En el PDF, la línea devuelta se marca con `(DEVUELTO: N UM)`
  y al pie va el bloque con fecha y motivo. El cuerpo firmado **no se altera** (no se tacha ni
  se cambia la cantidad entregada).
- **Vehículo de la nota**: `LogisticaAlmacenService` sugiere de la flota de los frentes del
  almacén, **sin flota pesada ni VACUUM** (el VACUUM está catalogado como flota liviana, así que
  se excluye por TIPO, no por categoría).
- **Departamento** de la salida: se sugiere lo que **ese usuario** ya usó, guardado en su
  navegador; solo se recuerda lo que llegó a una nota **registrada**.

**Sin internet.** `offline-sync.js` baja una copia a IndexedDB y los módulos tienen su versión
`*-offline.js` que pinta **igual** que online; lo que se hace sin conexión va a un *outbox*.
Stock y movimientos **nunca** se cachean; catálogos sí. La copia se baja sola (al abrir, cada
10 min, al volver la conexión): ya **no hay** botón "Copia local".
Si se toca la tabla del inventario hay que tocar **las dos** vistas (online y `*-offline.js`) o
las cabeceras dejan de cuadrar con el cuerpo.

## 6. Datos: tablas principales

`equipos` · `documentacion` (1-1 con equipo) · `equipo_audit_log` (historial, `CAMBIOS` JSON)
· `caracteristicas_modelo` + `catalogo_colores` (fichas y fotos) · `equipos_auxiliares` ·
`fallas` · `movilizacion_historial` · `frentes_trabajo` · `usuarios` ·
`verificacion_documento_registro` (una fila por documento leído) · `compresion_pdf_registro` ·
`almacenes`, `almacen_stock`, `movimientos_inventario`, `productos_inventario`, `traspasos`,
`almacen_logistica` (vehículos y choferes recordados por almacén).

## 7. Pruebas

- `php artisan test` corre todo; `--filter=NombreTest` para lo tocado. **La batería completa
  se pasa de los 300 s de `max_execution_time`**: correrla con
  `php -d max_execution_time=0 artisan test`.
- Van contra la **BD local de verdad** con `DatabaseTransactions` (`tests/MySqlTestCase`):
  **nunca `RefreshDatabase`**, borraría la base de trabajo.
- Dos redes para que eso no pueda pasar por accidente:
  1. `phpunit.xml` define `APP_CONFIG_CACHE` a un archivo que nunca existe, así las pruebas
     arman su configuración desde cero aunque haya `config:cache` (si no, `phpunit.xml` no
     podría imponer sqlite y las pruebas saldrían apuntando a MySQL).
  2. `Tests\TestCase::prohibirBaseReal()` para la ejecución si la conexión no es sqlite. Corre
     en `createApplication()`, **antes** de que los traits puedan migrar nada.
  `MySqlTestCase` redefine ese control (usa la base real a propósito) y en su `tearDown`
  devuelve la conexión a sqlite: `putenv` es global del proceso y si no, contagiaba al resto.
- Google Drive se sustituye por `tests/DriveFalso` (nada sale a la red).
- Hay guiones de navegador (Chrome headless por CDP) fuera del repo; se usan solo a petición.

## 8. Cuidado con esto

- **El `.env` local apunta al Drive REAL** (mismas credenciales y carpetas que el servidor),
  aunque la base sea una copia. Por eso, con `APP_ENV=local`, la carga masiva y la foto de
  producto **no borran** de Drive el archivo reemplazado: se deja huérfano y se anota. El
  subidor de PDF de uno en uno (visor del equipo) **todavía no tiene ese freno**.
- El service worker es `resources/sw.js`, servido por la ruta `/sw.js`; su versión de caché se
  invalida sola por commit: no tocarla a mano.
- La sesión se cierra por inactividad (20 min en producción); `ValidarSesionUnica` excluye auth.
- Migraciones: en local pueden estar desfasadas → usar guardas `hasColumn`; no insertar filas a
  mano en producción, hacerlo con migración o SQL revisado. Los arreglos de datos de una sola
  vez van en `database/sql/` (se pegan en phpMyAdmin; NO son migraciones). Las migraciones de
  datos del 21-09 (`2026_09_21_120000`…`210000`) se encadenan: la 200000 no toca los ROTC porque
  la 210000 los manda a releer todos (salvo los revisados por una persona, `APLICADO_POR`).
- **Bytes invisibles**: un `\b` escrito desde un script de Python sin `r''` se guardó como el
  carácter 0x08 y la regla del ROTC dejó de coincidir durante días. Al editar código con
  scripts, buscar después `[\x00-\x08\x0b\x0c\x0e-\x1f]`.
- Los informes de pólizas/documentos (Excel y presentación) se arman **fuera del repo** con el
  reporte del sistema y una copia de la base del servidor; no guardar Excel ni PDF dentro del
  proyecto (`storage/app/public` se sirve por `/storage`).
- Git: commit/push **solo** cuando el usuario lo pide.

## 9. Si el Control de Auditoría "va lento"

Ya está medido y optimizado; antes de tocar nada, leer esto:

- La pantalla del Historial **no lee una tabla**: fusiona cinco fuentes (audit log, documentos,
  equipos creados, auxiliares y catálogo), ~4.900 eventos, y los ordena en PHP. Armarla cuesta
  ~2 s; leerla del caché, ~10 ms.
- Por eso se **rehace al guardar, no al abrir**: `bumpDataVersion()` programa el trabajo con
  `app()->terminating()` para la vista sin filtros del usuario que hizo el cambio. Medido:
  abrir el módulo pasó de 2,5 s a 0,24 s.
- Las consultas **ya están bien**: todo por lotes, sin N+1. Un índice compuesto en
  `equipo_audit_log` se probó y se descartó (MySQL elige escaneo completo: la consulta pide
  5.000 de 6.400 filas y sola tarda 10 ms).
- Lo que queda pendiente si algún día hace falta más: que la primera página salga sin armar los
  4.900 eventos, fusionando las fuentes en SQL. Es reescribir el corazón del módulo.
