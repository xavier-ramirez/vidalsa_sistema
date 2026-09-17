<?php

namespace App\Console\Commands;

use App\Models\CatalogoSeguro;
use App\Models\DocumentoAnexo;
use App\Models\VerificacionDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compara lo que dice la ficha de un equipo (tabla documentacion) con lo que dice su PDF:
 *
 *   · TITULO DE PROPIEDAD : nombre del propietario y fecha de emision.
 *   · POLIZA DE SEGURO    : aseguradora, fecha de vencimiento y fecha de emision.
 *
 * Lo corre el programador de tareas de madrugada (routes/console.php) DESPUES de la
 * compresion: mientras queden PDF por comprimir, esta tarea no arranca.
 *
 *   php artisan docs:verificar-documentos                    5 documentos que falten
 *   php artisan docs:verificar-documentos --tipo=poliza
 *   php artisan docs:verificar-documentos --equipo=10        solo esa ficha (pruebas)
 *   php artisan docs:verificar-documentos --rehacer          vuelve a leer las ya revisadas
 *
 * NO CAMBIA NINGUNA FICHA: solo lee el PDF y anota lo que encontro en
 * verificacion_documento_registro. Si los datos ya estan bien, el equipo queda intacto y la
 * fila se marca "coincide". La ficha solo cambia cuando una persona pulsa "Corregir ficha"
 * en /admin/compresion-pdf (CompresionPdfController::aplicarDocumento).
 *
 * Se revisa TODO lo que cuelgue de esos dos enlaces, sin mirar de que tipo de documento se
 * trate: si del texto no sale nada util —escaneos viejos, borrosos o documentos que no son
 * el certificado ni el cuadro de poliza— la fila queda "ilegible" y la revisa una persona.
 *
 * A diferencia de docs:comprimir, este comando puede correr en el PC de desarrollo: no toca
 * ni un archivo de Drive (la copia que hace el lector para leerla la borra el mismo).
 */
class VerificarDocumentos extends Command
{
    protected $signature = 'docs:verificar-documentos
                            {--lote=5 : Cuantos documentos revisar en esta pasada}
                            {--tipo= : Solo propiedad o solo poliza}
                            {--equipo= : Revisar solo esta ficha (ID_EQUIPO)}
                            {--rehacer : Volver a leer los ya revisados}';

    protected $description = 'Compara el titulo de propiedad y la poliza de cada ficha con lo que dicen sus PDF.';

    /** [tipo => columna del enlace en documentacion]. */
    private const ENLACES = [
        LectorDocumentoPdf::PROPIEDAD => 'LINK_DOC_PROPIEDAD',
        LectorDocumentoPdf::POLIZA    => 'LINK_POLIZA_SEGURO',
    ];

    public function handle(LectorDocumentoPdf $lector): int
    {
        $tipos = $this->option('tipo') ? [$this->option('tipo')] : array_keys(self::ENLACES);
        foreach ($tipos as $t) {
            if (!isset(self::ENLACES[$t])) {
                $this->error("Tipo desconocido: $t. Usa propiedad o poliza.");
                return self::FAILURE;
            }
        }

        $catalogo = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();
        $lote = max(1, (int) $this->option('lote'));
        $hechos = 0;

        foreach ($tipos as $tipo) {
            foreach ($this->pendientes($tipo, $lote - $hechos) as $f) {
                $this->procesar($tipo, $f, $lector, $catalogo);
                if (++$hechos >= $lote) return self::SUCCESS;
            }
        }

        if ($hechos === 0) $this->info('No queda ningun documento por revisar.');
        return self::SUCCESS;
    }

