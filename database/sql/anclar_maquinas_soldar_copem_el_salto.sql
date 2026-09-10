-- =====================================================================
--  ANCLAR LAS MAQUINAS DE SOLDAR A SU CAMION
--  Fuente: "LISTADO DE MAQUINARIAS EQUIPOS", revision 0, 12/08/2026,
--          proyecto "CONSTRUCCION DE 29,6 KM DE TUBERIA DE 12\" DE
--          INYECCION DE AGUA SALADA DESDE COPEM HASTA LOS POZOS DE
--          INYECTORES EL SALTO".
--
--  Son 10 camiones de soldadura con DOS maquinas cada uno = 20 anclajes.
--  Antes de esto, las 20 tenian ID_EQUIPO_HOST en NULL.
--
--  POR QUE NO VA POR ID: los ID_AUXILIAR / ID_EQUIPO de la copia local no
--  tienen por que ser los del servidor. Cada maquina se busca por su
--  SERIAL y cada camion por su PLACA -que es lo que dice la hoja-, asi
--  que el guion hace lo mismo en las dos bases sin tocar numeros a mano.
--
--  OJO: es SQL crudo, no pasa por Eloquent. No dispara los audit log del
--  modelo ni invalida las caches de la aplicacion.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1) UN SERIAL MAL ESCRITO
--    El de la fila 01 esta cargado como 20250700001 -once digitos, un
--    cero de mas-. En la hoja es 2025070001. Sin corregirlo, esa maquina
--    no cruza por serial y se quedaria sin anclar.
--    El WHERE lleva el valor viejo, asi que correrlo dos veces no hace
--    nada la segunda.
-- ---------------------------------------------------------------------
UPDATE equipos_auxiliares
   SET SERIAL = '2025070001'
 WHERE SERIAL = '20250700001'
   AND TIPO   = 'MAQUINA_DE_SOLDAR'
   AND deleted_at IS NULL;

-- ---------------------------------------------------------------------
-- 2) LOS 20 ANCLAJES
--    La lista de abajo es la hoja tal cual: serial de la maquina y placa
--    del camion que la lleva. El JOIN resuelve la placa contra
--    documentacion y exige que el equipo sea un CAMION DE SOLDADURA
--    (tipo 11), para que una placa repetida o mal escrita no ancle la
--    maquina a cualquier cosa.
-- ---------------------------------------------------------------------
UPDATE equipos_auxiliares a
  JOIN (
              SELECT '2025070001' AS serial, 'A54DF0S' AS placa   -- 01
    UNION ALL SELECT '2025070031',           'A54DF0S'
    UNION ALL SELECT '2025070028',           'A53DF4S'            -- 02
    UNION ALL SELECT '2025070025',           'A53DF4S'
    UNION ALL SELECT '2025070015',           'A54DF2S'            -- 03
    UNION ALL SELECT '2025070005',           'A54DF2S'
    UNION ALL SELECT '2025070020',           'A53DF8S'            -- 04
    UNION ALL SELECT '2025070006',           'A53DF8S'
    UNION ALL SELECT '2025070021',           'A51EX5P'            -- 05
    UNION ALL SELECT '2025070022',           'A51EX5P'
    UNION ALL SELECT '2025070024',           'A51EX8P'            -- 06
    UNION ALL SELECT '2025070026',           'A51EX8P'
    UNION ALL SELECT '2025070003',           'A52EX3P'            -- 07
    UNION ALL SELECT '2025070004',           'A52EX3P'
    UNION ALL SELECT '2025070010',           'A52EX0P'            -- 08
    UNION ALL SELECT '2025070009',           'A52EX0P'
    UNION ALL SELECT '2025070007',           'A52EX1P'            -- 09
    UNION ALL SELECT '2025070019',           'A52EX1P'
    UNION ALL SELECT 'NE310876R',            'A16CS0V'            -- 10
    UNION ALL SELECT 'NE350661R',            'A16CS0V'
  ) m ON m.serial = a.SERIAL
  JOIN documentacion d ON d.PLACA = m.placa
  JOIN equipos e ON e.ID_EQUIPO = d.ID_EQUIPO
                AND e.id_tipo_equipo = 11          -- CAMION DE SOLDADURA
                AND e.deleted_at IS NULL
   SET a.ID_EQUIPO_HOST = e.ID_EQUIPO,
       a.updated_at     = NOW()
 WHERE a.TIPO = 'MAQUINA_DE_SOLDAR'
   AND a.deleted_at IS NULL;

COMMIT;

-- =====================================================================
--  COMPROBACION. Tienen que salir las 10 filas, cada una con 2 maquinas.
--  Si alguna sale con 1, es que ese serial no existe en esa base.
-- =====================================================================
SELECT d.PLACA,
       e.ID_EQUIPO,
       e.MARCA,
       COUNT(a.ID_AUXILIAR)          AS maquinas,
       GROUP_CONCAT(a.SERIAL ORDER BY a.SERIAL SEPARATOR ' + ') AS seriales
  FROM equipos e
  JOIN documentacion d       ON d.ID_EQUIPO = e.ID_EQUIPO
  JOIN equipos_auxiliares a  ON a.ID_EQUIPO_HOST = e.ID_EQUIPO
                            AND a.TIPO = 'MAQUINA_DE_SOLDAR'
                            AND a.deleted_at IS NULL
 WHERE e.id_tipo_equipo = 11
   AND e.deleted_at IS NULL
   AND d.PLACA IN ('A54DF0S','A53DF4S','A54DF2S','A53DF8S','A51EX5P',
                   'A51EX8P','A52EX3P','A52EX0P','A52EX1P','A16CS0V')
 GROUP BY d.PLACA, e.ID_EQUIPO, e.MARCA
 ORDER BY d.PLACA;

-- Y las que siguen sueltas, para saber que queda por delante.
SELECT COUNT(*) AS maquinas_sin_camion
  FROM equipos_auxiliares
 WHERE TIPO = 'MAQUINA_DE_SOLDAR'
   AND ID_EQUIPO_HOST IS NULL
   AND deleted_at IS NULL;

-- =====================================================================
--  PARA DESHACERLO (no se ejecuta solo; descomentar si hace falta).
--  Devuelve a NULL SOLO las 20 de esta hoja, no las que ya estaban.
-- =====================================================================
-- UPDATE equipos_auxiliares
--    SET ID_EQUIPO_HOST = NULL, updated_at = NOW()
--  WHERE TIPO = 'MAQUINA_DE_SOLDAR'
--    AND deleted_at IS NULL
--    AND SERIAL IN ('2025070001','2025070031','2025070028','2025070025',
--                   '2025070015','2025070005','2025070020','2025070006',
--                   '2025070021','2025070022','2025070024','2025070026',
--                   '2025070003','2025070004','2025070010','2025070009',
--                   '2025070007','2025070019','NE310876R','NE350661R');
