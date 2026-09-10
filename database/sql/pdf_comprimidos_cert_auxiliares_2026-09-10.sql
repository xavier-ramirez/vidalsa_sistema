-- Certificado de auxiliar comprimidas: 18 archivos. Cambia el enlace al PDF comprimido ya
-- subido a Drive. Se busca por el ID de Drive VIEJO: si alguien reemplazo ese
-- documento despues, esa fila no se toca.
START TRANSACTION;

-- 2025070009  (1.94 MB -> 0.62 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1w-19UFxnaXBwuF3TbeDfCDdF9uFwtFK5?v=1789068738', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1WYOvYvQPG3JKAOOxibA6S6EP9vFhTE25';

-- 2025070006  (1.89 MB -> 0.58 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1pzlQWPRaUyFAjP910vgASiwlSiOAtjUc?v=1789068751', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1NgzMt9ZJiQW3qzgfetUo8-rTKL5aj2FL';

-- 2025070021  (1.89 MB -> 0.61 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1WdecRQfGPcZyT09k1R5ncnSTNN_7DaL0?v=1789068758', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1f1UrDqHG9vkLbPIQn5pBPJLSc85niogT';

-- 2025070004  (1.88 MB -> 0.62 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1TPj2yfBbNU5hcWcg0Vv45ezm4uQx-rng?v=1789068765', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '18qAtSLrDhNya370rHFP0xPlgjk3Bs8T3';

-- 2025070007  (1.88 MB -> 0.60 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/19UcaQtDAP3liuF4KR_zIbyHxNQUnRNXz?v=1789068773', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1MYprleOIwuvuYPO_bUULKA7qYe1zSBPj';

-- 2025070028  (1.86 MB -> 0.59 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1VZOyCNV5YNtsopSBKWGBIlRgz9QleZLU?v=1789068781', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1d8y3hE4vwG3IFMgn9iCunWm1mbKX8oH1';

-- 2025070020  (1.83 MB -> 0.59 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1Ep_RL_xkL7HAJc-udg3XHwjAVxLSL4DV?v=1789068788', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '14NLI4jDnaN8WnA_ZHdF_XhRQzaqLvLYH';

-- 2025070024  (1.82 MB -> 0.59 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1WeUINyHRqvsF6wvbuaAwIl5HDDIL2YTo?v=1789068796', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1f5VsB8mmJXuW_kqt51tDemcubuNW_qFv';

-- 2025070003  (1.80 MB -> 0.58 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1Av3M81UFF_AGCXgZ_d1S_fp25FNyEMJF?v=1789068807', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '12E2b8EEN_BLl5U3QrgA_ZQtSaYV7w9sI';

-- 2025070019  (1.79 MB -> 0.57 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1LMAgT5noGrrqrvhOerLt2vQceSZueLsh?v=1789068819', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1YlYp5AF5YCSrT3d3ApJfMr5dt-Votxri';

-- 2025070015  (1.78 MB -> 0.57 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1EhdcYdz5rqNgBopbSUWEy35NRVlOuGZo?v=1789068829', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '11HITikY0deMwP6G5sO5TCD4pLYWMjX4l';

-- 2025070001  (1.77 MB -> 0.58 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1lZ4vCo4JGWe4NL81QnjCUsuK_8OlQxAL?v=1789068839', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1h-Wk4-yjps1PYpNd7WvsAV5e0W_xfj6Y';

-- 2025070022  (1.77 MB -> 0.57 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1vGFDyLs89JmU2Ql1IrliukJOAB8LSpL2?v=1789068848', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1FCkKUI6St0sySBMFmDVE5GGVz2V9eQrV';

-- 2025070026  (1.76 MB -> 0.57 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1gbEPlpLtLMOPGyYe_6YcH_OuQFTSiv8V?v=1789068860', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1zAqLG_KIKVSmojLRMEcoygR8IbOEqNew';

-- 2025070010  (1.75 MB -> 0.54 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1Jort23EzPUyMnc1U1N_K5z02vvusAjDN?v=1789068870', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '198qvuQaMKR-1SxKtPr4NyV76v6JpaDHY';

-- 2025070031  (1.74 MB -> 0.55 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1ZQMeifWMULxJQA9Vc_X-wMCA92QLHYLz?v=1789068880', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1hL9zcdhFPelktTR_rAjs3n-RaYma0qaO';

-- 2025070025  (1.72 MB -> 0.56 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1yLFWlkYQGw7OVQW6L6WHWTFzvrmXdn6U?v=1789068888', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1fpYcE6jupcfSf5i20Lql6-1Mevw_T4hC';

-- 2025070005  (1.66 MB -> 0.55 MB)
UPDATE equipos_auxiliares SET LINK_CERTIFICADO = '/storage/google/1kU9A6IumvWSZDhdJY4mdEc0PztRM25Lc?v=1789068899', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) = '1lTi9rDrEvzSRWjIWCxnQsBjyTLJyhOB_';

-- Debe dar 18.
SELECT COUNT(*) AS actualizados FROM equipos_auxiliares WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_CERTIFICADO, '/storage/google/', -1), '?', 1) IN ('1w-19UFxnaXBwuF3TbeDfCDdF9uFwtFK5','1pzlQWPRaUyFAjP910vgASiwlSiOAtjUc','1WdecRQfGPcZyT09k1R5ncnSTNN_7DaL0','1TPj2yfBbNU5hcWcg0Vv45ezm4uQx-rng','19UcaQtDAP3liuF4KR_zIbyHxNQUnRNXz','1VZOyCNV5YNtsopSBKWGBIlRgz9QleZLU','1Ep_RL_xkL7HAJc-udg3XHwjAVxLSL4DV','1WeUINyHRqvsF6wvbuaAwIl5HDDIL2YTo','1Av3M81UFF_AGCXgZ_d1S_fp25FNyEMJF','1LMAgT5noGrrqrvhOerLt2vQceSZueLsh','1EhdcYdz5rqNgBopbSUWEy35NRVlOuGZo','1lZ4vCo4JGWe4NL81QnjCUsuK_8OlQxAL','1vGFDyLs89JmU2Ql1IrliukJOAB8LSpL2','1gbEPlpLtLMOPGyYe_6YcH_OuQFTSiv8V','1Jort23EzPUyMnc1U1N_K5z02vvusAjDN','1ZQMeifWMULxJQA9Vc_X-wMCA92QLHYLz','1yLFWlkYQGw7OVQW6L6WHWTFzvrmXdn6U','1kU9A6IumvWSZDhdJY4mdEc0PztRM25Lc');

COMMIT;

-- Que el menu y el historial de documentos muestren ya los enlaces nuevos.
DELETE FROM cache WHERE `key` LIKE '%dashboard_user_data_%' OR `key` LIKE '%historial_docs_%';
