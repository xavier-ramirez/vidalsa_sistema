# MAPA — Sistema VIDALSA

Mapa corto del proyecto para orientarse **sin leer todo el código**. Si algo no está aquí,
está en los comentarios del propio archivo (el proyecto se comenta en español y explica el
*porqué*, no el *qué*).

## 1. Qué es y cómo se corre

Laravel 12 + Blade + JS a mano (sin build: no hay Vite/npm en producción). Dos mundos en la
misma app: **Flota** (equipos, documentos, mapa) y **Almacén** (inventario, notas, traspasos).
MySQL/MariaDB. Los archivos viven en **Google Drive** y se sirven por un proxy propio.

| Cosa | Dónde |
|---|---|
| Local | Apache de XAMPP, vhost en `http://127.0.0.1:8000` (la BD local es una COPIA del servidor) |
| Pruebas | `php artisan test` (~300, PHPUnit, `tests/Feature`) — ver §7 |
| Despliegue | `docker/start.sh`: `migrate --force` + `schedule:work` + php-fpm. Hosting: EasyPanel |
| Tareas | `routes/console.php` (verificación de documentos, compresión de PDF, limpieza de caché) |

## 2. Convenciones que hay que respetar

- **Columnas en MAYÚSCULAS** y PK propias: `usuarios.ID_USUARIO`, `equipos.ID_EQUIPO`,
  `verificacion_documento_registro.ID_REGISTRO`… `$user->id` es `null`: usar `getKey()`.
- **Usuarios**: tabla `usuarios` (no `users`). Permisos = clave literal en `usuarios.PERMISOS`
  (`can('super.admin')`), sin alias ni roles intermedios.
- **Visibilidad por frentes**: `Usuario::aplicarScopeIds()` / `aplicarBloqueoIds()`.
  `ID_FRENTE_BLOQUEADO` resta a todos, incluso a los globales.
- **SPA propia** (`navegacion.js`): las páginas se inyectan en `.main-viewport`. Por eso:
  - los `<script>` del layout **no llevan `defer`** (rompe el orden);
  - el JS de una vista se re-ejecuta en cada visita → guardas `window.__xxxBound` / `Init`;
  - el CSS/JS versionan con `?v=filemtime`.
- **Textos con tildes rotas**: cast `App\Casts\MojibakeFix` (no duplicar la lógica).
- **Caché**: driver `database`. Las listas caras se cachean con una *versión* que se sube al
  escribir (`App\Support\CacheVersion::bump`, de-duplicada por request).
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
| **Control de Auditoría** | `/admin/historial-documentos` | `HistorialDocumentosController` + `CompresionPdfController` | `admin/historial_documentos/**`, `admin/compresion_pdf/panel.blade.php` | `historial_documentos_index.js` |
| Archivos de Drive | `/storage/google/{id}` | `GoogleDriveController` (proxy + copia local + miniaturas) | — | — |
| Offline (PWA) | — | `OfflineController`, `OfflineSyncController` | — | `public/js/offline/*`, `*-offline.js` |

Rutas: todo en `routes/web.php`, bajo `auth` → `password.change.check` → `prefix('admin')`, y
lo sensible dentro de `can:super.admin`.

## 4. Servicios y comandos

- `GoogleDriveService` — subidas, papelera, **copia local** del PDF recién subido y miniaturas.
  Nunca instanciar Drive dentro de una transacción larga.
- `LectorDocumentoPdf` — **OCR con Drive** (copia como Documento de Google, exporta texto y
  borra la copia) + extracción de título, póliza, ROTC y RACDA.
- `CorrectorFichaDocumento` — **único** sitio que escribe en la ficha desde la verificación.
- `CompresorPdf` + `docs:comprimir` — comprime PDFs pesados de noche.
- `VerificarDocumentos` (`docs:verificar-documentos`) — compara ficha ↔ PDF (ver §5).
- `InventarioService`, `TraspasoService`, `DevolucionService`, `LogisticaAlmacenService`,
  `CompatibilidadProductoService` — reglas del almacén.
