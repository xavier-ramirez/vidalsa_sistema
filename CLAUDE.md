# Vidalsa Sistema - Project Guide

## Overview

**Sistema de Gestion de Flota y Equipos** para CONSTRUCTORA VIDALSA 27, C.A.
Aplicacion web Laravel para gestionar equipos pesados, movilizaciones entre frentes de trabajo, consumibles (combustible, aceites, cauchos), documentacion vehicular y sub-activos.

## Tech Stack

- **Backend:** Laravel 12 (PHP 8.2+)
- **Frontend:** Blade templates + Tailwind CSS v4 + Vanilla JS + Axios
- **Build:** Vite 7
- **Database:** SQLite (dev), MySQL/PostgreSQL (prod)
- **Auth:** Session-based (web) + Laravel Sanctum (mobile API)
- **File Storage:** Google Drive (fotos, documentos)
- **PDF:** TCPDF (actas de traslado, reportes)
- **Mobile:** Expo/React Native (`vidalsa_mobile/`) + Capacitor (`mobile_app/`)

## Quick Start

```bash
composer setup          # install deps, .env, key:generate, migrate, npm install, build
composer dev            # artisan serve + queue:listen + pail + vite dev (concurrent)
```

## Build & Dev Commands

```bash
npm run dev             # Vite dev server con hot reload
npm run build           # Build produccion
php artisan serve       # Laravel dev server (puerto 8000)
php artisan migrate     # Ejecutar migraciones
php artisan queue:listen # Procesar jobs (uploads Google Drive)
```

## Project Structure

```
app/
  Console/Commands/     # Comandos artisan (SubirPdfMasivo)
  Http/
    Controllers/        # 13 controllers (Equipo, Movilizacion, Consumibles, etc.)
    Middleware/          # EnsurePasswordChanged, ValidarSesionUnica
  Jobs/                 # ProcessEquipoUploads, DeleteGoogleDriveFile
  Models/               # 18 modelos Eloquent (Usuario, Equipo, FrenteTrabajo, etc.)
  Observers/            # EquipoObserver, DocumentacionObserver, MovilizacionObserver
  Services/             # GoogleDriveService
config/                 # Configuracion Laravel
database/
  migrations/           # 55 migraciones
  seeders/              # 6 seeders
resources/
  css/                  # Tailwind + estilos custom (branding BDV)
  js/                   # app.js + bootstrap.js (Axios setup)
  views/                # 49 Blade templates
    layouts/            # estructura_base.blade.php (master layout)
    admin/              # Modulos: equipos, movilizaciones, consumibles, usuarios, etc.
    partials/           # Componentes reutilizables
routes/
  web.php              # Rutas web (auth + admin)
  api.php              # API movil (Sanctum)
mobile_app/            # App movil Capacitor/Vite
vidalsa_mobile/        # App movil Expo/React Native
```

## Key Domain Concepts

| Concepto | Tabla | Descripcion |
|----------|-------|-------------|
| **Equipo** | `equipos` | Vehiculo/maquinaria pesada con etiqueta, serial, estado operativo |
| **Frente de Trabajo** | `frentes_trabajo` | Ubicacion fisica donde opera el equipo (OPERACION/RESGUARDO) |
| **Movilizacion** | `movilizacion_historial` | Traslado de equipo entre frentes (TRANSITO -> RECIBIDO) |
| **Consumible** | `consumibles` | Registro de combustible/aceite/cauchos despachados |
| **Documentacion** | `documentacion` | Docs vehiculares: propiedad, poliza, ROTC, RACDA con vencimientos |
| **Sub-Activo** | `sub_activos` | Herramientas montadas en equipos (soldadoras, plantas, compresores) |
| **Caracteristica Modelo** | `caracteristicas_modelo` | Catalogo de specs por modelo/anio (combustible, aceites, bateria) |
| **Suministro Origen** | `suministros_origen` | Lotes de combustible/materiales que llegan a un frente |

## Architecture Patterns

- **Scoping por nivel de acceso:** `NIVEL_ACCESO=1` (GLOBAL, ve todo) vs `2` (LOCAL, solo sus frentes)
- **Permisos CSV:** `Usuario.PERMISOS` almacena permisos como string separado por comas
- **Anclaje de equipos:** Remolcadores pueden tener equipos remolcables anclados (`ID_ANCLAJE`)
- **Estado de movilizacion:** TRANSITO -> RECIBIDO (con recepcion directa como alternativa)
- **Matching de consumibles:** PENDIENTE -> CONFIRMADO via motor de matching automatico
- **Google Drive proxy:** Archivos servidos via cache local con fallback, circuit breaker de 5 min
- **Jobs asincrono:** Uploads de fotos/documentos se procesan en cola (ProcessEquipoUploads)

## Database

- Default: SQLite (`database/database.sqlite`)
- Session, cache, queue: todos usan driver `database`
- Migraciones en espanol (nombres de tablas y columnas en MAYUSCULAS)
- Relaciones principales: Equipo -> TipoEquipo, FrenteTrabajo, CaracteristicaModelo, Documentacion

## Auth & Security

- **Login:** Rate limiting (5 intentos = bloqueo temporal, 10 = bloqueo permanente IP)
- **Sesion unica:** Middleware `ValidarSesionUnica` - un solo dispositivo por usuario
- **Cambio de clave:** Middleware `EnsurePasswordChanged` - fuerza cambio en primer login
- **Bcrypt rounds:** 12
- **Dominio email:** Solo `@cvidalsa27.com` permitido para usuarios

## API Movil (routes/api.php)

- `POST /api/mobile/login` - Login con Sanctum token
- `GET /api/mobile/equipos` - Listar equipos
- `GET /api/mobile/frentes` - Listar frentes activos
- `POST /api/mobile/movilizaciones` - Registrar movilizacion

## Code Conventions

- **Idioma del codigo:** Espanol (nombres de modelos, tablas, columnas, vistas)
- **Columnas DB:** MAYUSCULAS (ID_EQUIPO, NOMBRE_FRENTE, ESTADO_OPERATIVO)
- **Modelos:** PascalCase espanol (FrenteTrabajo, TipoEquipo, MovilizacionHistorial)
- **Vistas:** snake_case espanol organizadas por modulo en `admin/`
- **Controllers:** PascalCase con sufijo Controller
- **Rutas web:** Prefijo `/admin/` para todas las rutas protegidas
- **Frontend:** No usa SPA framework - Blade + Vanilla JS + Axios para AJAX
- **CSS:** Tailwind v4 + archivos custom en `resources/css/maquinaria/`

## Important Notes

- La aplicacion usa un modelo `Usuario` custom (no el User default de Laravel)
- Google Drive integration requiere credenciales configuradas en `.env`
- Los observers rastrean cambios en Equipo, Documentacion y Movilizacion
- El dashboard tiene cache por usuario para evitar data leaks entre niveles de acceso
- Ruta de emergencia: `/system/force-fix-db/vidalsa123` para reparacion de BD
