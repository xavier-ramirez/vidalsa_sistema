# Agents Guide - Vidalsa Sistema

## Directory Map

### `/app/Models/`
18 modelos Eloquent. Entidad central: `Equipo`. Relaciones clave:
- `Equipo` -> `TipoEquipo`, `CaracteristicaModelo`, `FrenteTrabajo`, `Documentacion`, `Responsable`, `SubActivo`
- `Movilizacion` -> `Equipo`, `FrenteTrabajo` (origen/destino)
- `Consumible` -> `Equipo`, `FrenteTrabajo`, `SuministroOrigen`
- `Usuario` -> `Role`, `FrenteTrabajo` (asignacion multiple via CSV)

Permisos: `Usuario.PERMISOS` es un string CSV (`super.admin`, `equipos.create`, `user.edit`, etc.)
Scoping: `NIVEL_ACCESO` 1=GLOBAL, 2=LOCAL (filtra por frentes asignados).

### `/app/Http/Controllers/`
13 controllers. Los mas grandes y complejos:
- `EquipoController` (~800 lineas) - CRUD equipos, documentos, fotos, anclaje, fleet stats
- `MovilizacionController` - Despacho, recepcion, actas PDF, bulk mobilize
- `ConsumiblesController` - Carga masiva, matching automatico, graficos, CSV export
- `UserController` - CRUD usuarios, permisos checkbox, multi-frente assignment

### `/app/Http/Middleware/`
- `EnsurePasswordChanged` - Redirige a cambio de clave si `REQUIERE_CAMBIO_CLAVE=true`
- `ValidarSesionUnica` - Invalida sesion si `SESSION_TOKEN` no coincide con DB

### `/app/Services/`
- `GoogleDriveService` - Singleton con circuit breaker, cache de tokens (55min), timeout 120s

### `/app/Jobs/`
- `ProcessEquipoUploads` - Upload asincrono de fotos/docs a Google Drive (3 reintentos, 30s backoff)
- `DeleteGoogleDriveFile` - Eliminacion asincrona de archivos en Drive

### `/app/Observers/`
- `EquipoObserver`, `DocumentacionObserver`, `MovilizacionObserver` - Auditoria de cambios

### `/database/migrations/`
55 migraciones. Columnas en MAYUSCULAS. Tablas principales:
`equipos`, `frentes_trabajo`, `movilizacion_historial`, `consumibles`, `documentacion`, `usuarios`, `roles`, `tipo_equipos`, `caracteristicas_modelo`, `sub_activos`, `suministros_origen`, `despacho_combustible`, `solicitudes_mantenimiento`, `bloqueo_ip`

### `/resources/views/`
49 Blade templates. Layout master: `layouts/estructura_base.blade.php`
Modulos en `admin/`: equipos, movilizaciones, consumibles, usuarios, frentes, catalogo, herramientas, sub-activos.
Partials reutilizables en `partials/` (alerts, session timeout, background SVG).

### `/routes/`
- `web.php` - Rutas web protegidas bajo `/admin/`. Middleware: `auth` + `password.change.check`
- `api.php` - API movil con Sanctum. Endpoints: login, equipos, frentes, movilizaciones

### `/resources/css/`
- `app.css` - Entry point Tailwind v4
- `maquinaria/` - Estilos custom: login (branding BDV), globales, menu, catalogo

### `/resources/js/`
- `app.js` - Entry point (importa bootstrap)
- `bootstrap.js` - Configura Axios con CSRF token header

### `/mobile_app/` (Capacitor)
App movil Android con Capacitor v6 + Vite v5 + SQLite local.

### `/vidalsa_mobile/` (Expo)
App movil React Native con Expo v55. Screens: Login, Home, Equipment List/Detail, Mobilization.

## Business Rules

1. **Equipos** solo pueden estar en un frente a la vez (ID_FRENTE_ACTUAL)
2. **Movilizaciones** cambian el frente del equipo al ser recibidas (TRANSITO -> RECIBIDO)
3. **Anclaje**: Un equipo remolcador puede tener multiples remolcables; al movilizar el remolcador, los anclados se mueven tambien
4. **Consumibles**: Se cargan en lotes y el matching automatico los vincula a equipos por identificador
5. **Documentacion**: Alertas en dashboard cuando poliza/ROTC/RACDA estan por vencer
6. **Usuarios LOCAL**: Solo ven equipos/movilizaciones de sus frentes asignados
7. **Sesion unica**: Si un usuario inicia sesion en otro dispositivo, la sesion anterior se invalida
