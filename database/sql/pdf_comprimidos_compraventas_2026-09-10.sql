-- Compraventa comprimidas: 44 archivos. Cambia el enlace al PDF comprimido ya
-- subido a Drive. Se busca por el ID de Drive VIEJO: si alguien reemplazo ese
-- documento despues, esa fila no se toca.
START TRANSACTION;

-- NDF219797  (11.39 MB -> 1.24 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1a7mhsNs4l5pjreU55ijRVq0dEoDZHzaQ?v=1789066014', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1U0CZRhUjIjiJz2GkNVXJiMp2ZnWIwF2W';

-- T0410JX140161  (6.46 MB -> 1.97 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/14jBbO09oUFRy7ar5IXFGq3lrhQIqn9tr?v=1789066044', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1BdMMNL161QlQ9ePkbKFdP55M9evj9878';

-- LC0410601  (6.16 MB -> 1.84 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1iIPzMxLl8T0cBWZw9PeXFz_2oEboaJve?v=1789066058', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1yRMFc_es5XqVKeTz7HHZycbnasyQd0-3';

-- VCEL120FC00072153  (5.85 MB -> 1.70 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1VIr0EtLS9scRbcVveTMQFOuzOUAaJbbn?v=1789066114', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1wF9f-7RIJP6XP08WT8TIoqK9LY_aobM6';

-- T0410JX149770  (5.68 MB -> 1.67 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1lDzexQ89-WeQbiS6ojv5OsyhufaVJfKu?v=1789066140', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1rMrBw211h2opPDC0aH6IvlWVSOqDSm22';

-- 96U09513  (3.97 MB -> 1.15 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1IfFuSejeGAYR5VvLfRzt2zvIrlswB6Ma?v=1789066148', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1tzEd7DznOUcPUFqpnBgWJ0kRdBu6iHN4';

-- 35596010001787  (3.94 MB -> 1.17 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1oIaaXdMfNMDXl7KU3OT2Q5YRjkAr2E04?v=1789066156', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1ED8-74SwqaY8T2fZzSDRNVB0azeL7C1T';

-- 13B01258  (3.90 MB -> 1.17 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1YS6EMclw6Uv712swqiKqe5CPQ0y3dzji?v=1789066166', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1-8kfBhQjlodvvK63sGmuDPv7npspmgY2';

-- CAT0938KJHFW00671  (3.89 MB -> 1.16 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1Cd62ICuJWJQ0soJf6zjVpgIOkTqtWQlr?v=1789066176', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1kCf3MguWpBmEtwqxT6ghm9ysRyJkRvGA';

-- CAT0140HV5HM02916  (3.89 MB -> 1.20 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1LmTuF4n8NB0mleaUzOF1Qx4f5maki_no?v=1789066183', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1YUfPgKnVdYZRgXODHO56BJbGAG92FhT9';

-- CAT0323DEJEG00245  (3.86 MB -> 1.17 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/19bzU8xmPThuSRqnJLuBifXUSpwRiSfn2?v=1789066192', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1SPRt6O5l6JWFIr020cKQbP4gE6EoJFEf';

-- CAT0938KCHFW00672  (3.85 MB -> 1.17 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/10AVRI3cmPIG0ztZHBgMjyfdY5riWP5_R?v=1789066200', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1nNGvjCHCO4_CUHfQcbVd7NeaB5QBrYWo';

-- CAT0012KEJJA03168  (3.79 MB -> 1.16 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1oktAhO1a6Fr0Hb-J8jDZ9a4UaOmhHEV1?v=1789066210', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1PtXNnpk-BA5DVibh2pBxphu0uVnNZ1-E';

-- CAT0336DTZCT00679  (3.40 MB -> 1.02 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1GH5dEW3O1EPeyIJKw2Xp3-EWf5Q_pSDA?v=1789066217', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '19igyluk8j2R235EA_lE48OKHWOCtTfR7';

-- CAT0631GTCLR00506  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1xQlUmTog94BE6KUaOX2GjPLnIVZV5h04?v=1789066223', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1mcXtSUy_XE2BmO5_h5BcguGNQBIyRuPI';

-- 2YD01532  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1-S2OVa-ZnLWxxK2V2IHEy88-mlg8GtvD?v=1789066231', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '110BsxFkgkniIjBCwS8zgHu8sYGgdgLjT';

-- CAT00D9THRJS00462  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1a_JYhOZv46ao8o70HyLzrAAlnFbO46QL?v=1789066236', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1Tv07X-IYiBc0KGTCtR8Mqk4COR3j91vn';

-- CATCB634CCDF00445  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1bmW6vdxneAukZNZx5MygD420ToOH05AM?v=1789066243', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1b1zoOK0DIkB1Hm7hFaIG_QphjhBxcJgQ';

-- CATCB634ECDF00453  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1n-7n0CsUr6zUu1NnDpdeE0Q1iDgn0r1C?v=1789066250', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1ukVLrXKap7ILUqs9Q65c2D2sGE90N9yn';

-- CAT00D9TTERJS00821  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1aEnnUmUOgPfmhKA_GUyRRCLmXaWJwU2d?v=1789066256', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1igRjgA0bfdoHoGjVc0a9i_a8m3P8Ttg_';

-- CAT00D9TLRJS00444  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1s705l8-g-9dskU_nw_Qg8l6jT3SiICZ0?v=1789066267', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1npmS9ij7vCaWJxH7YkDRZA4FuWumMJFM';

-- RJS00404  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1E4NwwUI3iElp7KLryKJvlRSg44nOq-N_?v=1789066273', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1E-afao296ER4U3Wzdj9yPeV2MN_tBU8t';

-- CAT00D9TCRJS00813  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1BrD4F9AlDr1rCgR7cEjTFouxmGGiyJrs?v=1789066280', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1ZkYmpWSNYG8WYpQRSOt7wnOLirNfHrm_';

-- CAT0345CHPJW01925  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1j0U_2Q2S4LNdbrlh0TuvieSB4vItqMy3?v=1789066286', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1oci3k-3FrVGAUjp7OXzmD3tCEIqfvigy';

-- 1AB01144  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1kZSHwYFQpQAqOOiVII1hjOB6vvKHga-t?v=1789066292', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '13MR_Cu4ReXyffI8GUbJqsiohVvR5ZQqm';

-- 1AB00936  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1CMIIbveJcPSF1o5y4XedBY-EsU7vfnaE?v=1789066298', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1v3sGQs3fQnQukwQhXVYOfq6pUgL8FKhe';

-- 1AB01145  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1bU4NgKnOV_AMTdgeAf1PlJzstj1UTDKu?v=1789066304', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1Pm8trempxTe5-IOwYveul3iEocogdmux';

-- 1AB00784  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1m3ygGpXN1BZ2UuZtPuoFEoSst2zvA6L2?v=1789066310', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1N-bWndo4_gLhSk2oVPDy4yczXjILLXYp';

-- 1AB00801  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1sHWIzE8_BRs3Zgq21qyF7nUXvNnCOfhe?v=1789066316', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1tV7AV-9MgTOcmP9Flpcxtz7S3JD3FSzy';

-- 1AB00990  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/19hEWu408111IFVOC5ATRxQKHB0RfRWLB?v=1789066322', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1hT0ll1l5uUmvdaRWEhNDxnNTGbH83FHl';

-- 1AB01338  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1HC7Dl_V5jHxIU3MG44AI7l7eRSsohlKz?v=1789066328', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1eZGykdNku6JgXAN_A67cvetU--gdssmc';

-- 1NB00676  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1kLemvp1yLTeFSAC9p19l0yn3cz_dL9yV?v=1789066333', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '17WY8NkzOXZXLsm1jOBPnH1O4IPGW3VPf';

-- CAT0631GHAWK00301  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1nxg5MD8M7MCg6rSZ_ohbceKXGZp_3Xwc?v=1789066339', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1bX4XwYvei8EOw5o81lZDgAy5atHQq9bd';

-- CAT0631GAAWK00313  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1Xpv5293ZtjLU5r9wvRRnZiAfubsQ6lmA?v=1789066345', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1FAn23QHb7osv0GxpI8ClCjjWDo7TJA_k';

-- CAT0631GVAWK00309  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1qRKjx-ZTA-1Swr6hO1ksdR3ntrxr72yU?v=1789066351', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1eJ3FLV6CpQU1CFlDSzBE8YFUhLvQQ56M';

-- CAT0631GJAWK00307  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/15vqqk1tv34AJ4TbFzXMsFsY6WsnnnxuI?v=1789066357', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1WPS5GBvOij_7r58wKSc8gkrQhHd4jdOp';

-- CAT0631GCAWK00298  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/16d2lB-6GpF0HsxaAJpg0RgYc9g2ENFYP?v=1789066363', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1xia6dLXRcAqHZEzqXP1b8Xt5L-eKJl4t';

-- CAT0631GHAWK00296  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1cCB2Xa3kVQLG-vJvcoqH88TlOso0Hl2x?v=1789066369', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1259tMJh7gAMe5y7RZ2RwtzQlwxpuUJq9';

-- CAT0631GLAWK00314  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1BWzfXdDXqeofXWKB9Rfba_0HjwMJOxuV?v=1789066375', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '158ISs5d6FlKpX4yE6UuF6AeABl5meYUH';

-- 6RN00331  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1LZikVUkMJg6yTptUBnCLmC9EHlEoQzq8?v=1789066380', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1IE7l0ctfFygvusjiEeEdQuplhLU-qSDE';

-- 1NB00944  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1UdgOA27Xyx9lPjhq5A6lSAinEd-3Bgu7?v=1789066386', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1UeJLdAym__d_fx_nbuzkv2ujHp2_2Ela';

-- 7WJ00976  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1VnGUqKCpEFSMSpPKTnA1V_PskYljfpWx?v=1789066393', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1M8fTwQjWPbFi7W1gVK_NVXwWnCZLdGty';

-- CAT0631GCCLR00505  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1kSsxCfcBe3nhVFUK9N6fIDFbVHcHQCYl?v=1789066401', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1QS98dGfhTOM2iIfpqLwi29_LcrRmxa7h';

-- CAT0631GCAWK00311  (2.61 MB -> 0.69 MB)
UPDATE documentacion SET LINK_DOC_ADICIONAL_2 = '/storage/google/1xvOor8s5rh2i_cKkyZUzDF8OTcr28vyd?v=1789066407', updated_at = NOW()
WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) = '1fI-ErALXo9twJ2kA-gYOJF-gZisuxYWf';

-- Debe dar 44.
SELECT COUNT(*) AS actualizados FROM documentacion WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(LINK_DOC_ADICIONAL_2, '/storage/google/', -1), '?', 1) IN ('1a7mhsNs4l5pjreU55ijRVq0dEoDZHzaQ','14jBbO09oUFRy7ar5IXFGq3lrhQIqn9tr','1iIPzMxLl8T0cBWZw9PeXFz_2oEboaJve','1VIr0EtLS9scRbcVveTMQFOuzOUAaJbbn','1lDzexQ89-WeQbiS6ojv5OsyhufaVJfKu','1IfFuSejeGAYR5VvLfRzt2zvIrlswB6Ma','1oIaaXdMfNMDXl7KU3OT2Q5YRjkAr2E04','1YS6EMclw6Uv712swqiKqe5CPQ0y3dzji','1Cd62ICuJWJQ0soJf6zjVpgIOkTqtWQlr','1LmTuF4n8NB0mleaUzOF1Qx4f5maki_no','19bzU8xmPThuSRqnJLuBifXUSpwRiSfn2','10AVRI3cmPIG0ztZHBgMjyfdY5riWP5_R','1oktAhO1a6Fr0Hb-J8jDZ9a4UaOmhHEV1','1GH5dEW3O1EPeyIJKw2Xp3-EWf5Q_pSDA','1xQlUmTog94BE6KUaOX2GjPLnIVZV5h04','1-S2OVa-ZnLWxxK2V2IHEy88-mlg8GtvD','1a_JYhOZv46ao8o70HyLzrAAlnFbO46QL','1bmW6vdxneAukZNZx5MygD420ToOH05AM','1n-7n0CsUr6zUu1NnDpdeE0Q1iDgn0r1C','1aEnnUmUOgPfmhKA_GUyRRCLmXaWJwU2d','1s705l8-g-9dskU_nw_Qg8l6jT3SiICZ0','1E4NwwUI3iElp7KLryKJvlRSg44nOq-N_','1BrD4F9AlDr1rCgR7cEjTFouxmGGiyJrs','1j0U_2Q2S4LNdbrlh0TuvieSB4vItqMy3','1kZSHwYFQpQAqOOiVII1hjOB6vvKHga-t','1CMIIbveJcPSF1o5y4XedBY-EsU7vfnaE','1bU4NgKnOV_AMTdgeAf1PlJzstj1UTDKu','1m3ygGpXN1BZ2UuZtPuoFEoSst2zvA6L2','1sHWIzE8_BRs3Zgq21qyF7nUXvNnCOfhe','19hEWu408111IFVOC5ATRxQKHB0RfRWLB','1HC7Dl_V5jHxIU3MG44AI7l7eRSsohlKz','1kLemvp1yLTeFSAC9p19l0yn3cz_dL9yV','1nxg5MD8M7MCg6rSZ_ohbceKXGZp_3Xwc','1Xpv5293ZtjLU5r9wvRRnZiAfubsQ6lmA','1qRKjx-ZTA-1Swr6hO1ksdR3ntrxr72yU','15vqqk1tv34AJ4TbFzXMsFsY6WsnnnxuI','16d2lB-6GpF0HsxaAJpg0RgYc9g2ENFYP','1cCB2Xa3kVQLG-vJvcoqH88TlOso0Hl2x','1BWzfXdDXqeofXWKB9Rfba_0HjwMJOxuV','1LZikVUkMJg6yTptUBnCLmC9EHlEoQzq8','1UdgOA27Xyx9lPjhq5A6lSAinEd-3Bgu7','1VnGUqKCpEFSMSpPKTnA1V_PskYljfpWx','1kSsxCfcBe3nhVFUK9N6fIDFbVHcHQCYl','1xvOor8s5rh2i_cKkyZUzDF8OTcr28vyd');

COMMIT;

-- Que el menu y el historial de documentos muestren ya los enlaces nuevos.
DELETE FROM cache WHERE `key` LIKE '%dashboard_user_data_%' OR `key` LIKE '%historial_docs_%';
