# MAPA — Sistema VIDALSA

Mapa corto del proyecto para orientarse **sin leer todo el código**. Si algo no está aquí,
está en los comentarios del propio archivo (el proyecto se comenta en español y explica el
*porqué*, no el *qué*).

> Última revisión: **20-09-2026**.

## 1. Qué es y cómo se corre

Laravel 12 + Blade + JS a mano (sin build: no hay Vite/npm en producción). Dos mundos en la
misma app: **Flota** (equipos, documentos, mapa) y **Almacén** (inventario, notas, traspasos).
MySQL/MariaDB. Los archivos viven en **Google Drive** y se sirven por un proxy propio.

| Cosa | Dónde |
|---|---|
| Local | Apache de XAMPP, vhost en `http://127.0.0.1:8000` (la BD local es una COPIA del servidor) |
| Pruebas | `php artisan test` (327, PHPUnit, `tests/Feature`) — ver §7 |
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
  (`almacen.productos`, `almacen.movimiento`, `almacen.nota.eliminar`, `user.delete`): **ni
  super.admin las hereda**.
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
| Traspasos / Devoluciones | `/admin/almacen/recepcion`, `/admin/almacen/devolucion` | `TraspasoController`, `DevolucionMaterialController` | dentro de almacén | `devolucion_material.js` |
| Usuarios / Frentes | `/admin/usuarios`, `/admin/frentes` | `UserController`, `FrenteTrabajoController` | `admin/usuarios/**`, `admin/frentes/**` | `usuarios_index.js`, `frentes_spa.js` |
| **Control de Auditoría** | `/admin/historial-documentos` | `HistorialDocumentosController` + `CompresionPdfController` + `CargaMasivaDocumentosController` | `admin/historial_documentos/**`, `admin/compresion_pdf/panel.blade.php` | `historial_documentos_index.js` |
| Archivos de Drive | `/storage/google/{id}` | `GoogleDriveController` (proxy + copia local + miniaturas) | — | — |
| Offline (PWA) | — | `OfflineController`, `OfflineSyncController` | — | `public/js/offline/*`, `*-offline.js` |

Rutas: todo en `routes/web.php`, bajo `auth` → `password.change.check` → `prefix('admin')`, y
lo sensible dentro de `can:super.admin`.

## 4. Servicios y comandos

- `GoogleDriveService` — subidas, papelera, **copia local** del PDF recién subido y miniaturas.
  Nunca instanciar Drive dentro de una transacción larga.
- `LectorDocumentoPdf` — **OCR con Drive** (copia como Documento de Google, exporta texto y
  borra la copia) + extracción de título, póliza, ROTC y RACDA.
- `CargaMasivaDocumentos` — soltar varios PDF y repartirlos a su equipo (§5).
- `CorrectorFichaDocumento` — **único** sitio que escribe en la ficha desde la verificación.
- `CompresorPdf` + `docs:comprimir` — comprime PDFs pesados de noche. `disponible()` (¿está
  Ghostscript?) **lanza un proceso**: su respuesta se recuerda 10 min o la pestaña de
  Compresión tardaba 374 ms en abrir en vez de 111.
- `VerificarDocumentos` (`docs:verificar-documentos`) — compara ficha ↔ PDF (ver §5).
- `InventarioService`, `TraspasoService`, `DevolucionService`, `LogisticaAlmacenService`,
  `CompatibilidadProductoService` — reglas del almacén.
- `Gps51Service` — posición de los equipos por API compartida (lento, por tandas).
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
- Un documento cargado al que le falta en la ficha su fecha de emisión o de vencimiento se
  **relee al pulsar "Revisar ahora"** (una vez por pulsación, NO cada noche: hay PDF que nunca
  la traen); con sus fechas no se relee.
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

**Carga masiva de documentos** (menú Acciones de Auditoría). Se sueltan varios PDF y cada uno
se lee y se propone a su equipo; **nada se escribe hasta que se aplica la fila**.
- **Un archivo por petición**: el OCR lo hace Drive (~8 s por PDF) y treinta juntos se caerían
  por timeout. Para leer un PDF hay que **subirlo antes**, por eso lo analizado y no aplicado
  se borra de Drive al cerrar (`descartar()`).
- **Nunca pisa solo**: si la ficha ya tiene ese documento hace falta marcar "reemplazar", y un
  *documento anterior* se niega **incluso** marcándolo.
- **Modo ensayo**: pasa por todas las comprobaciones y dice qué haría, sin escribir ni borrar.
  Es la forma de probar contra los datos de verdad sin tocarlos.
- Un **RACDA** es de la empresa y nombra muchas unidades: se aplica a todas las que lo
  necesiten (tope 40 por archivo).

**Fotos de equipos.** `Equipo::fotoParaMostrar()` decide, en este orden: foto del **color** de
la unidad en su ficha del catálogo → foto del **modelo** (`FOTO_REFERENCIAL`) → foto propia de
la unidad (`FOTO_EQUIPO`, hoy sin pantalla que la suba). Una ficha = modelo + año + TIPO.

**Almacén.** El stock vive por almacén (`almacen_stock`) y se mueve con
`movimientos_inventario`; las salidas se documentan con la **Nota de Entrega** (formato
congelado por nota). Cada presentación (UM) es un producto aparte: la conversión es manual.
Deshacer un movimiento es un borrado **duro** a propósito.
- **Foto del producto** (`productos_inventario.FOTO`): miniatura a la izquierda de la
  descripción; se sube desde "Detalles del producto" (círculo con la cámara siempre a la vista; antes de subir se
  encuadra en el recorte compartido con el Catálogo, `partials/recorte_foto`) y exige
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
  mano en producción, hacerlo con migración o SQL revisado.
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
