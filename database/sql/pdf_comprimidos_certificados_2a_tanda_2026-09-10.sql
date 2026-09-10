-- Certificados de equipos, 2a tanda: 9 archivos comprimidos por la forma SEGURA (se compacta
-- el PDF y se recomprimen las fotos sin bajar resolucion; el texto no se toca).
-- Se busca por el ID de Drive VIEJO: si alguien reemplazo ese documento despues, no se toca.
START TRANSACTION;

-- CLW009LHESN001941  LOVOL FL976K  (3.86 MB -> 2.85 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1e5Ulgj_MgNuG3BsCFz1ziItHMnyYy8MN?v=1789073657', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1CMzP1vtqs9TPuskysNr_rS-JoL3BhvHz';

-- CHSD22AAAS1028313  SHANTUI SD22  (3.31 MB -> 2.50 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/18c_z2awdg06h4swWWTKg3wntiv1sH4Bs?v=1789073671', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1YMwaJ5B0JQqgjIk1sSxpP3A-3mm9NLsf';

-- CAT00330TKEL20242  CAT 330D  (3.27 MB -> 3.02 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1F6GB7fV9YflqjQfqKbNPfer0CR5izQh5?v=1789073681', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1oTRygTkDZpDfQ1Zw3g9uuzsvov5Ykhk_';

-- CHSD22AAAS1028452  SHANTUI SD22  (3.07 MB -> 2.27 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1mr--9ssUfzRrXZK0bIPlZPAGiK7jw-hj?v=1789073700', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1KssuJUWm-Q1hIhNs7tw72TQMvGbb3Hxe';

-- FTC003RNHSZ555560  LOVOL FR420F  (2.92 MB -> 2.64 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1INQ5bSmoHPErwwSjulN4RMbcpdsSj7cK?v=1789073710', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1KA7FYU-hel8hOY2oaoDmoI1ZZAE0Tsed';

-- CAT0012KEJJA03168  CAT 12K  (2.52 MB -> 2.28 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1HV8PdIER15y_AsW00UX0zesXx8HxwST6?v=1789073720', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1ebAxzUftS_JXtmaXpaTaMTrc_K0LyJmg';

-- CLW009LFCSW001682  LOVOL FL956H  (2.12 MB -> 1.95 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/18HQL7N611e5yfc9bxyhcO6LKH4v1WWkL?v=1789073728', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1mjWbEnK0993ZRCET7LRXnmqb7NuE5fXQ';

-- CLW009LDASZ000225  SINOTRUK FLB468-II  (1.28 MB -> 0.77 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1Z7Sw_RJBEyPsnbuir5lZq1B2dSSYPAf8?v=1789073735', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1p4GGlfKzYxjda81lXvb6Hp5TmwmSVwNe';

-- CLW009LDCSZ000229  SINOTRUK FLB468-II  (1.25 MB -> 0.76 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/13q_Sg9kbL3oVj9AEGLnIfr9q9XIb7lwi?v=1789073741', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1SkcSeZh5eB95_UKNPIQp3DnQAVBPJ4-_';

-- Debe dar 9.
SELECT COUNT(*) AS actualizados FROM documentacion WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) IN ('1e5Ulgj_MgNuG3BsCFz1ziItHMnyYy8MN','18c_z2awdg06h4swWWTKg3wntiv1sH4Bs','1F6GB7fV9YflqjQfqKbNPfer0CR5izQh5','1mr--9ssUfzRrXZK0bIPlZPAGiK7jw-hj','1INQ5bSmoHPErwwSjulN4RMbcpdsSj7cK','1HV8PdIER15y_AsW00UX0zesXx8HxwST6','18HQL7N611e5yfc9bxyhcO6LKH4v1WWkL','1Z7Sw_RJBEyPsnbuir5lZq1B2dSSYPAf8','13q_Sg9kbL3oVj9AEGLnIfr9q9XIb7lwi');

COMMIT;

-- Que el menu y el historial de documentos muestren ya los enlaces nuevos.
DELETE FROM cache WHERE `key` LIKE '%dashboard_user_data_%' OR `key` LIKE '%historial_docs_%';