- `Gps51Service` — posición de los equipos por API compartida (lento, por tandas).
- `app/Support/` — `CacheVersion`, `EnlacesDocumentos` (¿es la BD del servidor?),
  `PanelDocumentos` (datos de las pestañas de auditoría), `OfflineVersion`, `VersionVistas`.

## 5. Reglas de negocio que no se adivinan leyendo el código

**Documentos (Control de Auditoría).** Cada equipo tiene título, póliza, ROTC y RACDA en
`documentacion` (+ sus fechas). `docs:verificar-documentos` los lee con OCR y compara:
- **Manda el documento**: lo que dice el PDF se escribe en la ficha… salvo **placa y serial**
  (nunca), PDF de otro vehículo, leído a medias, sin confirmar, o **PDF anterior**
  (`VerificacionDocumento::documentoAnterior`: vence >45 días antes que la ficha → no se toca).
- Lo que no puede resolver la tarea queda "para revisar a mano" en el visor.
- Horarios en **constantes**: `VerificarDocumentos::HORARIO` (20:00–00:00, 4 lectores en
  paralelo con `--parte/--de`) y `ComprimirDocumentos::HORARIO` (02:00–05:00). Sin trabajo, el
  programador no lanza nada. Botón **"Revisar ahora"** = `pedirAhora()` (caché 12 h).
- Un "No se pudo leer" se vuelve a intentar 3 veces esa noche y **una vez por noche** después.
- En el Historial, la **subida de un PDF y los datos guardados con ella salen en una sola
  fila** (misma ficha, documento, autor y <2 min).

**Fotos de equipos.** `Equipo::fotoParaMostrar()` decide, en este orden: foto del **color** de
la unidad en su ficha del catálogo → foto del **modelo** (`FOTO_REFERENCIAL`) → foto propia de
la unidad (`FOTO_EQUIPO`, hoy sin pantalla que la suba). Una ficha = modelo + año + TIPO.

**Almacén.** El stock vive por almacén (`almacen_stock`) y se mueve con
`movimientos_inventario`; las salidas se documentan con la **Nota de Entrega** (formato
congelado por nota). Cada presentación (UM) es un producto aparte: la conversión es manual.
Deshacer un movimiento es un borrado **duro** a propósito.

**Sin internet.** `offline-sync.js` baja una copia a IndexedDB y los módulos tienen su versión
`*-offline.js` que pinta **igual** que online; lo que se hace sin conexión va a un *outbox*.
Stock y movimientos **nunca** se cachean; catálogos sí.

## 6. Datos: tablas principales

`equipos` · `documentacion` (1-1 con equipo) · `equipo_audit_log` (historial, `CAMBIOS` JSON)
· `caracteristicas_modelo` + `catalogo_colores` (fichas y fotos) · `equipos_auxiliares` ·
`fallas` · `movilizacion_historial` · `frentes_trabajo` · `usuarios` ·
`verificacion_documento_registro` (una fila por documento leído) · `compresion_pdf_registro` ·
`almacenes`, `almacen_stock`, `movimientos_inventario`, `productos_inventario`, `traspasos`.

## 7. Pruebas

- `php artisan test` corre todo; `--filter=NombreTest` para lo tocado.
- Van contra la **BD local de verdad** con `DatabaseTransactions` (`tests/MySqlTestCase`):
  **nunca `RefreshDatabase`**, borraría la base de trabajo.
- Google Drive se sustituye por `tests/DriveFalso` (nada sale a la red).
- Hay guiones de navegador (Chrome headless por CDP) fuera del repo; se usan solo a petición.

## 8. Cuidado con esto

- El service worker es `resources/sw.js`, servido por la ruta `/sw.js`; su versión de caché se invalida sola por commit: no tocarla a mano.
- La sesión se cierra por inactividad (20 min en producción); `ValidarSesionUnica` excluye auth.
- Migraciones: en local pueden estar desfasadas → usar guardas `hasColumn`; no insertar filas a
  mano en producción, hacerlo con migración o SQL revisado.
- Git: commit/push **solo** cuando el usuario lo pide.
