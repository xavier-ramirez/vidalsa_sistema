-- ═══════════════════════════════════════════════════════════════════════════
-- Setup de datos de muestra para /admin/almacen y módulos derivados.
-- Compatible con el schema VIDALSA en producción (migrations hasta 2026-05-20).
--
-- VERSIÓN CORREGIDA (la anterior usaba `producto_inventario` singular,
-- `UNIDAD_MEDIDA`, `STOCK_MINIMO`, `FECHA_ACTUALIZACION` — todas referencias
-- que NO existen en el schema actual. Tabla real: `productos_inventario`,
-- columnas: `UM`, `CANTIDAD_MINIMA`, timestamps `created_at`/`updated_at`).
--
-- Tablas tocadas:
--   - almacenes              (2 filas: 1 GENERAL + 1 PROYECTO)
--   - productos_inventario   (30 productos de catálogo)
--   - almacen_stock          (60 filas: 30 productos × 2 almacenes)
--
-- IMPORTANTE: si tu BD ya tiene almacenes con CODIGO 'ALM-CEN' o 'ALM-PRY',
-- los INSERT de la sección 1 fallarán por UNIQUE. Ajusta los CODIGO o elimina
-- esa sección si los almacenes ya existen.
-- ═══════════════════════════════════════════════════════════════════════════

START TRANSACTION;
SET FOREIGN_KEY_CHECKS=0;

-- ── 1) ALMACENES ─────────────────────────────────────────────────────────
INSERT INTO almacenes (CODIGO, NOMBRE, TIPO, UBICACION, ESTATUS, created_at, updated_at) VALUES
('ALM-CEN', 'ALMACÉN CENTRAL',     'GENERAL',  'CARACAS - SEDE PRINCIPAL', 'ACTIVO', NOW(), NOW()),
('ALM-PRY', 'ALMACÉN PROYECTO X',  'PROYECTO', 'OBRA X',                   'ACTIVO', NOW(), NOW());

-- Resolvemos los IDs de los almacenes recién creados por CODIGO (no se asume valor fijo).
SET @id_alm_central  := (SELECT ID_ALMACEN FROM almacenes WHERE CODIGO='ALM-CEN' LIMIT 1);
SET @id_alm_proyecto := (SELECT ID_ALMACEN FROM almacenes WHERE CODIGO='ALM-PRY' LIMIT 1);

-- ── 2) PRODUCTOS (30) ────────────────────────────────────────────────────
INSERT INTO productos_inventario (CODIGO, NOMBRE, UM, CATEGORIA, ESTATUS, created_at, updated_at) VALUES
('000001', 'GUANTE DE LIMPIEZA TALLA XL',                 'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000002', 'GUANTE DE LIMPIEZA TALLA L',                  'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000003', 'PAÑO DE MICROFIBRA',                          'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000004', 'ESPONJA MULTIUSOS',                           'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000005', 'ESPONJA METÁLICA',                            'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000006', 'PORTA SERVILLETAS METÁLICO',                  'UND', 'OFICINA',               'ACTIVO', NOW(), NOW()),
('000007', 'LANILLA AMARILLA',                            'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000008', 'ESCOBA CERDAS SUAVES',                        'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000009', 'LIMPIADOR DE SUPERFICIES DESINFECTANTE',      'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000010', 'MOPA DE COLETO',                              'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000011', 'VASO CÓNICO. PAQUETE 150 U',                  'UND', 'OFICINA',               'ACTIVO', NOW(), NOW()),
('000012', 'PAPEL JUMBO 9"',                              'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000013', 'PAPELERA METALICA DE MALLA',                  'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000014', 'BOLSA DE PAPELERA 30L',                       'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000015', 'TOALLA DE MANOS INTERCALADA',                 'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000016', 'SERVILLETAS TIPO Z PEQUEÑA',                  'UND', 'OFICINA',               'ACTIVO', NOW(), NOW()),
('000017', 'CABO DE MADERA',                              'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000018', 'TERMO 44L',                                   'UND', 'CONSTRUCCION',          'ACTIVO', NOW(), NOW()),
('000019', 'MACHETE MANGO DE GOMA 22"',                   'UND', 'CONSTRUCCION',          'ACTIVO', NOW(), NOW()),
('000020', 'MACHETE MANGO DE MADERA 22"',                 'UND', 'CONSTRUCCION',          'ACTIVO', NOW(), NOW()),
('000021', 'MANGUERA DE JARDIN',                          'UND', 'CONSTRUCCION',          'ACTIVO', NOW(), NOW()),
('000022', 'RODILLO',                                     'UND', 'PINTURA/REVESTIMIENTO', 'ACTIVO', NOW(), NOW()),
('000023', 'CINTA DE SEGURIDAD AMARILLA',                 'UND', 'EPP',                   'ACTIVO', NOW(), NOW()),
('000024', 'CINTA DE SEGURIDAD ROJA',                     'UND', 'EPP',                   'ACTIVO', NOW(), NOW()),
('000025', 'RASTRILLO METÁLICO 14 DIENTES',               'UND', 'LIMPIEZA',              'ACTIVO', NOW(), NOW()),
('000026', 'JUEGO DE MANGUERA PARA EQUIPO DE OXICORTE',   'UND', 'CORTE Y SOLDADURA',     'ACTIVO', NOW(), NOW()),
('000027', 'AVISO BAÑO DE DAMAS',                         'UND', 'SEÑALIZACION',          'ACTIVO', NOW(), NOW()),
('000028', 'AVISO USO OBLIGATORIO PASAMANOS',             'UND', 'SEÑALIZACION',          'ACTIVO', NOW(), NOW()),
('000029', 'PANTALLA FACIAL CON BORDE METÁLICO',          'UND', 'EPP',                   'ACTIVO', NOW(), NOW()),
('000030', 'PANTALLA FACIAL',                             'UND', 'EPP',                   'ACTIVO', NOW(), NOW());

