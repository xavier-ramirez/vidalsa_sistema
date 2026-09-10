-- ============================================================================
--  Inventario: TODOS los guantes y TODAS las botas con unidad de medida "PAR"
-- ============================================================================
--
--  QUÉ HACE
--  Pone UM = 'PAR' en todos los productos del inventario cuyo nombre lleva
--  "GUANTE" (guantes de carnaza, neopreno, anticorte, limpieza, quirúrgicos,
--  estériles, PVC...). Los que decían "UNIDAD" o "PARES" quedan como "PAR".
--  En la base de desarrollo (copia del servidor del 10-09-2026) son 13
--  productos y cambian 9: 8 que decían UNIDAD y 1 que decía PARES. Los otros
--  4 ya decían PAR.
--  BOTAS: todo producto cuyo nombre empieza por "BOTA " (seguridad obrero,
--  1/2 caña, supervisor, PVC). En desarrollo son 43 y cambian 29 que decían
--  UNIDAD; las otras 14 ya decían PAR. Se busca 'BOTA %' (empieza por BOTA y
--  un espacio) y no '%BOTA%', para no tocar palabras que solo la contengan.
--  CUALQUIER PRODUCTO con la unidad escrita como variante de PAR ("PARES",
--  "PARESS", "Par", "par ") también queda en "PAR", sea guante o no.
--  BINARY en la comparación: la tabla no distingue mayúsculas, y sin él un
--  "par" en minúsculas pasaría por igual a "PAR" y no se corregiría.
--
--  OJO: SOLO CAMBIA LA ETIQUETA, NO LAS CANTIDADES
--  El sistema no convierte unidades (la conversión es manual, por decisión).
--  Si un guante tenía 20 en stock contados como UNIDAD, seguirá con 20 y ahora
--  se leerán como 20 PARES. Si alguno se había contado por guante suelto, hay
--  que ajustar su cantidad aparte con una auditoría.
--  Revisar en especial "GUANTE QUIRÚRGICO TALLA L. CAJA 100 U": por el nombre
--  se maneja por CAJA. Si no debe cambiar, descomentar la línea marcada en el
--  PASO 2.
--
--  NO HACE FALTA TOCAR NADA MÁS
--  La unidad solo vive en productos_inventario.UM: movimientos, kardex, notas
--  de entrega y exportes la leen del producto, así que el cambio se ve en
--  todas partes. Se actualiza también updated_at: con eso la copia sin conexión
--  de los teléfonos (dominio "almacen" de OfflineVersion) se entera sola en
--  menos de 5 minutos.
--
--  Es IDEMPOTENTE: se puede correr varias veces sin efecto adicional.
--
--  CÓMO CORRERLO
--    1) PASO 1 para ver qué hay ANTES (y guardar ese resultado: es la reversa).
--    2) PASO 2 (la transacción).
--    3) PASO 3 para confirmar.
-- ============================================================================


-- ── PASO 1: qué hay antes ───────────────────────────────────────────────────
SELECT ID_PRODUCTO, CODIGO, NOMBRE, UM, CATEGORIA
FROM productos_inventario
WHERE (NOMBRE LIKE '%GUANTE%' OR NOMBRE LIKE 'BOTA %' OR TRIM(UM) LIKE 'PAR%')
ORDER BY UM, NOMBRE;


-- ── PASO 2: el cambio ───────────────────────────────────────────────────────
START TRANSACTION;

UPDATE productos_inventario
SET UM = 'PAR',
    updated_at = NOW()
WHERE (NOMBRE LIKE '%GUANTE%' OR NOMBRE LIKE 'BOTA %' OR TRIM(UM) LIKE 'PAR%')
  -- AND NOMBRE NOT LIKE '%CAJA%'      -- <- descomentar para dejar la caja de 100 como está
  AND BINARY UM <> 'PAR';

-- Debe decir 0: ningún guante ni bota sin PAR.
SELECT COUNT(*) AS sin_par
FROM productos_inventario
WHERE (NOMBRE LIKE '%GUANTE%' OR NOMBRE LIKE 'BOTA %' OR TRIM(UM) LIKE 'PAR%') AND BINARY UM <> 'PAR';

COMMIT;


-- ── PASO 3: confirmar ───────────────────────────────────────────────────────
SELECT UM, COUNT(*) AS productos
FROM productos_inventario
WHERE (NOMBRE LIKE '%GUANTE%' OR NOMBRE LIKE 'BOTA %' OR TRIM(UM) LIKE 'PAR%')
GROUP BY UM;


-- ── REVERSA (valores de la base de desarrollo del 10-09-2026) ───────────────
-- Solo si hubiera que deshacerlo. En el servidor, usar lo que dio el PASO 1.
--
-- UPDATE productos_inventario SET UM = 'PARES',  updated_at = NOW() WHERE CODIGO = '000046';
-- UPDATE productos_inventario SET UM = 'UNIDAD', updated_at = NOW()
--  WHERE CODIGO IN ('000048','000049','000105','000434','000220','000219','000221','000038');
-- Botas que decían UNIDAD: 000122 a 000140, 000526, 000580 a 000582, 000584 a 000587,
-- 000589 y 000798.