    private function procesar(string $tipo, object $f, LectorDocumentoPdf $lector, array $catalogo): void
    {
        $driveId = DocumentoAnexo::driveIdDeLink($f->LINK);
        $leido = [];
        $diferencias = [];
        $texto = '';
        $estado = VerificacionDocumento::COINCIDE;
        $motivo = null;

        if (!$driveId) {
            [$estado, $motivo] = [VerificacionDocumento::SIN_ARCHIVO, 'El enlace del documento no apunta a ningun archivo'];
        } else {
            try {
                $texto = $lector->texto($driveId);
                $leido = $lector->extraer($tipo, $texto);
                // El documento puede ser de OTRO vehiculo (una ficha con el PDF equivocado):
                // entonces no se compara nada mas, porque lo que hay que arreglar es el archivo.
                if (!$lector->mismaPlaca($f->PLACA, $leido['placa'] ?? null)) {
                    $leido['otra_placa'] = true;
                    $estado = VerificacionDocumento::DIFIERE;
                    $motivo = 'El documento es de la placa ' . $leido['placa'] . ', no de la ' . $f->PLACA;
                } else {
                    [$estado, $motivo, $diferencias, $leido] = $tipo === VerificacionDocumento::POLIZA
                        ? $this->revisarPoliza($f, $leido, $texto, $lector, $catalogo)
                        : $this->revisarPropiedad($f, $leido, $lector);
                }
            } catch (\Throwable $e) {
                // Un archivo borrado de Drive da 404 aqui: es "sin archivo", no un fallo del
                // que haya que reintentar cada noche.
                $noEsta = str_contains($e->getMessage(), '404') || stripos($e->getMessage(), 'not found') !== false;
                $estado = $noEsta ? VerificacionDocumento::SIN_ARCHIVO : VerificacionDocumento::ERROR;
                $motivo = $noEsta ? 'El archivo ya no esta en Drive' : 'No se pudo leer: ' . $e->getMessage();
                Log::warning("docs:verificar-documentos $tipo equipo {$f->ID_EQUIPO}: " . $e->getMessage());
            }
        }

        $reg = VerificacionDocumento::updateOrCreate(
            ['ID_EQUIPO' => $f->ID_EQUIPO, 'TIPO' => $tipo, 'DRIVE_ID' => $driveId],
            [
                'PLACA'  => $f->PLACA,
                'SERIAL' => $f->SERIAL_CHASIS,
                'LEIDO'       => $leido ?: null,
                'DIFERENCIAS' => $diferencias ?: null,
                'ESTADO'      => $estado,
                // MOTIVO es varchar(255): un error largo de Drive no puede tumbar la pasada.
                'MOTIVO'      => $motivo ? mb_substr($motivo, 0, 255) : null,
                'CARACTERES'  => mb_strlen($texto),
                // Los ilegibles y los fallidos se reintentan otras noches hasta MAX_INTENTOS
                // (Drive devuelve el documento vacio de vez en cuando); lo demas se lee una vez.
                'INTENTOS'    => in_array($estado, [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR], true)
                    ? (int) VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
                        ->where('DRIVE_ID', $driveId)->value('INTENTOS') + 1
                    : 0,
                // Lo revisado de nuevo vuelve a estar pendiente de que alguien lo mire.
                'APLICADO_POR' => null,
                'APLICADO_EN'  => null,
            ]
        );

        // Si le subieron otro archivo, la lectura del anterior ya no vale: se retira para que
        // no queden dos filas del mismo documento (ni se pueda aplicar lo que decia el viejo).
        VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
            ->where('ID_REGISTRO', '<>', $reg->ID_REGISTRO)->delete();

        $this->line(sprintf('%-9s %-7s %-11s %-12s %s', $f->ID_EQUIPO, $tipo === 'poliza' ? 'poliza' : 'titulo',
            $f->PLACA ?: '—', $estado, $motivo ?? $this->resumen($tipo, $leido)));
    }

