# VIDALSA — Módulo de Inventario / Almacén

Documento de referencia para entender la arquitectura, tablas y flujos del módulo
de inventario multi-almacén del sistema VIDALSA (Laravel 12).

> **NOTA DE TERMINOLOGÍA:** Para el usuario final, TODO lo que sale de un almacén
> es una **"Salida"** con su **"Nota de Entrega"** (NE-YYYY-NNNN). La palabra
> "traspaso" NO existe en la UI. Internamente, cuando la salida va a un frente que
> tiene su propio almacén, el backend crea un **Traspaso** (tabla `traspasos`) de
> forma transparente. Los estados que el usuario ve son: **En tránsito** (ENVIADO),
> **Confirmada** (RECIBIDO), **Confirmada parcial** (RECIBIDO_PARCIAL),
> **Cancelada** (CANCELADO). Las tablas y código PHP sí usan "traspaso" internamente.

---

## 1. Arquitectura de Tablas (ERD textual)

```
almacenes                    frentes_trabajo
├─ ID_ALMACEN (PK)          ├─ ID_FRENTE (PK)
├─ CODIGO                    ├─ NOMBRE_FRENTE
├─ NOMBRE (unique)           ├─ TIPO_FRENTE
├─ TIPO (GENERAL|PROYECTO)   ├─ CONTRATOS (JSON)
├─ UBICACION                 ├─ ESTATUS_FRENTE
├─ ALMACENISTA               └─ ...
├─ CARGO_ALMACENISTA
├─ ESTATUS (ACTIVO|INACTIVO)         almacen_frentes (pivot N:M)
├─ CREADO_POR → usuarios             ├─ ID_ALMACEN → almacenes
└─ soft_delete                        └─ ID_FRENTE → frentes_trabajo
                                       UNIQUE(ID_ALMACEN, ID_FRENTE)

productos_inventario                  almacen_stock
├─ ID_PRODUCTO (PK)                   ├─ ID_STOCK (PK)
├─ CODIGO (unique, SKU)               ├─ ID_ALMACEN → almacenes
├─ NOMBRE                             ├─ ID_PRODUCTO → productos_inventario
├─ UM (UND,KG,LTS,MTS,CAJA,...)      ├─ CANTIDAD (decimal 16,3)
├─ CATEGORIA                          ├─ CANTIDAD_MINIMA (umbral alerta)
├─ UBICACION                          ├─ ULTIMA_ENTRADA
├─ ESTATUS (ACTIVO|INACTIVO)          ├─ ULTIMA_SALIDA
├─ CREADO_POR → usuarios              ├─ FECHA_ULT_MOVIMIENTO
└─ soft_delete                        └─ UNIQUE(ID_ALMACEN, ID_PRODUCTO)

movimientos_inventario (kardex)
├─ ID_MOVIMIENTO (PK)
├─ ID_ALMACEN → almacenes
├─ ID_PRODUCTO → productos_inventario
├─ TIPO (ENTRADA|SALIDA|AJUSTE|TRASPASO_ENTRADA|TRASPASO_SALIDA)
├─ CANTIDAD (siempre positiva; signo lo da TIPO)
├─ CANTIDAD_ANTERIOR (saldo antes)
├─ CANTIDAD_RESULTANTE (saldo después)
├─ FECHA
├─ ID_ALMACEN_CONTRAPARTE (solo traspasos: el otro almacén)
├─ ID_MOVIMIENTO_RELACIONADO (solo traspasos: movimiento espejo)
├─ ID_TRASPASO → traspasos
├─ ID_FRENTE → frentes_trabajo
├─ ID_USUARIO → usuarios
├─ REFERENCIA
├─ NUMERO_NOTA (NE-YYYY-NNNN, solo para SALIDA)
├─ NUMERO_CONTRATO, NUMERO_RQ, SOLICITANTE, DEPARTAMENTO
├─ MOTIVO, NOTAS
└─ created_at, updated_at

traspasos (cabecera de transferencia)
├─ ID_TRASPASO (PK)
├─ NUMERO (TR-YYYY-NNNN, unique)
├─ ID_ALMACEN_ORIGEN → almacenes
├─ ID_ALMACEN_DESTINO → almacenes
├─ ID_FRENTE_DESTINO → frentes_trabajo
├─ ESTADO (BORRADOR|ENVIADO|RECIBIDO|RECIBIDO_PARCIAL|CANCELADO)
├─ FECHA_ENVIO, FECHA_RECEPCION
├─ ID_USUARIO_CREO, ID_USUARIO_ENVIO, ID_USUARIO_RECEPCION
├─ REFERENCIA (aquí se copia el NE-YYYY-NNNN cuando viene de una salida)
├─ MOTIVO, NOTAS
└─ soft_delete

traspaso_lineas (detalle por producto)
├─ ID_LINEA (PK)
├─ ID_TRASPASO → traspasos
├─ ID_PRODUCTO → productos_inventario
├─ CANTIDAD_ENVIADA (inmutable después de ENVIADO)
├─ CANTIDAD_RECIBIDA (NULL hasta que destino confirma)
├─ ESTADO_LINEA (PENDIENTE|OK|FALTANTE|SOBRANTE|DANADO)
├─ NOTAS_LINEA (observaciones del receptor)
├─ ID_MOVIMIENTO_SALIDA → movimientos_inventario
├─ ID_MOVIMIENTO_ENTRADA → movimientos_inventario
└─ created_at, updated_at

numero_nota_counter (secuencial atómico)
├─ ANIO (PK)
└─ SIGUIENTE (último folio emitido)
```

