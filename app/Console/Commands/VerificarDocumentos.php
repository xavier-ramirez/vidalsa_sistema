<?php

namespace App\Console\Commands;

use App\Models\CatalogoSeguro;
use App\Models\DocumentoAnexo;
use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compara lo que dice la ficha de un equipo (tabla documentacion) con lo que dice su PDF.
 * Se revisan los cuatro documentos, EN ESTE ORDEN: cuando no queda ninguno del primero, la
 * pasada sigue con el segundo, y asi hasta el ultimo.
 *
 *   · TITULO DE PROPIEDAD : propietario y fecha de emision.
 *   · POLIZA DE SEGURO    : aseguradora, vencimiento y fecha de emision.
 *   · ROTC                : propietario, vencimiento, fecha de emision y numero de ROTC.
 *   · RACDA               : es de la EMPRESA, no del equipo: el mismo PDF cuelga de decenas de
 *                           fichas. De el salen la fecha de la providencia, hasta cuando vale
 *                           (dice cuantos años dura) y la lista de placas autorizadas, donde
 *                           tiene que estar la del equipo.
 *
 * Lo corre el programador de tareas de 12:30 a 6 de la madrugada
 * (routes/console.php), en una ventana que NO se toca con la de la compresion (06:00-07:30):
 * las dos leen de Drive.
 *
 *   php artisan docs:verificar-documentos                    10 documentos que falten
 *   php artisan docs:verificar-documentos --tipo=rotc
 *   php artisan docs:verificar-documentos --equipo=10        solo esa ficha (pruebas)
 *   php artisan docs:verificar-documentos --rehacer          vuelve a leer las ya revisadas
 *   php artisan docs:verificar-documentos --no-rellenar      solo anota, no rellena nada
 *
 * MANDA EL DOCUMENTO: lo que dice el PDF se pone en la ficha en cuanto se lee, este vacia o
 * diga otra cosa. NUNCA la placa ni el serial (App\Services\CorrectorFichaDocumento::CAMPOS),
 * que son lo que sirve para saber si el PDF es de este vehiculo, y NUNCA nada si el documento
 * es de otro vehiculo, se leyo a medias o no se pudo confirmar de quien es: eso queda en
 * Control de Auditoría para que lo mire una persona.
 *
 * Se revisa TODO lo que cuelgue de esos cuatro enlaces, sin mirar de que tipo de documento se
 * trate: si del texto no sale nada util —escaneos viejos, borrosos o documentos que no son
 * el certificado ni el cuadro de poliza— la fila queda "ilegible" y la revisa una persona.
 *
 * En el PC de desarrollo se corre SIEMPRE con --no-rellenar: de Drive no toca nada (la copia
 * que hace el lector para leerla la borra el mismo), pero SI escribe en la base —y la de
 * desarrollo es una copia, asi que esas correcciones no le sirven a nadie y ensucian el
 * historial de los equipos. Sin esa opcion, esto solo debe correr en el servidor.
 */
class VerificarDocumentos extends Command
{
    protected $signature = 'docs:verificar-documentos
                            {--lote=10 : Cuantos documentos revisar en esta pasada}
                            {--tipo= : Solo uno: propiedad, poliza, rotc o racda}
                            {--equipo= : Revisar solo esta ficha (ID_EQUIPO)}
                            {--no-rellenar : Solo anotar lo que dicen los PDF, sin escribir nada en las fichas}
                            {--rehacer : Volver a leer los ya revisados}';

    protected $description = 'Compara los documentos de cada ficha (titulo, poliza, ROTC y RACDA) con lo que dicen sus PDF.';

    /** [tipo => columna del enlace]. El ORDEN es el de la revision (ver VerificacionDocumento). */
    private const ENLACES = VerificacionDocumento::ENLACES;

    public function __construct(private CorrectorFichaDocumento $corrector)
    {
        parent::__construct();
    }

