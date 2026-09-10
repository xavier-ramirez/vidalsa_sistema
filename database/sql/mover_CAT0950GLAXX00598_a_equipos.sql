-- =============================================================================
-- MIGRACIÓN MANUAL: Mover equipo CAT0950GLAXX00598
--                   de  equipos_auxiliares  →  equipos
--
-- SERIAL buscado : CAT0950GLAXX00598
-- Ejecutar en    : servidor MySQL/MariaDB de producción
-- Autor          : Generado vía Antigravity IDE  –  2026-09-07
-- =============================================================================

-- ▸ Siempre dentro de una transacción para poder revertir si algo falla.
START TRANSACTION;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 0 · Verificar que el registro existe en equipos_auxiliares
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    a.ID_AUXILIAR,
    a.TIPO,
    a.MARCA,
    a.MODELO,
    a.SERIAL,
    a.CODIGO_INTERNO,
    a.CAPACIDAD,
    a.ANIO,
    a.ESTADO_OPERATIVO,
    a.ID_FRENTE_ACTUAL,
    a.ID_EQUIPO_HOST,
    a.FOTO,
    a.OBSERVACIONES,
    a.COMBUSTIBLE,
    a.CONSUMO_PROMEDIO,
    a.LINK_DOC_PROPIEDAD,
    a.LINK_CERTIFICADO,
    a.FECHA_VENCIMIENTO_CERT,
    a.NRO_DOC_PROPIEDAD,
    a.DETALLE_UBICACION_ACTUAL,
    a.CONFIRMADO_EN_SITIO,
    a.CREADO_POR,
    a.created_at
FROM equipos_auxiliares a
WHERE a.SERIAL = 'CAT0950GLAXX00598'
  AND a.deleted_at IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 1 · Guardar el ID_AUXILIAR en una variable
-- ─────────────────────────────────────────────────────────────────────────────
SET @id_aux = (
    SELECT ID_AUXILIAR
    FROM   equipos_auxiliares
    WHERE  SERIAL = 'CAT0950GLAXX00598'
      AND  deleted_at IS NULL
    LIMIT  1
);

SELECT CONCAT('ID_AUXILIAR encontrado: ', IFNULL(@id_aux, 'NULL – ABORTANDO')) AS info;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 2 · Insertar el equipo en la tabla `equipos`
--
-- ⚠ AJUSTA ANTES DE EJECUTAR:
--   · id_tipo_equipo : reemplaza el 0 con el ID real.
--                      Usa: SELECT id, nombre FROM tipo_equipos ORDER BY nombre;
--   · CODIGO_PATIO   : debe ser ÚNICO. Por defecto se usa CODIGO_INTERNO o SERIAL.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO equipos (
    TIPO_EQUIPO,
    CATEGORIA_FLOTA,
    CODIGO_PATIO,
    MARCA,
    MODELO,
    CAPACIDAD,
    ANIO,
    COLOR,
    id_tipo_equipo,
    NUMERO_ETIQUETA,
    SERIAL_CHASIS,
    SERIAL_DE_MOTOR,
    COMBUSTIBLE,
    CONSUMO_PROMEDIO,
    LINK_GPS,
    FOTO_EQUIPO,
    ID_FRENTE_ACTUAL,
    CONFIRMADO_EN_SITIO,
    ESTADO_OPERATIVO,
    ID_ANCLAJE,
    CREADO_POR,
    OBSERVACIONES,
    created_at,
    updated_at
)
SELECT
    a.TIPO                               AS TIPO_EQUIPO,
    NULL                                 AS CATEGORIA_FLOTA,
    COALESCE(a.CODIGO_INTERNO, a.SERIAL) AS CODIGO_PATIO,
    a.MARCA,
    a.MODELO,
    a.CAPACIDAD,
    a.ANIO,
    NULL                                 AS COLOR,
    -- ⚠ Reemplaza el 0 con el id_tipo_equipo correcto:
    0                                    AS id_tipo_equipo,
    NULL                                 AS NUMERO_ETIQUETA,
    a.SERIAL                             AS SERIAL_CHASIS,
    NULL                                 AS SERIAL_DE_MOTOR,
    a.COMBUSTIBLE,
    a.CONSUMO_PROMEDIO,
    NULL                                 AS LINK_GPS,
    a.FOTO                               AS FOTO_EQUIPO,
    a.ID_FRENTE_ACTUAL,
    a.CONFIRMADO_EN_SITIO,
    a.ESTADO_OPERATIVO,
    a.ID_EQUIPO_HOST                     AS ID_ANCLAJE,
    a.CREADO_POR,
    a.OBSERVACIONES,
    a.created_at,
    NOW()                                AS updated_at