---

## 2. Tipos de Almacén

| TIPO | Descripción | Visibilidad |
|------|-------------|-------------|
| **GENERAL** | Almacén central (ej: "Almacén Valencia"). Visible para todos los usuarios GLOBAL (NIVEL_ACCESO=1). | Todos los GLOBAL + LOCAL cuyos frentes estén vinculados |
| **PROYECTO** | Almacén de obra/frente (ej: "Bodega Norte"). Vinculado a uno o más frentes via `almacen_frentes`. | Solo usuarios cuyos frentes coinciden con el pivot |

Un almacén puede estar vinculado a VARIOS frentes (N:M). Un frente puede tener VARIOS almacenes.

---

## 3. Control de Acceso

### Visibilidad (qué almacenes ve el usuario)
- **`usuarios.NIVEL_ACCESO = 1` (GLOBAL):** Ve TODOS los almacenes.
- **`usuarios.NIVEL_ACCESO = 2` (LOCAL):** Solo almacenes vinculados a sus frentes asignados.
- Implementado en `Almacen::visiblesPara($user)` — NO depende de roles ni permisos.
- `usuarios.ID_FRENTE_BLOQUEADO`: lista negra de frentes que se restan.

### Permisos (qué puede hacer)
- **`almacen.movimiento`** (EXCLUSIVA): registrar entradas/salidas/ajustes/traspasos, confirmar recepciones.
- **`almacen.productos`** (EXCLUSIVA): editar catálogo de productos.
- **`almacen.nota.eliminar`** (EXCLUSIVA): eliminar notas de entrega, eliminar productos.
- **`super.admin`**: CRUD de almacenes (crear/editar/borrar el almacén en sí).

"EXCLUSIVA" = ni `super.admin` la otorga implícitamente; debe estar literal en `usuarios.PERMISOS`.

---

## 4. Flujo Principal: SALIDA → Traspaso → Confirmación → ENTRADA