    public function handle(LectorDocumentoPdf $lector): int
    {
        $tipos = $this->option('tipo') ? [$this->option('tipo')] : array_keys(self::ENLACES);
        foreach ($tipos as $t) {
            if (!isset(self::ENLACES[$t])) {
                $this->error("Tipo desconocido: $t. Usa " . implode(', ', array_keys(self::ENLACES)) . '.');
                return self::FAILURE;
            }
        }

        $catalogo = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();
        $lote = max(1, (int) $this->option('lote'));
        $hechos = 0;

        // Lo ya leido otras noches que solo esperaba a que alguien pulsara un boton para
        // rellenar un hueco: se rellena ahora, sin volver a Drive (la lectura esta guardada).
        // Asi el dia que se enciende el rellenado automatico no queda una cola de filas viejas
        // pendientes de un clic que nadie tiene que dar.
        if (!$this->option('no-rellenar')) $this->rellenarLoYaLeido($tipos, $this->option('equipo'));

        // De uno en uno y EN ORDEN (asi lo pidio el cliente): la pasada se gasta en el primer
        // documento que tenga cola; cuando ese se acaba, el resto del lote sigue con el
        // siguiente. Los reintentos de los ilegibles no atascan la cola porque se agotan a las
        // MAX_INTENTOS veces.
        foreach ($tipos as $tipo) {
            foreach ($this->pendientes($tipo, $lote - $hechos) as $f) {
                $this->procesar($tipo, $f, $lector, $catalogo);
                if (++$hechos >= $lote) return self::SUCCESS;
            }
        }

        if ($hechos === 0) $this->info('No queda ningun documento por revisar.');
        return self::SUCCESS;
    }

