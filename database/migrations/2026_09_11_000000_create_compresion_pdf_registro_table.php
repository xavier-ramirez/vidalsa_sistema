<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Registro de la compresion de PDF de documentos (comando docs:comprimir).
     *
     * Una fila por ARCHIVO de Drive que el comando ya miro, se haya comprimido o no (si
     * varias filas comparten el archivo, se anota la primera). Sirve para
     * dos cosas:
     *   · que la tarea de cada noche NO vuelva a tocar lo que ya proceso: un PDF que
     *     despues de comprimido sigue pesando mas del umbral quedaria en la lista para
     *     siempre si no se recordara que ya se hizo;
     *   · que el administrador vea en la pantalla "Compresion de PDF" que se comprimio,
     *     cuanto bajo, y que se salto y por que.
     *
     * Se identifica por el ID de Drive: el enlace de la BD cambia al comprimir, pero el
     * ID viejo y el nuevo quedan aqui, asi que el documento se reconoce por cualquiera
     * de los dos.
     */
    public function up(): void
    {
        if (Schema::hasTable('compresion_pdf_registro')) return;

        Schema::create('compresion_pdf_registro', function (Blueprint $table) {
            $table->bigIncrements('ID_REGISTRO');
            // Donde vive el enlace: documentacion (equipos), equipos_auxiliares o
            // documento_anexos (correcciones); ver App\Support\EnlacesDocumentos.
            $table->string('TABLA', 40);
            $table->string('COLUMNA', 40);
            // ID_EQUIPO, ID_AUXILIAR o ID_ANEXO, segun TABLA. Null en las filas cargadas a mano.
            $table->unsignedBigInteger('FILA_ID')->nullable();
            $table->string('DOCUMENTO', 60);
            $table->string('SERIAL', 80)->nullable();
            $table->string('DRIVE_ID_VIEJO', 80)->index();
            $table->string('DRIVE_ID_NUEVO', 80)->nullable()->index();
            $table->unsignedBigInteger('BYTES_ANTES')->nullable();
            $table->unsignedBigInteger('BYTES_DESPUES')->nullable();
            // comprimido | saltado | error
            $table->string('ESTADO', 20)->index();
            $table->string('MOTIVO', 255)->nullable();
            // noche (la tarea programada) | manual (comprimidos a mano el 10-09-2026)
            $table->string('ORIGEN', 20)->default('noche');
            $table->timestamps();
        });

        // Los que ya se comprimieron a mano el 10-09-2026 (polizas, compraventas y
        // certificados de equipos y auxiliares). Sin esto la tarea los volveria a
        // procesar: varios siguen pesando mas de 1000 KB aun comprimidos.
        $ahora = now();
        DB::table('compresion_pdf_registro')->insert(array_map(fn ($f) => [
            'TABLA' => $f[0], 'COLUMNA' => $f[1], 'DOCUMENTO' => $f[2], 'SERIAL' => $f[3],
            'DRIVE_ID_VIEJO' => $f[4], 'DRIVE_ID_NUEVO' => $f[5],
            'BYTES_ANTES' => $f[6], 'BYTES_DESPUES' => $f[7],
            'ESTADO' => 'comprimido', 'MOTIVO' => 'Comprimido a mano el 10-09-2026', 'ORIGEN' => 'manual',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ], self::COMPRIMIDOS_A_MANO));
    }

    public function down(): void
    {
        Schema::dropIfExists('compresion_pdf_registro');
    }

    // [tabla, columna, documento, serial, drive_id_viejo, drive_id_nuevo, bytes_antes, bytes_despues]
    private const COMPRIMIDOS_A_MANO = [
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'NDF219797', '1U0CZRhUjIjiJz2GkNVXJiMp2ZnWIwF2W', '1a7mhsNs4l5pjreU55ijRVq0dEoDZHzaQ', 11946024, 1299365],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'T0410JX140161', '1BdMMNL161QlQ9ePkbKFdP55M9evj9878', '14jBbO09oUFRy7ar5IXFGq3lrhQIqn9tr', 6775931, 2061490],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'LC0410601', '1yRMFc_es5XqVKeTz7HHZycbnasyQd0-3', '1iIPzMxLl8T0cBWZw9PeXFz_2oEboaJve', 6455987, 1932403],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'VCEL120FC00072153', '1wF9f-7RIJP6XP08WT8TIoqK9LY_aobM6', '1VIr0EtLS9scRbcVveTMQFOuzOUAaJbbn', 6130916, 1787397],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'T0410JX149770', '1rMrBw211h2opPDC0aH6IvlWVSOqDSm22', '1lDzexQ89-WeQbiS6ojv5OsyhufaVJfKu', 5954032, 1754385],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '96U09513', '1tzEd7DznOUcPUFqpnBgWJ0kRdBu6iHN4', '1IfFuSejeGAYR5VvLfRzt2zvIrlswB6Ma', 4168034, 1208078],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '35596010001787', '1ED8-74SwqaY8T2fZzSDRNVB0azeL7C1T', '1oIaaXdMfNMDXl7KU3OT2Q5YRjkAr2E04', 4126387, 1224040],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '13B01258', '1-8kfBhQjlodvvK63sGmuDPv7npspmgY2', '1YS6EMclw6Uv712swqiKqe5CPQ0y3dzji', 4091749, 1227417],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0938KJHFW00671', '1kCf3MguWpBmEtwqxT6ghm9ysRyJkRvGA', '1Cd62ICuJWJQ0soJf6zjVpgIOkTqtWQlr', 4079609, 1214259],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0140HV5HM02916', '1YUfPgKnVdYZRgXODHO56BJbGAG92FhT9', '1LmTuF4n8NB0mleaUzOF1Qx4f5maki_no', 4074774, 1256208],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0323DEJEG00245', '1SPRt6O5l6JWFIr020cKQbP4gE6EoJFEf', '19bzU8xmPThuSRqnJLuBifXUSpwRiSfn2', 4051087, 1228781],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0938KCHFW00672', '1nNGvjCHCO4_CUHfQcbVd7NeaB5QBrYWo', '10AVRI3cmPIG0ztZHBgMjyfdY5riWP5_R', 4042206, 1223308],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0012KEJJA03168', '1PtXNnpk-BA5DVibh2pBxphu0uVnNZ1-E', '1oktAhO1a6Fr0Hb-J8jDZ9a4UaOmhHEV1', 3971136, 1219887],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0336DTZCT00679', '19igyluk8j2R235EA_lE48OKHWOCtTfR7', '1GH5dEW3O1EPeyIJKw2Xp3-EWf5Q_pSDA', 3569410, 1066960],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GTCLR00506', '1mcXtSUy_XE2BmO5_h5BcguGNQBIyRuPI', '1xQlUmTog94BE6KUaOX2GjPLnIVZV5h04', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '2YD01532', '110BsxFkgkniIjBCwS8zgHu8sYGgdgLjT', '1-S2OVa-ZnLWxxK2V2IHEy88-mlg8GtvD', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT00D9THRJS00462', '1Tv07X-IYiBc0KGTCtR8Mqk4COR3j91vn', '1a_JYhOZv46ao8o70HyLzrAAlnFbO46QL', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CATCB634CCDF00445', '1b1zoOK0DIkB1Hm7hFaIG_QphjhBxcJgQ', '1bmW6vdxneAukZNZx5MygD420ToOH05AM', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CATCB634ECDF00453', '1ukVLrXKap7ILUqs9Q65c2D2sGE90N9yn', '1n-7n0CsUr6zUu1NnDpdeE0Q1iDgn0r1C', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT00D9TTERJS00821', '1igRjgA0bfdoHoGjVc0a9i_a8m3P8Ttg_', '1aEnnUmUOgPfmhKA_GUyRRCLmXaWJwU2d', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT00D9TLRJS00444', '1npmS9ij7vCaWJxH7YkDRZA4FuWumMJFM', '1s705l8-g-9dskU_nw_Qg8l6jT3SiICZ0', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'RJS00404', '1E-afao296ER4U3Wzdj9yPeV2MN_tBU8t', '1E4NwwUI3iElp7KLryKJvlRSg44nOq-N_', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT00D9TCRJS00813', '1ZkYmpWSNYG8WYpQRSOt7wnOLirNfHrm_', '1BrD4F9AlDr1rCgR7cEjTFouxmGGiyJrs', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0345CHPJW01925', '1oci3k-3FrVGAUjp7OXzmD3tCEIqfvigy', '1j0U_2Q2S4LNdbrlh0TuvieSB4vItqMy3', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB01144', '13MR_Cu4ReXyffI8GUbJqsiohVvR5ZQqm', '1kZSHwYFQpQAqOOiVII1hjOB6vvKHga-t', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB00936', '1v3sGQs3fQnQukwQhXVYOfq6pUgL8FKhe', '1CMIIbveJcPSF1o5y4XedBY-EsU7vfnaE', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB01145', '1Pm8trempxTe5-IOwYveul3iEocogdmux', '1bU4NgKnOV_AMTdgeAf1PlJzstj1UTDKu', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB00784', '1N-bWndo4_gLhSk2oVPDy4yczXjILLXYp', '1m3ygGpXN1BZ2UuZtPuoFEoSst2zvA6L2', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB00801', '1tV7AV-9MgTOcmP9Flpcxtz7S3JD3FSzy', '1sHWIzE8_BRs3Zgq21qyF7nUXvNnCOfhe', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB00990', '1hT0ll1l5uUmvdaRWEhNDxnNTGbH83FHl', '19hEWu408111IFVOC5ATRxQKHB0RfRWLB', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1AB01338', '1eZGykdNku6JgXAN_A67cvetU--gdssmc', '1HC7Dl_V5jHxIU3MG44AI7l7eRSsohlKz', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1NB00676', '17WY8NkzOXZXLsm1jOBPnH1O4IPGW3VPf', '1kLemvp1yLTeFSAC9p19l0yn3cz_dL9yV', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GHAWK00301', '1bX4XwYvei8EOw5o81lZDgAy5atHQq9bd', '1nxg5MD8M7MCg6rSZ_ohbceKXGZp_3Xwc', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GAAWK00313', '1FAn23QHb7osv0GxpI8ClCjjWDo7TJA_k', '1Xpv5293ZtjLU5r9wvRRnZiAfubsQ6lmA', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GVAWK00309', '1eJ3FLV6CpQU1CFlDSzBE8YFUhLvQQ56M', '1qRKjx-ZTA-1Swr6hO1ksdR3ntrxr72yU', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GJAWK00307', '1WPS5GBvOij_7r58wKSc8gkrQhHd4jdOp', '15vqqk1tv34AJ4TbFzXMsFsY6WsnnnxuI', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GCAWK00298', '1xia6dLXRcAqHZEzqXP1b8Xt5L-eKJl4t', '16d2lB-6GpF0HsxaAJpg0RgYc9g2ENFYP', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GHAWK00296', '1259tMJh7gAMe5y7RZ2RwtzQlwxpuUJq9', '1cCB2Xa3kVQLG-vJvcoqH88TlOso0Hl2x', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GLAWK00314', '158ISs5d6FlKpX4yE6UuF6AeABl5meYUH', '1BWzfXdDXqeofXWKB9Rfba_0HjwMJOxuV', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '6RN00331', '1IE7l0ctfFygvusjiEeEdQuplhLU-qSDE', '1LZikVUkMJg6yTptUBnCLmC9EHlEoQzq8', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '1NB00944', '1UeJLdAym__d_fx_nbuzkv2ujHp2_2Ela', '1UdgOA27Xyx9lPjhq5A6lSAinEd-3Bgu7', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', '7WJ00976', '1M8fTwQjWPbFi7W1gVK_NVXwWnCZLdGty', '1VnGUqKCpEFSMSpPKTnA1V_PskYljfpWx', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GCCLR00505', '1QS98dGfhTOM2iIfpqLwi29_LcrRmxa7h', '1kSsxCfcBe3nhVFUK9N6fIDFbVHcHQCYl', 2739046, 719032],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'Compraventa', 'CAT0631GCAWK00311', '1fI-ErALXo9twJ2kA-gYOJF-gZisuxYWf', '1xvOor8s5rh2i_cKkyZUzDF8OTcr28vyd', 2739046, 719032],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070009', '1WYOvYvQPG3JKAOOxibA6S6EP9vFhTE25', '1w-19UFxnaXBwuF3TbeDfCDdF9uFwtFK5', 2031226, 646815],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070006', '1NgzMt9ZJiQW3qzgfetUo8-rTKL5aj2FL', '1pzlQWPRaUyFAjP910vgASiwlSiOAtjUc', 1980405, 611507],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070021', '1f1UrDqHG9vkLbPIQn5pBPJLSc85niogT', '1WdecRQfGPcZyT09k1R5ncnSTNN_7DaL0', 1977390, 637319],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070004', '18qAtSLrDhNya370rHFP0xPlgjk3Bs8T3', '1TPj2yfBbNU5hcWcg0Vv45ezm4uQx-rng', 1974711, 653865],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070007', '1MYprleOIwuvuYPO_bUULKA7qYe1zSBPj', '19UcaQtDAP3liuF4KR_zIbyHxNQUnRNXz', 1968075, 630053],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070028', '1d8y3hE4vwG3IFMgn9iCunWm1mbKX8oH1', '1VZOyCNV5YNtsopSBKWGBIlRgz9QleZLU', 1954200, 617710],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070020', '14NLI4jDnaN8WnA_ZHdF_XhRQzaqLvLYH', '1Ep_RL_xkL7HAJc-udg3XHwjAVxLSL4DV', 1917500, 614179],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070024', '1f5VsB8mmJXuW_kqt51tDemcubuNW_qFv', '1WeUINyHRqvsF6wvbuaAwIl5HDDIL2YTo', 1910764, 622243],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070003', '12E2b8EEN_BLl5U3QrgA_ZQtSaYV7w9sI', '1Av3M81UFF_AGCXgZ_d1S_fp25FNyEMJF', 1892091, 611354],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070019', '1YlYp5AF5YCSrT3d3ApJfMr5dt-Votxri', '1LMAgT5noGrrqrvhOerLt2vQceSZueLsh', 1877281, 602563],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070015', '11HITikY0deMwP6G5sO5TCD4pLYWMjX4l', '1EhdcYdz5rqNgBopbSUWEy35NRVlOuGZo', 1863200, 595298],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070001', '1h-Wk4-yjps1PYpNd7WvsAV5e0W_xfj6Y', '1lZ4vCo4JGWe4NL81QnjCUsuK_8OlQxAL', 1856516, 603143],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070022', '1FCkKUI6St0sySBMFmDVE5GGVz2V9eQrV', '1vGFDyLs89JmU2Ql1IrliukJOAB8LSpL2', 1851400, 592545],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070026', '1zAqLG_KIKVSmojLRMEcoygR8IbOEqNew', '1gbEPlpLtLMOPGyYe_6YcH_OuQFTSiv8V', 1840673, 600147],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070010', '198qvuQaMKR-1SxKtPr4NyV76v6JpaDHY', '1Jort23EzPUyMnc1U1N_K5z02vvusAjDN', 1832402, 569360],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070031', '1hL9zcdhFPelktTR_rAjs3n-RaYma0qaO', '1ZQMeifWMULxJQA9Vc_X-wMCA92QLHYLz', 1822510, 572856],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070025', '1fpYcE6jupcfSf5i20Lql6-1Mevw_T4hC', '1yLFWlkYQGw7OVQW6L6WHWTFzvrmXdn6U', 1808750, 590881],
        ['equipos_auxiliares', 'LINK_CERTIFICADO', 'Certificado (auxiliar)', '2025070005', '1lTi9rDrEvzSRWjIWCxnQsBjyTLJyhOB_', '1kU9A6IumvWSZDhdJY4mdEc0PztRM25Lc', 1741713, 576670],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSP45YAKS1003161', '13L7_Gv3Zk54z_wpPweQbjMSAClQCSZQh', '1yZR23cDke8ZXaEMjcyCn2VYjaW3Ovb_L', 4672631, 1857462],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSP25YAHS1055078', '1cKoY-_L3ScGQvZxKsGL-YMVwvXqM2GXc', '1sLelNr3PDLeq5dxtPolUh0LR-OEgjN01', 4634746, 1841949],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSP25YAJS1055056', '1XR8rUF7JPA4zyZCwoTZWwXmIoroB0jPT', '1FaSSeCXZoywWyMqPJsTT9lEvMH2hp8Nf', 4576223, 1820961],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '61A113', '1IiVADWj8A_K1boBf1tLkimcLReEP01KB', '1XAiJW-mk7ULFpW9mszRLZTT8s87jV55c', 4540165, 1797951],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '77V14577', '1sP6xa2WhIFc4OuPX1ZVr3Xe--m_0Zgur', '10CRHCXQ73eqHb5kWBSPkKtEzPBV68UKc', 4469433, 1758520],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSP45YAAS1003165', '1EO56GFmdu9iFViJh2cpr-Gnt31glwLyR', '1hBttxf89SsWe7WYvnV8wwZN2hgM9ot_2', 4421245, 1757330],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LHESN001941', '1CMzP1vtqs9TPuskysNr_rS-JoL3BhvHz', '1e5Ulgj_MgNuG3BsCFz1ziItHMnyYy8MN', 4045887, 2990910],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDLSW002267', '1PsM6M_fiVtsI7Lz4zFttoPP2rDi07_OB', '1DSD3fDtnnPoE0-Av62Ir8fDAnob3BuNQ', 3946400, 3154248],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDLSW002270', '1UM3urdkO0I4FHgHlFtX4eokPr8cDAsEl', '1nO1k3xjOspin0Q942WAl_32GN5IAunAF', 3761033, 2989742],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSD22AAAS1028313', '1YMwaJ5B0JQqgjIk1sSxpP3A-3mm9NLsf', '18c_z2awdg06h4swWWTKg3wntiv1sH4Bs', 3475350, 2619116],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT00330TKEL20242', '1oTRygTkDZpDfQ1Zw3g9uuzsvov5Ykhk_', '1F6GB7fV9YflqjQfqKbNPfer0CR5izQh5', 3427415, 3168098],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG0S0000017', '1dg0bicBb1xugNbJeBERVWP1xXKrAIJne', '16LDXXJZzKKLnXtLzDya9gEwMK9cJU-aI', 3271485, 1303215],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG9S0000016', '1cEw5rjrfViz0B8GtSLIj5_dojgk9AeK_', '1xC9NXucDKyK7SOqyKPqaTAJx9PGUJQee', 3261713, 1299512],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG5S0000031', '1mTpel_lpKzl1-lHgStYaAjBRMX8Kf1E_', '1xZb9Rb4EqDGwGMZlUB3cnLbFQ32r2Yhb', 3256840, 1290911],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG3S0000030', '1WtD8kN-dMr3Emi9dio2-r5MlZtC1lXRk', '1_i9z1iL0RsCk3v3JndAp378Axg-qHQXi', 3256390, 1295829],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG2S0000021', '1w6DRiGcTzAfAHORkmrS-TOljHjYChF4K', '1HOTb7YzNrbK1Ax9dEEkaWhI08eihdEhv', 3252770, 1293026],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'L1C29HRG2S0000018', '12vGQcXYtvN_t6b8BaDRAE3-ZZOABwndw', '1CJDpyvv_VlduTklsfYkihqMvSBVrQeRh', 3247595, 1290575],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDESZ000228', '1EyV0uJMUMQsdAP49xA085qveRzt6BmrX', '1kL9HWGVgbnDUkYmUvsBZuv4A9m-7QNIv', 3239335, 1247493],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CHSD22AAAS1028452', '1KssuJUWm-Q1hIhNs7tw72TQMvGbb3Hxe', '1mr--9ssUfzRrXZK0bIPlZPAGiK7jw-hj', 3215385, 2381053],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LHKSN001959', '1tkPk_6f9k7hSlo4Cj4wvGIbTn2NP6W_Y', '1WKQLASYvOiBigzqPFoDXBZE1-j_wLj_X', 3212180, 2556733],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '96U09513', '1BOZsmsuC8OldS0i0LM2VUN41YvN7W8aI', '1XM6y_bnS2pkQOs6t-1CjLl-iuMdMr9-N', 3149819, 1264658],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0012KCJJA03169', '1-vfbzIvZA87gPssrGAB3SfriLLWScRzr', '1cDxr42CZSfY4wVefcKdrXYYGA0oDf4sV', 3137988, 1266865],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'FTC003RNHSZ555560', '1KA7FYU-hel8hOY2oaoDmoI1ZZAE0Tsed', '1INQ5bSmoHPErwwSjulN4RMbcpdsSj7cK', 3059922, 2767434],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '2KZ03939', '1JkRtPGjhfIjrUHWmDbkflTn5c055YPY6', '1i0hHUd9UkvSp849lGOi_kUIAuiJyK2pl', 3053045, 1228546],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '5FM01814', '18QFnOxdWUnEub23h3uhVV_Fu7OdMhYOW', '1As5OQ4cIRjhCnEGidVkrcAt1_ZEjccez', 3007944, 1217649],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDCSZ000232', '1dab6d9-rjuDdXqyS1UyQkrZS_T3PL4Mj', '1_Y5VdZKlZ_PXXLXR66Xuhft7Zac2t6PI', 2999537, 1191158],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LHHSN001940', '1nVB3SPlLcaWgaJJYlhaTOaF4AR7w2eyu', '1V2zLfQNAxpPH-hoSCcjW4zDrNMS8M-96', 2694788, 1030111],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LHTSN001960', '1oQUzTmmI5FZ2g9qaIPacHdP4mA4b9Xyr', '1uWGl3TEgwGSMAEA2ZQ6RvWccP0T-6NIN', 2690433, 1023445],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'FTC003RHCSW556889', '17eri6PJVLxqxOeeIb-gqHurgEBbHC1qf', '1E9ryBRH5VXdRZWGVdYv8tiypBpVsCb7l', 2678912, 1067594],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0938KJHFW00671', '1_HAQSuxXVR7Y9vqS4SgId-GeSwgPFSmV', '1FJiJoO4qaPg-nCnOGA2TAFSeUV9pcCWy', 2673540, 1055996],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0012KEJJA03168', '1ebAxzUftS_JXtmaXpaTaMTrc_K0LyJmg', '1HV8PdIER15y_AsW00UX0zesXx8HxwST6', 2639557, 2388540],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'FTC003RNESZ555561', '1XjavbmgY8n-iXfTbE8qT8ycWY1Se4Sfg', '1pXCh0HtXuWGz25jr8EXLkSYdNHLntXTD', 2638988, 1049502],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', '1800K0120152', '1f4e5AI_ZbGsO57PPo15OBoEqIGCdKAQS', '19N_8tC_OE1XvGQLKdbuICAI_yd8DhXeL', 2598791, 1047672],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFASW001684', '1OGJnRSqZh17H3CMvadfhr_Us7qw4TrFN', '1qR22LAexGg4iiFqEKgw8TENKjJMlgkvK', 2584243, 1028643],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDHSZ000230', '1TWPcy_23s-oUq9617yFUEHmSsfhJQIX5', '1-AeKlnUxFnlupthpDY-x4YeGLHotwSRr', 2365687, 970238],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDESZ000231', '1IEcKXG_P2czM4inYvNT8mf8q_v3Tt0oE', '1IzKmmrZ9YXbAcs4VSRoD4pM1tnWp_Vpz', 2362693, 967470],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0336DTZCT00679', '1q2DvWSd95JKT1EKV5rn9l4fZZIdEI9Re', '19BQLwTb6g6u2bstt1IAe2snttVl1F2B5', 2354777, 930547],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFCSW001682', '1mjWbEnK0993ZRCET7LRXnmqb7NuE5fXQ', '18HQL7N611e5yfc9bxyhcO6LKH4v1WWkL', 2221231, 2043856],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0950GLAXX00598', '1jlc58dwScszB0wKZkYN4JuJOg8jQv-Kt', '11IYQPbxUsq1-WYehauls51bNRcVmfQXv', 2206215, 909449],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'FTC003RHVSS556867', '1QtMWcdxkbNmxPe1t4wWh9j-plnvelSq0', '114MNoCD4AObW6qknzE-ZqrbCMs0izSNd', 2030992, 819386],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT00320JZBN20779', '1JFMua0H-Dg3QiwZlgT5YdjxotGe-IZZV', '1HCwzJpI9xu8uUSwRQ3CSnkK3QpWHC02Y', 2012094, 823817],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT00320AZBN20866', '1eCiQ6VMOsEVLvEV0TYS8SzzHkYd8qD-1', '125HKtGbwK9lBzZCaObLgryzww6l7NlYx', 1974001, 801220],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'FTC003RHTSW556935', '1GFQkF-oJyhmcDyO-YledDnP_ZG5NDxdq', '1zx8KXSZ7ZPhJHlKDZsfu7eMVd0V4fDdN', 1943140, 785979],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CAT0938KCHFW00672', '1AGZmzGo_3lUMKymxi8NFcR-6TLuMf5B6', '15LSXnXRLIAt50iRbmxZ9_SvBr1_UwRqk', 1912221, 774710],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFVSW001697', '1JsW-kPxkyyowYPzRcixtbGumf0WpkZTg', '14pm8CzzgMLiG_Eu635J7eWA1DnZuYR81', 1903252, 780568],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFCSW001696', '1cJuivfytt4nkHefLsWe0T0ojFcmjrah8', '1G6qLrA8YKQDmjaPhuYHjTHBiIc1sUZck', 1894802, 775768],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFASW001698', '1IjSbc-foGKL7-dATNuBLGCN0ZXs9I0ET', '1pFoTWMxblGB6dUFuW0cwb_V4V5s9mim2', 1879355, 770598],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LFVSW001683', '1AjXtQlY42DLeTiRb31be8CH02h8p2UbM', '1LMLudbeOroOB0iH6K4hCwQZorxTGr8xA', 1853906, 761222],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDASZ000225', '1p4GGlfKzYxjda81lXvb6Hp5TmwmSVwNe', '1Z7Sw_RJBEyPsnbuir5lZq1B2dSSYPAf8', 1342248, 805839],
        ['documentacion', 'LINK_DOC_ADICIONAL', 'Certificado', 'CLW009LDCSZ000229', '1SkcSeZh5eB95_UKNPIQp3DnQAVBPJ4-_', '13q_Sg9kbL3oVj9AEGLnIfr9q9XIb7lwi', 1311603, 795639],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'LJRL13373F2004828', '1XFjafkSRCNqKiXwFg8LwnROkrvGhTcE4', '1WlVHU8V93sDotQXaYOb0DRt9vAafKe6A', 2047736, 206953],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'VCEL120FC00072153', '1tMzUJPNmkRzwvJ9wr4Pc1BlRgs-VUGfI', '12VL84UVCk5u7TXtj-dbfFUXP5xZLVVYn', 1863671, 600590],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'T0410JX149770', '19vYH1PWyY8HxOy1XBg2e7FBtr6EVgyG1', '15KLo9a0_0PpgCCNHwPTByATcqQPImZ_S', 1863671, 600589],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'T0410JX140161', '1wtWTPfkFyHER22TS6kCE8pYW3CRZwEF-', '1j96ZuQT481gs0fHlvGTv_Yt4V3M0cmuX', 1863671, 600589],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'LC0410601', '1yAldkDtYRyyZ2ZhijitUIUXyQ_vx5u0D', '1WflHEd9N9a0_7kkpnIZtQkpeW0P6URMW', 1863671, 600589],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', 'MHFBU8FS5K0035032', '1rclrtRJkumlHQF7gmFIjLajKfjaIb241', '1XsqCPXiRzVxvN09WadWFPIDrJ_aciy7Z', 1057042, 308964],
        ['documentacion', 'LINK_POLIZA_SEGURO', 'Poliza', '8YTWF3H66EGA04309', '1Gd9Ob1uP5iZQW9mVCnXzsYtB_8UaWQur', '1TffAmoj6EdtNtl_2sKHtqICxXTqh_R-v', 1030401, 298375],
    ];
};