```
┌─────────────────────────────────────────────────────────────────┐
│ ALMACÉN GLOBAL (Valencia)                                        │
│                                                                   │
│ 1. Usuario registra SALIDA a Frente "Obra Norte"                 │
│    → AlmacenController::registrarMovimientoLote()                │
│                                                                   │
│ 2. Sistema detecta: Frente "Obra Norte" tiene Almacén "Bodega"   │
│    → registrarSalidaViaTraspaso() (líneas ~1697-1723)            │
│                                                                   │
│ 3. Auto-crea Traspaso:                                            │
│    - BORRADOR auto-skip → ENVIADO inmediato                      │
│    - TRASPASO_SALIDA en kardex de Valencia (stock -QTY)           │
│    - Genera NE-YYYY-NNNN → campo REFERENCIA del traspaso         │
│    - PDF de Nota de Entrega imprimible                            │
└─────────────┬───────────────────────────────────────────────────┘
              │ Material físico viaja con NE impresa
              ▼
┌─────────────────────────────────────────────────────────────────┐
│ ALMACÉN LOCAL (Bodega Norte)                                     │
│                                                                   │
│ 4. Usuario ve banner "X notas pendientes" en /admin/almacen      │
│    → Click lleva a la bandeja /admin/almacen/recepcion?force=1   │
│                                                                   │
│ 5. Busca la NE-YYYY-NNNN que tiene impresa en mano               │
│    → Click abre detalle /admin/almacen/recepcion/{id}            │
│                                                                   │
│ 6. Confirma línea por línea:                                      │
│    Opción A: "Confirmar todo OK" (1 click, todo = enviado)       │
│    Opción B: Ajustar cantidades por línea + notas                │
│    → TraspasoController::recibir() → TraspasoService::recibir() │
│                                                                   │
│ 7. Resultado:                                                     │
│    - TRASPASO_ENTRADA en kardex de Bodega (stock +QTY_REAL)      │
│    - Traspaso → RECIBIDO (o RECIBIDO_PARCIAL si hay diferencia)  │
│    - traspaso_lineas: ESTADO_LINEA = OK|FALTANTE|SOBRANTE|DANADO│
│                                                                   │
│ 8. Bodega Norte tiene su propio inventario separado               │
│    → Puede hacer sus propias SALIDAS (consumo directo en obra)   │
│    → Kardex independiente de Valencia                             │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Máquina de Estados de Traspasos

```
  BORRADOR ──enviar()──→ ENVIADO ──recibir()──→ RECIBIDO
      │                     │                      │
      │                     │         (si hay    RECIBIDO_PARCIAL
      │                     │          diffs)
      │                     │
      └──cancelar()──→ CANCELADO ←──cancelar()──┘
                        (revierte stock si estaba ENVIADO)
```

| Estado | Stock origen | Stock destino | Acción |
|--------|-------------|---------------|--------|
| BORRADOR | Sin cambio | Sin cambio | Solo existe el registro |
| ENVIADO | -QTY (TRASPASO_SALIDA) | Sin cambio aún | Material en tránsito |
| RECIBIDO | (ya restado) | +QTY (TRASPASO_ENTRADA) | Todo OK |
| RECIBIDO_PARCIAL | (ya restado) | +QTY_REAL (lo que llegó) | Hay diferencias |
| CANCELADO | +QTY si estaba ENVIADO | Sin cambio | Se revierte |

---

## 6. Discrepancias (traspaso_lineas)

Cuando el destino confirma con cantidades diferentes:
- `CANTIDAD_ENVIADA`: lo que salió del origen (inmutable).
- `CANTIDAD_RECIBIDA`: lo que el destino reporta que llegó.
- `ESTADO_LINEA`:
  - **OK**: |recibida - enviada| < 0.0005 (tolerancia EPS)
  - **FALTANTE**: recibida < enviada
  - **SOBRANTE**: recibida > enviada
  - **DANADO**: marcado manualmente por el receptor
- `NOTAS_LINEA`: texto libre del receptor ("1 bolsa rota", "llegó mojado").

Si CUALQUIER línea tiene discrepancia → traspaso.ESTADO = RECIBIDO_PARCIAL.

---

## 7. Nota de Entrega (NE-YYYY-NNNN)

- Número secuencial por año, generado atómicamente via `numero_nota_counter` + `lockForUpdate()`.
- Se genera para SALIDAS (directas o vía traspaso).
- El PDF sigue formato VID-FO-GEN-019: contrato, RQ, solicitante, departamento, tabla de materiales, firmas.
- Cuando la SALIDA es vía traspaso, el NE se copia al campo `traspasos.REFERENCIA`.
- La bandeja de "Notas de Entrega" muestra el NE como identificador principal.

---

## 8. Kardex (movimientos_inventario)

Toda operación de stock genera un registro inmutable:
- `CANTIDAD_ANTERIOR`: saldo antes del movimiento.
- `CANTIDAD_RESULTANTE`: saldo después.
- `TIPO`: determina si suma o resta.

**Atomicidad**: toda escritura de stock ocurre dentro de `DB::transaction()` con `lockForUpdate()` en la fila de `almacen_stock`. Cero condiciones de carrera.

---

## 9. Endpoints API Principales

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/admin/almacen` | Dashboard inventario (stock, filtros, banner pendientes) |
| POST | `/admin/almacen/movimientos-lote` | Registrar ENTRADA/SALIDA/AJUSTE en lote |
| GET | `/admin/almacen/movimientos` | Kardex (historial de movimientos) |
| GET | `/admin/almacen/nota-entrega?numero=NE-...` | PDF de nota de entrega |
| DELETE | `/admin/almacen/nota-entrega` | Eliminar nota + revertir stock |
| POST | `/admin/almacen/productos` | Crear producto |
| PATCH | `/admin/almacen/productos/{id}` | Editar producto |
| GET | `/admin/almacen/recepcion?force=1` | Bandeja de notas de entrega pendientes |
| GET | `/admin/almacen/recepcion/nueva` | Formulario de entrada directa (ODC) |
| POST | `/admin/almacen/recepcion` | Crear traspaso (BORRADOR) |
| GET | `/admin/almacen/recepcion/{id}` | Detalle de traspaso / pantalla de confirmación |
| POST | `/admin/almacen/recepcion/{id}/enviar` | Enviar (BORRADOR → ENVIADO) |
| POST | `/admin/almacen/recepcion/{id}/recibir` | Confirmar recepción (ENVIADO → RECIBIDO) |
| POST | `/admin/almacen/recepcion/{id}/cancelar` | Cancelar (revertir si ENVIADO) |