    /**
     * Pone en la ficha lo que dicen los documentos YA leidos (sin volver a Drive): son las
     * lecturas de otras noches que seguian esperando un clic. Solo las que un boton podria
     * arreglar: las marcadas "a mano" (PDF de otro vehiculo, leido a medias, sin confirmar, o
     * ficha cambiada despues de leer) las decide una persona, no este comando.
     */
    private function rellenarLoYaLeido(array $tipos, $equipo = null): void
    {
        $puestas = 0;
        // Con --equipo, SOLO esa ficha: esa opcion es para probar una y no puede acabar
        // aplicando de golpe las correcciones pendientes de toda la flota.
        VerificacionDocumento::corregibles()->whereIn('TIPO', $tipos)
            ->when($equipo, fn ($q) => $q->where('ID_EQUIPO', (int) $equipo))
            ->orderBy('ID_REGISTRO')->chunkById(100, function ($filas) use (&$puestas) {
                foreach ($filas as $reg) {
                    if ($this->corrector->aplicar($reg, null)['puestos'] ?? []) $puestas++;
                }
            }, 'ID_REGISTRO');

        if ($puestas) $this->info("Se rellenaron $puestas fichas con lo ya leido (sin volver a Drive).");
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
                // ¿El PDF es de ESTE vehiculo? Se mira por placa y por serial del chasis.
                // Si es de otro, no se compara nada mas: lo que hay que arreglar es el archivo
                // enlazado. Si no se puede saber (el escaneo no deja leer ninguno de los dos),
                // se compara igual pero queda marcado para que lo confirme una persona: sin esa
                // comprobacion, aplicar lo leido podria meterle a la ficha datos de otro equipo.
                // El RACDA es de la EMPRESA y cuelga de muchas fichas: no tiene "una" placa,
                // tiene una LISTA, y de eso se encarga revisarRacda.
                $deEsteVehiculo = $tipo === VerificacionDocumento::RACDA
                    ? 'si'
                    : $lector->mismoVehiculo($f->PLACA, $f->SERIAL_CHASIS, $leido);
                if ($deEsteVehiculo === 'no') {
                    $leido['otra_placa'] = true;
                    $estado = VerificacionDocumento::DIFIERE;
                    $motivo = 'El documento es de otro vehiculo: dice '
                        . trim(($leido['placa'] ? 'placa ' . $leido['placa'] : '') . ' ' . ($leido['serial'] ? 'serial ' . $leido['serial'] : ''))
                        . ' y la ficha es ' . trim(($f->PLACA ? 'placa ' . $f->PLACA : '') . ' ' . ($f->SERIAL_CHASIS ? 'serial ' . $f->SERIAL_CHASIS : ''));
                } else {
                    if ($deEsteVehiculo === 'no_se_sabe') $leido['sin_confirmar'] = true;
                    [$estado, $motivo, $diferencias, $leido] = match ($tipo) {
                        VerificacionDocumento::POLIZA => $this->revisarPoliza($f, $leido, $texto, $lector, $catalogo),
                        VerificacionDocumento::ROTC   => $this->revisarRotc($f, $leido, $texto, $lector),
                        VerificacionDocumento::RACDA  => $this->revisarRacda($f, $leido, $lector),
                        default                       => $this->revisarPropiedad($f, $leido, $lector),
                    };
                    if (($leido['sin_confirmar'] ?? false) && $estado === VerificacionDocumento::DIFIERE) {
                        $motivo = 'No se pudo confirmar que el documento sea de este vehículo (no se leyó placa ni serial). ' . $motivo;
                    }
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
                // Lo que ninguna persona puede corregir con el boton (PDF de otro vehiculo,
                // leido a medias o sin confirmar de quien es) va al monton "para revisar".
                // Solo cuando hay algo que decidir: si todo cuadra, no hay nada que mirar.
                'A_MANO'      => $estado === VerificacionDocumento::DIFIERE
                    && (bool) (($leido['otra_placa'] ?? false) || ($leido['lectura_parcial'] ?? false)
                        || ($leido['sin_confirmar'] ?? false) || ($leido['fuera_de_lista'] ?? false)),
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

        // MANDA EL DOCUMENTO: lo que dice el PDF se pone en la ficha, este vacia o diga otra
        // cosa. Nunca la placa ni el serial (CorrectorFichaDocumento::CAMPOS), y nunca si el
        // PDF es de otro vehiculo, se leyo a medias o no se pudo confirmar de quien es.
        // Con --no-rellenar no escribe nada: solo anota lo que encontro.
        $puestos = [];
        if ($estado === VerificacionDocumento::DIFIERE && !$this->option('no-rellenar')) {
            $resultado = $this->corrector->aplicar($reg->refresh(), null);
            $puestos = $resultado['puestos'] ?? [];
        }

        $this->line(sprintf('%-9s %-10s %-11s %-12s %s', $f->ID_EQUIPO, $tipo,
            $f->PLACA ?: '—', $puestos ? $reg->refresh()->ESTADO : $estado,
            $puestos ? 'se puso solo: ' . implode(', ', $puestos) : ($motivo ?? $this->resumen($tipo, $leido))));
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

    /**
     * ROTC: el certificado de circulacion de carga. Trae propietario, las dos fechas y su
     * numero. La ficha guarda como FECHA_ROTC la de VENCIMIENTO (es la que vigilan las alertas).
     */
    private function revisarRotc(object $f, array $leido, string $textoRotc, LectorDocumentoPdf $lector): array
    {
        // Que el PDF sea DE VERDAD un ROTC, igual que con el RACDA: "Fecha de Emisión" y
        // "Fecha de Vencimiento" son rotulos que salen tambien en otros papeles del mismo
        // vehiculo. Si en LINK_ROTC hubiera por error su poliza, el vehiculo coincidiria, se
        // sacarian dos fechas y se escribirian en FECHA_ROTC como si tal cosa. Un ROTC trae su
        // numero o se nombra a si mismo.
        if (empty($leido['nro']) && !preg_match('/ROTC/i', $textoRotc)) {
            return [VerificacionDocumento::ILEGIBLE,
                'El documento enlazado no parece un ROTC (no trae el numero ni se nombra)', [], $leido];
        }
        if (!$leido['vence'] && !$leido['emision']) {
            return [VerificacionDocumento::ILEGIBLE, 'No se encontraron las fechas del ROTC en el documento', [], $leido];
        }
        $dif = [];
        $motivo = null;
        if (!empty($leido['titular'])) {
            [$iguales, $motivoNombre, $sirve] = array_pad($lector->compararNombre($f->NOMBRE_DEL_TITULAR, $leido['titular']), 3, true);
            if (!$iguales) {
                $dif['NOMBRE_DEL_TITULAR'] = ['etiqueta' => 'Propietario', 'ficha' => $f->NOMBRE_DEL_TITULAR, 'documento' => $leido['titular']];
                $motivo = $motivoNombre;
                if (!$sirve) $leido['lectura_parcial'] = true;
            }
        }
        $this->compararFecha($dif, 'FECHA_ROTC', 'Vencimiento', $f->FECHA_ROTC, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_ROTC', 'Fecha de emision', $f->FECHA_EMISION_ROTC, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $motivo ?: $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /**
     * RACDA: la providencia del MINEC, que es de la EMPRESA. Lo que dice de este equipo es si
     * su placa esta entre las unidades autorizadas; ademas da la fecha en que se emitio y
     * cuantos años vale, de donde sale el vencimiento que guarda la ficha.
     *
     * Un equipo que no aparece en la lista NO se arregla con un boton: o le falta el tramite o
     * tiene enlazada una providencia que no lo cubre. Por eso va a "revisar a mano".
     */
    private function revisarRacda(object $f, array $leido, LectorDocumentoPdf $lector): array
    {
        // Lo primero: que el PDF sea DE VERDAD una providencia. Toda hoja tiene codigos con
        // pinta de placa, asi que si en LINK_RACDA hubiera por error el titulo o la poliza de
        // este mismo equipo, su placa saldria en la lista, no habria fechas que comparar y la
        // fila se daria por "coincide": un documento mal enlazado quedaria verificado. Una
        // providencia trae su numero o su fecha ("PROVIDENCIA ADMINISTRATIVA N° 1120",
        // "CARACAS, 14 DE JULIO DE 2025"); sin ninguno de los dos, no se acepta.
        if (!$leido['nro'] && !$leido['emision']) {
            return [VerificacionDocumento::ILEGIBLE,
                'El documento enlazado no parece una providencia RACDA (no trae ni numero ni fecha)',
                [], $leido];
        }
        // Y sin lista de placas no hay nada que comprobar de ESTE equipo: lo unico que la
        // providencia dice de el es si esta autorizado.
        if (!$leido['placas']) {
            return [VerificacionDocumento::ILEGIBLE,
                'Se leyo la providencia pero no la lista de unidades autorizadas',
                [], $leido];
        }
        if (!$lector->placaEnLista($f->PLACA, $leido['placas'])) {
            $leido['fuera_de_lista'] = true;
            return [
                VerificacionDocumento::DIFIERE,
                'La placa ' . ($f->PLACA ?: '(la ficha no tiene placa)') . ' NO esta entre las '
                    . count($leido['placas']) . ' unidades autorizadas por esta providencia',
                [], $leido,
            ];
        }
        $dif = [];
        $this->compararFecha($dif, 'FECHA_RACDA', 'Vencimiento', $f->FECHA_RACDA, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_RACDA', 'Fecha de emision', $f->FECHA_EMISION_RACDA, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
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
        return match ($tipo) {
            VerificacionDocumento::POLIZA => trim(($leido['nro'] ?? '') . ' vence ' . ($leido['vence'] ?? '—') . ' emitida ' . ($leido['emision'] ?? '—')),
            VerificacionDocumento::ROTC   => 'ROTC ' . ($leido['nro'] ?? '—') . ' vence ' . ($leido['vence'] ?? '—'),
            VerificacionDocumento::RACDA  => 'providencia ' . ($leido['nro'] ?? '—') . ' del ' . ($leido['emision'] ?? '—')
                . ' con ' . count($leido['placas'] ?? []) . ' placas',
            default                       => (string) ($leido['titular'] ?? ''),
        };
    }

    /**
     * Fichas de ese documento que faltan por revisar (o todas, con --rehacer / --equipo).
     * La cola la define VerificacionDocumento::pendientes(), que es la MISMA que cuenta el
     * panel: asi el numero de "faltan por leer" siempre cuadra con lo que el comando hara.
     */
    private function pendientes(string $tipo, int $lote)
    {
        if ($lote < 1) return collect();
        $col = self::ENLACES[$tipo];
        $equipo  = $this->option('equipo');
        $rehacer = (bool) $this->option('rehacer');

        $q = ($rehacer || $equipo)
            ? DB::table('documentacion as d')
                ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
                ->whereNull('e.deleted_at')
                ->where("d.$col", 'like', '/storage/google/%')
            : VerificacionDocumento::pendientes($tipo, $col);

        return $q->when($equipo, fn ($q) => $q->where('d.ID_EQUIPO', (int) $equipo))
            ->orderBy('d.ID_EQUIPO')
            ->limit($lote)
            ->get([
                'd.ID_EQUIPO', 'd.PLACA', 'd.NOMBRE_DEL_TITULAR', 'd.FECHA_EMISION_PROPIEDAD',
                'd.ID_SEGURO', 'd.FECHA_VENC_POLIZA', 'd.FECHA_EMISION_POLIZA',
                'd.FECHA_ROTC', 'd.FECHA_EMISION_ROTC', 'd.FECHA_RACDA', 'd.FECHA_EMISION_RACDA',
                'e.SERIAL_CHASIS', DB::raw("d.$col as LINK"),
            ]);
    }
}
