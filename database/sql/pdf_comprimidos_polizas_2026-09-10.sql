-- Polizas comprimidas (7). Cambia el enlace de cada poliza al PDF comprimido
-- ya subido a Drive. Se busca por el ID de Drive VIEJO: si alguien reemplazo esa
-- poliza despues, esa fila no se toca.
START TRANSACTION;

-- LJRL13373F2004828  JAC HFC9340DPB  (1.95 MB -> 0.20 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/1WlVHU8V93sDotQXaYOb0DRt9vAafKe6A?v=1789064339', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1XFjafkSRCNqKiXwFg8LwnROkrvGhTcE4';

-- VCEL120FC00072153  VOLVO L120E  (1.78 MB -> 0.57 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/12VL84UVCk5u7TXtj-dbfFUXP5xZLVVYn?v=1789064345', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1tMzUJPNmkRzwvJ9wr4Pc1BlRgs-VUGfI';

-- T0410JX149770  JOHN DEERE 410J  (1.78 MB -> 0.57 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/15KLo9a0_0PpgCCNHwPTByATcqQPImZ_S?v=1789064355', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '19vYH1PWyY8HxOy1XBg2e7FBtr6EVgyG1';

-- T0410JX140161  JOHN DEERE 410J  (1.78 MB -> 0.57 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/1j96ZuQT481gs0fHlvGTv_Yt4V3M0cmuX?v=1789064384', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1wtWTPfkFyHER22TS6kCE8pYW3CRZwEF-';

-- LC0410601  HYUNDAI HL7607A  (1.78 MB -> 0.57 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/1WflHEd9N9a0_7kkpnIZtQkpeW0P6URMW?v=1789064400', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1yAldkDtYRyyZ2ZhijitUIUXyQ_vx5u0D';

-- MHFBU8FS5K0035032  TOYOTA FORTUNER  (1.01 MB -> 0.29 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/1XsqCPXiRzVxvN09WadWFPIDrJ_aciy7Z?v=1789064490', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1rclrtRJkumlHQF7gmFIjLajKfjaIb241';

-- 8YTWF3H66EGA04309  FORD F-350 4X4  (0.98 MB -> 0.28 MB)
UPDATE documentacion SET LINK_POLIZA_SEGURO = '/storage/google/1TffAmoj6EdtNtl_2sKHtqICxXTqh_R-v?v=1789064497', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) = '1Gd9Ob1uP5iZQW9mVCnXzsYtB_8UaWQur';

-- Debe dar 7: las 7 polizas ya apuntan al PDF comprimido.
SELECT COUNT(*) AS polizas_actualizadas FROM documentacion
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_POLIZA_SEGURO, '/storage/google/', -1), '?', 1) IN ('1WlVHU8V93sDotQXaYOb0DRt9vAafKe6A','12VL84UVCk5u7TXtj-dbfFUXP5xZLVVYn','15KLo9a0_0PpgCCNHwPTByATcqQPImZ_S','1j96ZuQT481gs0fHlvGTv_Yt4V3M0cmuX','1WflHEd9N9a0_7kkpnIZtQkpeW0P6URMW','1XsqCPXiRzVxvN09WadWFPIDrJ_aciy7Z','1TffAmoj6EdtNtl_2sKHtqICxXTqh_R-v');

COMMIT;

-- Que el menu y el historial de documentos muestren ya los enlaces nuevos
-- (se vuelven a armar solos en la siguiente visita).
DELETE FROM cache WHERE `key` LIKE '%dashboard_user_data_%' OR `key` LIKE '%historial_docs_%';