---

## 10. Servicios Clave

| Servicio | Archivo | Responsabilidad |
|----------|---------|----------------|
| `InventarioService` | `app/Services/InventarioService.php` | Toda la lógica de movimientos de stock (entrada, salida, ajuste, traspaso). Transacciones + row locking. |
| `TraspasoService` | `app/Services/TraspasoService.php` | Máquina de estados de traspasos: crearBorrador, enviar, recibir, cancelar. Delega movimientos de stock a InventarioService. |
| `AlmacenController` | `app/Http/Controllers/AlmacenController.php` | Dashboard, CRUD de almacenes/productos, registrar movimientos en lote, genera PDFs. |
| `TraspasoController` | `app/Http/Controllers/TraspasoController.php` | Bandeja de notas de entrega, detalle de traspaso, confirmar recepción. |

---

## 11. Modelos Eloquent

| Modelo | Tabla | Relaciones clave |
|--------|-------|------------------|
| `Almacen` | almacenes | frentes() N:M, stocks(), movimientos() |
| `ProductoInventario` | productos_inventario | stocks(), movimientos() |
| `AlmacenStock` | almacen_stock | almacen(), producto() |
| `MovimientoInventario` | movimientos_inventario | almacen(), producto(), traspaso() |
| `Traspaso` | traspasos | lineas(), almacenOrigen(), almacenDestino(), frenteDestino() |
| `TraspasoLinea` | traspaso_lineas | traspaso(), producto() |
| `FrenteTrabajo` | frentes_trabajo | almacenes() N:M |

---

## 12. Cómo se vinculan Almacenes, Frentes y Usuarios

```
Usuario
├─ ID_FRENTE_ASIGNADO (CSV de frentes)   ← getFrentesIds()
├─ NIVEL_ACCESO (1=GLOBAL, 2=LOCAL)
├─ ID_FRENTE_BLOQUEADO (lista negra)
│
├─ almacenPorDefecto()  → busca en almacen_frentes el PROYECTO del frente
└─ getFrentesIds() - ID_FRENTE_BLOQUEADO = frentes efectivos

Almacen::visiblesPara($user):
  GLOBAL → todos los almacenes
  LOCAL  → almacenes cuyos frentes coinciden con los del usuario
```

---

## 13. Flujo de auto-conversión SALIDA → Traspaso

En `AlmacenController::registrarMovimientoLote()` (líneas ~1697-1723):

```php
// Si la SALIDA va a un frente que tiene un almacén DIFERENTE al origen...
$almacenDestinoId = AlmacenFrentes::where('ID_FRENTE', $idFrenteDestino)
    ->where('ID_ALMACEN', '!=', $idAlmacenOrigen)->first()?->ID_ALMACEN;

if ($almacenDestinoId) {
    // → Crear traspaso automático (BORRADOR → ENVIADO inmediato)
    return $this->registrarSalidaViaTraspaso(...);
} else {
    // → SALIDA normal (consumo directo, sin confirmación)
    return $this->inventario->registrarSalida(...);
}
```

Esto es lo que conecta la "Nota de Entrega" del almacén global con la "Bandeja de confirmación" del almacén local. No requiere intervención manual del usuario — el sistema decide automáticamente basándose en si el frente destino tiene un almacén propio.
