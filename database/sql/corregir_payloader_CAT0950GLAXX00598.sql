-- ============================================================================
--  CAT0950GLAXX00598 (CAT 950G) — estaba como AUXILIAR "MONTACARGA" y es un
--  PAYLOADER de la flota.
--
--  Lo que pasó: el 06/07/2026 18:14:22 alguien lo convirtió de equipo a
--  auxiliar. El equipo NO se borró de verdad, quedó soft-deleted (equipos
--  ID 53, deleted_at con esa misma marca de tiempo) y en el mismo segundo
--  nació la ficha de auxiliar (equipos_auxiliares ID 146).
--
--  Por eso esto NO crea un equipo nuevo: revive el que ya existe, con lo que
--  recupera su historia intacta (4 movilizaciones, 1 documento, 2 consumibles,
--  2 registros de auditoría, todos apuntando a ID_EQUIPO = 53). Dar de alta uno
--  nuevo dejaría todo eso huérfano.
--
--  La ficha de auxiliar no tiene NADA colgando (0 fallas, 0 documentos, 0
--  movilizaciones), así que se retira sin arrastrar nada.
--
--  Filtra por SERIAL y no por ID, para que sirva igual si en el servidor los
--  IDs no coinciden. Es IDEMPOTENTE: correrlo dos veces no hace nada la
--  segunda (cada UPDATE exige el estado de partida).
-- ============================================================================

START TRANSACTION;

-- 1) Devolver el equipo a la flota, con el tipo correcto.
--    id_tipo_equipo pasa de 10 a 6 = PAYLOADER. El 10 ya NO EXISTE en
--    tipo_equipos (la lista salta del 9 al 11): quedó huérfano cuando se
--    borró ese tipo, así que revivirlo sin corregirlo lo dejaría sin tipo.
--    CONSUMO_PROMEDIO se trae de la ficha de auxiliar (200.00): es del mismo
--    activo físico y en el equipo estaba vacío. Si no lo quieres, borra esa línea.
--    CATEGORIA_FLOTA ya dice FLOTA PESADA y ID_FRENTE_ACTUAL ya es 43 (el mismo
--    del auxiliar), por eso no se tocan.
UPDATE equipos
   SET deleted_at       = NULL,
       deleted_by       = NULL,
       id_tipo_equipo   = 6,
       CONSUMO_PROMEDIO = 200.00,
       updated_at       = NOW()
 WHERE SERIAL_CHASIS = 'CAT0950GLAXX00598'
   AND deleted_at IS NOT NULL;

-- 2) Retirar la ficha de auxiliar.
--    Soft delete (deleted_at), no DELETE: es reversible y deja rastro de que
--    ese registro existió, igual que hace la app. NO se copia
--    DETALLE_UBICACION_ACTUAL = 'TIPO MONTACARGAS': es justo el error que se
--    está corrigiendo.
UPDATE equipos_auxiliares
   SET deleted_at = NOW(),
       updated_at = NOW()
 WHERE SERIAL = 'CAT0950GLAXX00598'
   AND deleted_at IS NULL;

COMMIT;

-- 3) Invalidar la caché.
--    OBLIGATORIO: un UPDATE por SQL no dispara los observers de Laravel, así que
--    el dashboard, el mapa tipo/categoría, el historial de documentos y el
--    snapshot offline seguirían mostrando el equipo como auxiliar hasta que
--    venzan sus TTL. CACHE_STORE=database, así que vaciar esta tabla equivale a
--    `php artisan cache:clear`. Se reconstruye sola; NO toca `sessions` (nadie
--    pierde su sesión).
DELETE FROM cache;

-- ── Verificación (correr después; deben salir 1 y 0 filas) ──────────────────
-- SELECT ID_EQUIPO, id_tipo_equipo, MARCA, MODELO, SERIAL_CHASIS, deleted_at
--   FROM equipos WHERE SERIAL_CHASIS = 'CAT0950GLAXX00598' AND deleted_at IS NULL;
-- SELECT ID_AUXILIAR, TIPO, SERIAL, deleted_at
--   FROM equipos_auxiliares WHERE SERIAL = 'CAT0950GLAXX00598' AND deleted_at IS NULL;
