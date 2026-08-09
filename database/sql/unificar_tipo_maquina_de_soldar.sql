-- ============================================================================
--  Unificar el TIPO de auxiliar "máquina de soldar" en UNA sola clave
--  Canónica: MAQUINA_DE_SOLDAR      (se elimina la variante MAQUINA_SOLDAR)
-- ============================================================================
--
--  POR QUÉ
--  El código tenía dos claves para el mismo tipo: el mapa fijo del modelo usaba
--  'MAQUINA_SOLDAR' y la normalización de EquipoAuxiliarController (mayúsculas +
--  guiones bajos) producía 'MAQUINA_DE_SOLDAR'. Según por dónde entrara el dato
--  se guardaba una u otra, así que en las listas de tipos aparecían DOS entradas
--  para lo mismo. El código ya quedó unificado en MAQUINA_DE_SOLDAR; este script
--  alinea los datos que hubieran quedado con la clave vieja.
--
--  IMPORTANTE
--  En la base de datos de desarrollo NO había ninguna fila con 'MAQUINA_SOLDAR'
--  (los 22 auxiliares y las 2 filas de auxiliar_filtro ya usan la canónica), así
--  que allí este script no cambia nada. Se prepara para el SERVIDOR, donde sí
--  pueden existir registros creados por la vía del datalist.
--
--  Es IDEMPOTENTE: se puede correr varias veces sin efecto adicional.
--
--  CÓMO CORRERLO
--    1) Respaldo de las dos tablas (ver PASO 0).
--    2) PASO 1 para ver qué hay ANTES.
--    3) PASO 2 (la transacción) si el paso 1 muestra filas a corregir.
--    4) PASO 3 para confirmar.
--  Si el PASO 1 devuelve 0 filas, no hace falta correr nada más.
-- ============================================================================


-- ─── PASO 0 · RESPALDO (recomendado; ejecutar en shell, no en SQL) ───────────
--   mysqldump -u USUARIO -p BASE equipos_auxiliares auxiliar_filtro \
--     > respaldo_tipos_$(date +%F_%H%M).sql


-- ─── PASO 1 · DIAGNÓSTICO: ¿qué variantes existen hoy? ──────────────────────
SELECT 'equipos_auxiliares' AS tabla, TIPO, COUNT(*) AS filas
FROM equipos_auxiliares
WHERE TIPO LIKE '%SOLDA%'
GROUP BY TIPO
UNION ALL
SELECT 'auxiliar_filtro', TIPO, COUNT(*)
FROM auxiliar_filtro
WHERE TIPO LIKE '%SOLDA%'
GROUP BY TIPO
ORDER BY tabla, TIPO;
-- Esperado DESPUÉS de correr el script: una sola fila por tabla, MAQUINA_DE_SOLDAR.


-- ─── PASO 2 · CORRECCIÓN ────────────────────────────────────────────────────
--  Normaliza a MAQUINA_DE_SOLDAR cualquier variante del mismo tipo. El IN cubre
--  la clave vieja y las formas que puede dejar un tecleo manual (con espacios,
--  sin "DE", en singular/plural). No toca nada más: el WHERE es una lista
--  cerrada, no un LIKE, para no arrastrar por accidente un tipo distinto.
START TRANSACTION;

UPDATE equipos_auxiliares
SET    TIPO = 'MAQUINA_DE_SOLDAR'
WHERE  TIPO IN (
    'MAQUINA_SOLDAR',
    'MAQUINA SOLDAR',
    'MAQUINA DE SOLDAR',
    'MAQUINAS_DE_SOLDAR',
    'MAQUINA_DE_SOLDADURA',
    'MÁQUINA_DE_SOLDAR',
    'MÁQUINA DE SOLDAR'
);

UPDATE auxiliar_filtro
SET    TIPO = 'MAQUINA_DE_SOLDAR'
WHERE  TIPO IN (
    'MAQUINA_SOLDAR',
    'MAQUINA SOLDAR',
    'MAQUINA DE SOLDAR',
    'MAQUINAS_DE_SOLDAR',
    'MAQUINA_DE_SOLDADURA',
    'MÁQUINA_DE_SOLDAR',
    'MÁQUINA DE SOLDAR'
);

-- Revisa las filas afectadas que reporta el cliente MySQL.
-- Si el número cuadra:   COMMIT;
-- Si algo no cuadra:     ROLLBACK;
COMMIT;


-- ─── PASO 3 · VERIFICACIÓN ─────────────────────────────────────────────────
--  (a) Debe quedar UNA sola variante por tabla.
SELECT 'equipos_auxiliares' AS tabla, TIPO, COUNT(*) AS filas
FROM equipos_auxiliares
WHERE TIPO LIKE '%SOLDA%'
GROUP BY TIPO
UNION ALL
SELECT 'auxiliar_filtro', TIPO, COUNT(*)
FROM auxiliar_filtro
WHERE TIPO LIKE '%SOLDA%'
GROUP BY TIPO
ORDER BY tabla, TIPO;

--  (b) Vista general de tipos de auxiliar, para confirmar que no quedó ningún
--      otro duplicado del mismo estilo (dos claves para el mismo concepto).
SELECT TIPO, COUNT(*) AS filas
FROM equipos_auxiliares
WHERE TIPO IS NOT NULL AND TIPO <> ''
GROUP BY TIPO
ORDER BY TIPO;


-- ─── NOTA SOBRE EL HISTORIAL ────────────────────────────────────────────────
--  equipo_audit_log.CAMBIOS guarda textos ya escritos ("_aux_label":
--  "MAQUINA_DE_SOLDAR DENYO DAW-500S"). NO se tocan a propósito: son el registro
--  de lo que pasó en su momento y reescribirlos falsearía la auditoría.