-- ── 3) STOCKS — ALMACÉN GENERAL (con cantidades reales) ──────────────────
-- Patrón INSERT ... SELECT FROM (UNION ALL) JOIN productos_inventario por CODIGO:
-- así el SQL no depende de IDs auto-generados; resuelve ID_PRODUCTO en runtime.
INSERT INTO almacen_stock (ID_ALMACEN, ID_PRODUCTO, CANTIDAD, CANTIDAD_MINIMA, created_at, updated_at)
SELECT @id_alm_central, p.ID_PRODUCTO, t.CANTIDAD, 0, NOW(), NOW()
FROM productos_inventario p
JOIN (
    SELECT '000001' AS CODIGO, 51.000  AS CANTIDAD UNION ALL
    SELECT '000002', 106.000 UNION ALL
    SELECT '000003',  66.000 UNION ALL
    SELECT '000004', 119.000 UNION ALL
    SELECT '000005',  54.000 UNION ALL
    SELECT '000006',  48.000 UNION ALL
    SELECT '000007', 122.000 UNION ALL
    SELECT '000008', 140.000 UNION ALL
    SELECT '000009',  76.000 UNION ALL
    SELECT '000010', 139.000 UNION ALL
    SELECT '000011',  85.000 UNION ALL
    SELECT '000012',  56.000 UNION ALL
    SELECT '000013', 129.000 UNION ALL
    SELECT '000014',  71.000 UNION ALL
    SELECT '000015', 150.000 UNION ALL
    SELECT '000016',  58.000 UNION ALL
    SELECT '000017',  99.000 UNION ALL
    SELECT '000018', 133.000 UNION ALL
    SELECT '000019',  25.000 UNION ALL
    SELECT '000020',  21.000 UNION ALL
    SELECT '000021', 127.000 UNION ALL
    SELECT '000022',  92.000 UNION ALL
    SELECT '000023',  25.000 UNION ALL
    SELECT '000024', 123.000 UNION ALL
    SELECT '000025', 110.000 UNION ALL
    SELECT '000026', 150.000 UNION ALL
    SELECT '000027', 106.000 UNION ALL
    SELECT '000028', 104.000 UNION ALL
    SELECT '000029',  55.000 UNION ALL
    SELECT '000030',  72.000
) t ON p.CODIGO = t.CODIGO;

-- ── 4) STOCKS — ALMACÉN PROYECTO (todos en 0, listos para recibir traspasos) ──
INSERT INTO almacen_stock (ID_ALMACEN, ID_PRODUCTO, CANTIDAD, CANTIDAD_MINIMA, created_at, updated_at)
SELECT @id_alm_proyecto, ID_PRODUCTO, 0, 0, NOW(), NOW()
FROM productos_inventario
WHERE CODIGO BETWEEN '000001' AND '000030';

SET FOREIGN_KEY_CHECKS=1;
COMMIT;

-- ── Verificación ─────────────────────────────────────────────────────────
-- SELECT ID_ALMACEN, NOMBRE, TIPO, ESTATUS FROM almacenes;
-- SELECT COUNT(*) AS productos FROM productos_inventario;
-- SELECT a.NOMBRE, COUNT(s.ID_PRODUCTO) AS items, SUM(s.CANTIDAD) AS total
-- FROM almacen_stock s JOIN almacenes a ON a.ID_ALMACEN = s.ID_ALMACEN GROUP BY a.NOMBRE;
