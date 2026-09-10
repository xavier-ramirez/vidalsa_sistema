-- Certificado comprimidas: 41 archivos. Cambia el enlace al PDF comprimido ya
-- subido a Drive. Se busca por el ID de Drive VIEJO: si alguien reemplazo ese
-- documento despues, esa fila no se toca.
START TRANSACTION;

-- CHSP45YAKS1003161  (4.46 MB -> 1.77 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1yZR23cDke8ZXaEMjcyCn2VYjaW3Ovb_L?v=1789071110', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '13L7_Gv3Zk54z_wpPweQbjMSAClQCSZQh';

-- CHSP25YAHS1055078  (4.42 MB -> 1.76 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1sLelNr3PDLeq5dxtPolUh0LR-OEgjN01?v=1789071136', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1cKoY-_L3ScGQvZxKsGL-YMVwvXqM2GXc';

-- CHSP25YAJS1055056  (4.36 MB -> 1.74 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1FaSSeCXZoywWyMqPJsTT9lEvMH2hp8Nf?v=1789071173', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1XR8rUF7JPA4zyZCwoTZWwXmIoroB0jPT';

-- 61A113  (4.33 MB -> 1.71 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1XAiJW-mk7ULFpW9mszRLZTT8s87jV55c?v=1789071190', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1IiVADWj8A_K1boBf1tLkimcLReEP01KB';

-- 77V14577  (4.26 MB -> 1.68 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/10CRHCXQ73eqHb5kWBSPkKtEzPBV68UKc?v=1789071202', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1sP6xa2WhIFc4OuPX1ZVr3Xe--m_0Zgur';

-- CHSP45YAAS1003165  (4.22 MB -> 1.68 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1hBttxf89SsWe7WYvnV8wwZN2hgM9ot_2?v=1789071213', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1EO56GFmdu9iFViJh2cpr-Gnt31glwLyR';

-- CLW009LDLSW002267  (3.76 MB -> 3.01 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1DSD3fDtnnPoE0-Av62Ir8fDAnob3BuNQ?v=1789071236', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1PsM6M_fiVtsI7Lz4zFttoPP2rDi07_OB';

-- CLW009LDLSW002270  (3.59 MB -> 2.85 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1nO1k3xjOspin0Q942WAl_32GN5IAunAF?v=1789071279', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1UM3urdkO0I4FHgHlFtX4eokPr8cDAsEl';

-- L1C29HRG0S0000017  (3.12 MB -> 1.24 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/16LDXXJZzKKLnXtLzDya9gEwMK9cJU-aI?v=1789071290', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1dg0bicBb1xugNbJeBERVWP1xXKrAIJne';

-- L1C29HRG9S0000016  (3.11 MB -> 1.24 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1xC9NXucDKyK7SOqyKPqaTAJx9PGUJQee?v=1789071303', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1cEw5rjrfViz0B8GtSLIj5_dojgk9AeK_';

-- L1C29HRG5S0000031  (3.11 MB -> 1.23 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1xZb9Rb4EqDGwGMZlUB3cnLbFQ32r2Yhb?v=1789071311', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1mTpel_lpKzl1-lHgStYaAjBRMX8Kf1E_';

-- L1C29HRG3S0000030  (3.11 MB -> 1.24 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1_i9z1iL0RsCk3v3JndAp378Axg-qHQXi?v=1789071322', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1WtD8kN-dMr3Emi9dio2-r5MlZtC1lXRk';

-- L1C29HRG2S0000021  (3.10 MB -> 1.23 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1HOTb7YzNrbK1Ax9dEEkaWhI08eihdEhv?v=1789071349', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1w6DRiGcTzAfAHORkmrS-TOljHjYChF4K';

-- L1C29HRG2S0000018  (3.10 MB -> 1.23 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1CJDpyvv_VlduTklsfYkihqMvSBVrQeRh?v=1789071382', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '12vGQcXYtvN_t6b8BaDRAE3-ZZOABwndw';

-- CLW009LDESZ000228  (3.09 MB -> 1.19 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1kL9HWGVgbnDUkYmUvsBZuv4A9m-7QNIv?v=1789071397', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1EyV0uJMUMQsdAP49xA085qveRzt6BmrX';

-- CLW009LHKSN001959  (3.06 MB -> 2.44 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1WKQLASYvOiBigzqPFoDXBZE1-j_wLj_X?v=1789071410', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1tkPk_6f9k7hSlo4Cj4wvGIbTn2NP6W_Y';

-- 96U09513  (3.00 MB -> 1.21 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1XM6y_bnS2pkQOs6t-1CjLl-iuMdMr9-N?v=1789071423', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1BOZsmsuC8OldS0i0LM2VUN41YvN7W8aI';

-- CAT0012KCJJA03169  (2.99 MB -> 1.21 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1cDxr42CZSfY4wVefcKdrXYYGA0oDf4sV?v=1789071434', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1-vfbzIvZA87gPssrGAB3SfriLLWScRzr';

-- 2KZ03939  (2.91 MB -> 1.17 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1i0hHUd9UkvSp849lGOi_kUIAuiJyK2pl?v=1789071445', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1JkRtPGjhfIjrUHWmDbkflTn5c055YPY6';

-- 5FM01814  (2.87 MB -> 1.16 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1As5OQ4cIRjhCnEGidVkrcAt1_ZEjccez?v=1789071456', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '18QFnOxdWUnEub23h3uhVV_Fu7OdMhYOW';

-- CLW009LDCSZ000232  (2.86 MB -> 1.14 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1_Y5VdZKlZ_PXXLXR66Xuhft7Zac2t6PI?v=1789071477', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1dab6d9-rjuDdXqyS1UyQkrZS_T3PL4Mj';

-- CLW009LHHSN001940  (2.57 MB -> 0.98 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1V2zLfQNAxpPH-hoSCcjW4zDrNMS8M-96?v=1789071503', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1nVB3SPlLcaWgaJJYlhaTOaF4AR7w2eyu';

-- CLW009LHTSN001960  (2.57 MB -> 0.98 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1uWGl3TEgwGSMAEA2ZQ6RvWccP0T-6NIN?v=1789071530', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1oQUzTmmI5FZ2g9qaIPacHdP4mA4b9Xyr';

-- FTC003RHCSW556889  (2.55 MB -> 1.02 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1E9ryBRH5VXdRZWGVdYv8tiypBpVsCb7l?v=1789071551', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '17eri6PJVLxqxOeeIb-gqHurgEBbHC1qf';

-- CAT0938KJHFW00671  (2.55 MB -> 1.01 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1FJiJoO4qaPg-nCnOGA2TAFSeUV9pcCWy?v=1789071571', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1_HAQSuxXVR7Y9vqS4SgId-GeSwgPFSmV';

-- FTC003RNESZ555561  (2.52 MB -> 1.00 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1pXCh0HtXuWGz25jr8EXLkSYdNHLntXTD?v=1789071593', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1XjavbmgY8n-iXfTbE8qT8ycWY1Se4Sfg';

-- 1800K0120152  (2.48 MB -> 1.00 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/19N_8tC_OE1XvGQLKdbuICAI_yd8DhXeL?v=1789071607', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1f4e5AI_ZbGsO57PPo15OBoEqIGCdKAQS';

-- CLW009LFASW001684  (2.46 MB -> 0.98 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1qR22LAexGg4iiFqEKgw8TENKjJMlgkvK?v=1789071624', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1OGJnRSqZh17H3CMvadfhr_Us7qw4TrFN';

-- CLW009LDHSZ000230  (2.26 MB -> 0.93 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1-AeKlnUxFnlupthpDY-x4YeGLHotwSRr?v=1789071634', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1TWPcy_23s-oUq9617yFUEHmSsfhJQIX5';

-- CLW009LDESZ000231  (2.25 MB -> 0.92 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1IzKmmrZ9YXbAcs4VSRoD4pM1tnWp_Vpz?v=1789071645', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1IEcKXG_P2czM4inYvNT8mf8q_v3Tt0oE';

-- CAT0336DTZCT00679  (2.25 MB -> 0.89 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/19BQLwTb6g6u2bstt1IAe2snttVl1F2B5?v=1789071654', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1q2DvWSd95JKT1EKV5rn9l4fZZIdEI9Re';

-- CAT0950GLAXX00598  (2.10 MB -> 0.87 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/11IYQPbxUsq1-WYehauls51bNRcVmfQXv?v=1789071664', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1jlc58dwScszB0wKZkYN4JuJOg8jQv-Kt';

-- FTC003RHVSS556867  (1.94 MB -> 0.78 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/114MNoCD4AObW6qknzE-ZqrbCMs0izSNd?v=1789071756', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1QtMWcdxkbNmxPe1t4wWh9j-plnvelSq0';

-- CAT00320JZBN20779  (1.92 MB -> 0.79 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1HCwzJpI9xu8uUSwRQ3CSnkK3QpWHC02Y?v=1789071773', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1JFMua0H-Dg3QiwZlgT5YdjxotGe-IZZV';

-- CAT00320AZBN20866  (1.88 MB -> 0.76 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/125HKtGbwK9lBzZCaObLgryzww6l7NlYx?v=1789071787', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1eCiQ6VMOsEVLvEV0TYS8SzzHkYd8qD-1';

-- FTC003RHTSW556935  (1.85 MB -> 0.75 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1zx8KXSZ7ZPhJHlKDZsfu7eMVd0V4fDdN?v=1789071802', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1GFQkF-oJyhmcDyO-YledDnP_ZG5NDxdq';

-- CAT0938KCHFW00672  (1.82 MB -> 0.74 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/15LSXnXRLIAt50iRbmxZ9_SvBr1_UwRqk?v=1789071813', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1AGZmzGo_3lUMKymxi8NFcR-6TLuMf5B6';

-- CLW009LFVSW001697  (1.82 MB -> 0.74 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/14pm8CzzgMLiG_Eu635J7eWA1DnZuYR81?v=1789071831', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1JsW-kPxkyyowYPzRcixtbGumf0WpkZTg';

-- CLW009LFCSW001696  (1.81 MB -> 0.74 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1G6qLrA8YKQDmjaPhuYHjTHBiIc1sUZck?v=1789071851', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1cJuivfytt4nkHefLsWe0T0ojFcmjrah8';

-- CLW009LFASW001698  (1.79 MB -> 0.73 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1pFoTWMxblGB6dUFuW0cwb_V4V5s9mim2?v=1789071862', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1IjSbc-foGKL7-dATNuBLGCN0ZXs9I0ET';

-- CLW009LFVSW001683  (1.77 MB -> 0.73 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL = '/storage/google/1LMLudbeOroOB0iH6K4hCwQZorxTGr8xA?v=1789071871', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) = '1AjXtQlY42DLeTiRb31be8CH02h8p2UbM';

-- Debe dar 41.
SELECT COUNT(*) AS actualizados FROM documentacion WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL, '/storage/google/', -1), '?', 1) IN ('1yZR23cDke8ZXaEMjcyCn2VYjaW3Ovb_L','1sLelNr3PDLeq5dxtPolUh0LR-OEgjN01','1FaSSeCXZoywWyMqPJsTT9lEvMH2hp8Nf','1XAiJW-mk7ULFpW9mszRLZTT8s87jV55c','10CRHCXQ73eqHb5kWBSPkKtEzPBV68UKc','1hBttxf89SsWe7WYvnV8wwZN2hgM9ot_2','1DSD3fDtnnPoE0-Av62Ir8fDAnob3BuNQ','1nO1k3xjOspin0Q942WAl_32GN5IAunAF','16LDXXJZzKKLnXtLzDya9gEwMK9cJU-aI','1xC9NXucDKyK7SOqyKPqaTAJx9PGUJQee','1xZb9Rb4EqDGwGMZlUB3cnLbFQ32r2Yhb','1_i9z1iL0RsCk3v3JndAp378Axg-qHQXi','1HOTb7YzNrbK1Ax9dEEkaWhI08eihdEhv','1CJDpyvv_VlduTklsfYkihqMvSBVrQeRh','1kL9HWGVgbnDUkYmUvsBZuv4A9m-7QNIv','1WKQLASYvOiBigzqPFoDXBZE1-j_wLj_X','1XM6y_bnS2pkQOs6t-1CjLl-iuMdMr9-N','1cDxr42CZSfY4wVefcKdrXYYGA0oDf4sV','1i0hHUd9UkvSp849lGOi_kUIAuiJyK2pl','1As5OQ4cIRjhCnEGidVkrcAt1_ZEjccez','1_Y5VdZKlZ_PXXLXR66Xuhft7Zac2t6PI','1V2zLfQNAxpPH-hoSCcjW4zDrNMS8M-96','1uWGl3TEgwGSMAEA2ZQ6RvWccP0T-6NIN','1E9ryBRH5VXdRZWGVdYv8tiypBpVsCb7l','1FJiJoO4qaPg-nCnOGA2TAFSeUV9pcCWy','1pXCh0HtXuWGz25jr8EXLkSYdNHLntXTD','19N_8tC_OE1XvGQLKdbuICAI_yd8DhXeL','1qR22LAexGg4iiFqEKgw8TENKjJMlgkvK','1-AeKlnUxFnlupthpDY-x4YeGLHotwSRr','1IzKmmrZ9YXbAcs4VSRoD4pM1tnWp_Vpz','19BQLwTb6g6u2bstt1IAe2snttVl1F2B5','11IYQPbxUsq1-WYehauls51bNRcVmfQXv','114MNoCD4AObW6qknzE-ZqrbCMs0izSNd','1HCwzJpI9xu8uUSwRQ3CSnkK3QpWHC02Y','125HKtGbwK9lBzZCaObLgryzww6l7NlYx','1zx8KXSZ7ZPhJHlKDZsfu7eMVd0V4fDdN','15LSXnXRLIAt50iRbmxZ9_SvBr1_UwRqk','14pm8CzzgMLiG_Eu635J7eWA1DnZuYR81','1G6qLrA8YKQDmjaPhuYHjTHBiIc1sUZck','1pFoTWMxblGB6dUFuW0cwb_V4V5s9mim2','1LMLudbeOroOB0iH6K4hCwQZorxTGr8xA');

COMMIT;

-- Que el menu y el historial de documentos muestren ya los enlaces nuevos.
DELETE FROM cache WHERE `key` LIKE '%dashboard_user_data_%' OR `key` LIKE '%historial_docs_%';