    /** Titulo de propiedad: propietario y fecha de emision. */
    private function revisarPropiedad(object $f, array $leido, LectorDocumentoPdf $lector): array
    {
        if (empty($leido['titular'])) {
            return [VerificacionDocumento::ILEGIBLE, 'No se encontro el nombre del propietario en el documento', [], $leido];
        }
        $dif = [];
        [$iguales, $motivo, $sirve] = array_pad($lector->compararNombre($f->NOMBRE_DEL_TITULAR, $leido['titular']), 3, true);
        if (!$iguales) {
            $dif['NOMBRE_DEL_TITULAR'] = ['etiqueta' => 'Propietario', 'ficha' => $f->NOMBRE_DEL_TITULAR, 'documento' => $leido['titular']];
            // Nombre leido a medias: se muestra la diferencia, pero no se deja aplicar (lo
            // pondria PEOR que como esta). Lo mira una persona con el PDF delante.
            if (!$sirve) $leido['lectura_parcial'] = true;
        }
        $this->compararFecha($dif, 'FECHA_EMISION_PROPIEDAD', 'Fecha de emision', $f->FECHA_EMISION_PROPIEDAD, $leido['emision'] ?? null);

        // El motivo del nombre (errata, abreviado, otro alfabeto...) manda: es el que dice que
        // mirar. Si solo cambian fechas, se resume que falta y que esta distinto.
        return $dif
            ? [VerificacionDocumento::DIFIERE, $motivo ?: $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /** Poliza: aseguradora, vencimiento y fecha de emision. */
    private function revisarPoliza(object $f, array $leido, string $texto, LectorDocumentoPdf $lector, array $catalogo): array
    {
        // La aseguradora se busca en TODO el texto: su nombre esta en el membrete, no en un
        // rotulo fijo. Lo reconocido se guarda para que la pantalla lo muestre.
        $idSeguro = $lector->aseguradoraEnTexto($texto, $catalogo);
        $leido['aseguradora'] = $idSeguro ? $catalogo[$idSeguro] : null;
        $dif = [];

        // Sin fecha de vencimiento no se puede dar por revisada: es el dato que vigila la app.
        if (!$leido['vence']) {
            return [VerificacionDocumento::ILEGIBLE, $idSeguro
                ? 'Se reconocio la aseguradora, pero no la vigencia de la poliza'
                : 'No se encontro la aseguradora ni la vigencia en el documento', [], $leido];
        }
        if ($idSeguro && (int) $f->ID_SEGURO !== $idSeguro) {
            $dif['ID_SEGURO'] = [
                'etiqueta' => 'Aseguradora',
                // La pantalla muestra los NOMBRES; la ficha guarda el ID del catalogo, y es
                // ese el que se compara y el que se escribe (ficha_valor / valor).
                'ficha' => $catalogo[$f->ID_SEGURO] ?? '(sin aseguradora)',
                'ficha_valor' => $f->ID_SEGURO !== null ? (int) $f->ID_SEGURO : null,
                'documento' => $catalogo[$idSeguro],
                'valor' => $idSeguro,
            ];
        }
        $this->compararFecha($dif, 'FECHA_VENC_POLIZA', 'Vencimiento', $f->FECHA_VENC_POLIZA, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_POLIZA', 'Fecha de emision', $f->FECHA_EMISION_POLIZA, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /**
     * Resume las diferencias en una linea: separa lo que FALTA en la ficha (estaba vacio) de lo
     * que esta DISTINTO, que son dos cosas muy distintas para quien revisa.
     */
    private function motivoDe(array $dif): string
    {
        $faltan = $distintos = [];
        foreach ($dif as $d) {
            if (($d['ficha'] ?? null) === null || $d['ficha'] === '') $faltan[] = mb_strtolower($d['etiqueta']);
            else $distintos[] = mb_strtolower($d['etiqueta']);
        }
        $partes = [];
        if ($faltan)    $partes[] = 'falta en la ficha: ' . implode(', ', $faltan);
        if ($distintos) $partes[] = 'distinto: ' . implode(', ', $distintos);
        return ucfirst(implode('; ', $partes));
    }

    /** Suma una fecha a las diferencias si el documento la trae y la ficha dice otra (o ninguna). */
    private function compararFecha(array &$dif, string $campo, string $etiqueta, ?string $enFicha, ?string $enDocumento): void
    {
        if (!$enDocumento) return;
        $ficha = $enFicha ? substr((string) $enFicha, 0, 10) : null;
        if ($ficha === $enDocumento) return;
        $dif[$campo] = ['etiqueta' => $etiqueta, 'ficha' => $ficha, 'documento' => $enDocumento];
    }

    /** Lo leido, en una linea, para la salida del comando. */
    private function resumen(string $tipo, array $leido): string
    {
        return $tipo === VerificacionDocumento::POLIZA
            ? trim(($leido['nro'] ?? '') . ' vence ' . ($leido['vence'] ?? '—') . ' emitida ' . ($leido['emision'] ?? '—'))
            : (string) ($leido['titular'] ?? '');
    }

    /**
     * Fichas con ese documento cargado que todavia no se han revisado (o todas, con --rehacer).
     * "Revisado" es por equipo, tipo Y archivo: si le suben otro, el ID de Drive cambia y
     * vuelve a la cola sola. Los equipos borrados no se revisan, y los enlaces que no son de
     * Drive tampoco: sin ID no hay nada que leer y se quedarian ocupando el lote cada noche.
     */
    private function pendientes(string $tipo, int $lote)
    {
        if ($lote < 1) return collect();
        $col = self::ENLACES[$tipo];
        $idEnlace = "SUBSTRING_INDEX(SUBSTRING_INDEX(d.$col, '/storage/google/', -1), '?', 1)";
        $equipo  = $this->option('equipo');
        $rehacer = (bool) $this->option('rehacer');

        return DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->whereNull('e.deleted_at')
            ->where("d.$col", 'like', '/storage/google/%')
            // Un enlace sin id detras ("/storage/google/?v=1") no se puede leer: si entrara,
            // se guardaria sin DRIVE_ID, nunca contaria como revisado y ocuparia un sitio del
            // lote todas las noches.
            ->whereRaw("$idEnlace <> ''")
            ->when($equipo, fn ($q) => $q->where('d.ID_EQUIPO', (int) $equipo))
            ->when(!$rehacer && !$equipo, fn ($q) => $q->whereNotExists(
                fn ($s) => $s->from('verificacion_documento_registro as v')
                    ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')
                    ->where('v.TIPO', $tipo)
                    ->whereRaw("v.DRIVE_ID = $idEnlace")
                    // Lo leido cuenta como revisado; lo ilegible o fallido vuelve a la cola
                    // mientras le queden intentos (ver INTENTOS).
                    ->where(fn ($w) => $w->whereNotIn('v.ESTADO', [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR])
                        ->orWhere('v.INTENTOS', '>=', VerificacionDocumento::MAX_INTENTOS))
            ))
            ->orderBy('d.ID_EQUIPO')
            ->limit($lote)
            ->get([
                'd.ID_EQUIPO', 'd.PLACA', 'd.NOMBRE_DEL_TITULAR', 'd.FECHA_EMISION_PROPIEDAD',
                'd.ID_SEGURO', 'd.FECHA_VENC_POLIZA', 'd.FECHA_EMISION_POLIZA',
                'e.SERIAL_CHASIS', DB::raw("d.$col as LINK"),
            ]);
    }
}