FROM equipos_auxiliares a
WHERE a.ID_AUXILIAR = @id_aux;

SET @nuevo_id_equipo = LAST_INSERT_ID();
SELECT CONCAT('Nuevo ID_EQUIPO creado: ', @nuevo_id_equipo) AS info;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 3 · Redirigir registros relacionados
-- ─────────────────────────────────────────────────────────────────────────────

-- 3a. Historial de movilizaciones
UPDATE movilizacion_historial
SET    ID_EQUIPO   = @nuevo_id_equipo,
       ID_AUXILIAR = NULL,
       updated_at  = NOW()
WHERE  ID_AUXILIAR = @id_aux;

SELECT CONCAT(ROW_COUNT(), ' movilizaciones redirigidas') AS info;

-- 3b. Audit log
UPDATE equipo_audit_log
SET    ID_EQUIPO   = @nuevo_id_equipo,
       ID_AUXILIAR = NULL,
       updated_at  = NOW()
WHERE  ID_AUXILIAR = @id_aux;

SELECT CONCAT(ROW_COUNT(), ' entradas de audit_log redirigidas') AS info;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 4 · Soft-delete del auxiliar (queda como respaldo, NO se borra)
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE equipos_auxiliares
SET    deleted_at    = NOW(),
       OBSERVACIONES = CONCAT(
           COALESCE(OBSERVACIONES, ''),
           ' | [', NOW(), '] Movido a tabla equipos ID_EQUIPO=', @nuevo_id_equipo
       )
WHERE  ID_AUXILIAR = @id_aux;

SELECT CONCAT(ROW_COUNT(), ' auxiliar marcado como eliminado') AS info;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 5 · Verificación final
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    e.ID_EQUIPO,
    e.TIPO_EQUIPO,
    e.CODIGO_PATIO,
    e.MARCA,
    e.MODELO,
    e.SERIAL_CHASIS,
    e.COMBUSTIBLE,
    e.ESTADO_OPERATIVO,
    e.ID_FRENTE_ACTUAL,
    e.created_at
FROM equipos e
WHERE e.ID_EQUIPO = @nuevo_id_equipo;

-- ─────────────────────────────────────────────────────────────────────────────
-- ✅ Si todo se ve correcto → COMMIT
-- ❌ Si algo está mal       → ROLLBACK
-- ─────────────────────────────────────────────────────────────────────────────
COMMIT;
-- ROLLBACK;


-- =============================================================================
-- CONSULTAS DE AYUDA
-- =============================================================================

-- ► Obtener id_tipo_equipo:
--   SELECT id, nombre FROM tipo_equipos ORDER BY nombre;

-- ► Verificar unicidad de CODIGO_PATIO:
--   SELECT CODIGO_PATIO FROM equipos WHERE CODIGO_PATIO = 'CAT0950GLAXX00598';

-- ► Confirmar el equipo en producción:
--   SELECT * FROM equipos WHERE SERIAL_CHASIS = 'CAT0950GLAXX00598';

-- ► Confirmar que el auxiliar quedó en soft-delete:
--   SELECT ID_AUXILIAR, SERIAL, deleted_at, OBSERVACIONES
--   FROM   equipos_auxiliares
--   WHERE  SERIAL = 'CAT0950GLAXX00598';
