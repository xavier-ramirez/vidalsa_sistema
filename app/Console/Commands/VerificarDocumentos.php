<?php

namespace App\Console\Commands;

use App\Models\CatalogoSeguro;
use App\Models\DocumentoAnexo;
use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
 *   · RACDA               : es de la EMPRESA, no del equipo: la MISMA providencia vale para
 *                           muchos vehiculos (aunque en Drive este subida un archivo por
 *                           ficha). De ella salen la fecha, hasta cuando vale (dice cuantos
 *                           años dura) y la lista de placas autorizadas, donde tiene que
 *                           estar la del equipo.
 *
 * Lo corre el programador de tareas en su HORARIO, de noche (routes/console.php), en una
 * ventana que NO se toca con la de la compresion (ComprimirDocumentos::HORARIO): las dos leen
 * de Drive. Si no queda nada que leer ni que poner, el programador ni lo lanza
 * (VerificacionDocumento::hayTrabajo).
 *
 *   php artisan docs:verificar-documentos                    10 documentos que falten
 *   php artisan docs:verificar-documentos --tipo=rotc
 *   php artisan docs:verificar-documentos --equipo=10        solo esa ficha (pruebas)
 *   php artisan docs:verificar-documentos --rehacer          vuelve a leer las ya revisadas
 *   php artisan docs:verificar-documentos --parte=0 --de=4   uno de 4 procesos EN PARALELO: cada uno
 *                                                            lee solo sus fichas (ID_EQUIPO % 4 = 0),
 *                                                            asi no se pisan (ver $this->reparto())
 *   php artisan docs:verificar-documentos --reintentar       relee SOLO los ilegibles y los errores,
 *                                                            aunque hayan agotado sus 3 intentos (p. ej.
 *                                                            tras mejorar el lector)
 *   php artisan docs:verificar-documentos --no-rellenar      solo anota, no rellena nada
 *
 * MANDA EL DOCUMENTO: lo que dice el PDF se pone en la ficha en cuanto se lee, este vacia o
 * diga otra cosa. NUNCA la placa ni el serial (App\Services\CorrectorFichaDocumento::CAMPOS),
 * que son lo que sirve para saber si el PDF es de este vehiculo, y NUNCA nada si el documento
 * es de otro vehiculo o es el anterior. Si se leyo a medias o no se pudo confirmar de quien es,
 * solo las fechas que la ficha tiene vacias; el resto queda en Control de Auditoría para que
 * lo mire una persona.
 *
 * Se revisa TODO lo que cuelgue de esos cuatro enlaces, sin mirar de que tipo de documento se
 * trate: si del texto no sale nada util —escaneos viejos, borrosos o papeles que no son el
 * documento que dice el enlace— la fila queda "ilegible" y la revisa una persona.
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
                            {--rehacer : Volver a leer los ya revisados}
                            {--reintentar : Volver a leer SOLO los "no se pudo leer" y los errores, aunque hayan agotado sus intentos}
                            {--parte=0 : Con --de: que parte de las fichas lee este proceso (0, 1, ...)}
                            {--de=1 : En cuantas partes se reparte la cola, para leer con varios procesos a la vez}';

    protected $description = 'Compara los documentos de cada ficha (titulo, poliza, ROTC y RACDA) con lo que dicen sus PDF.';

    /** De que hora a que hora la corre el programador (hora de la app). UNICO sitio: lo leen routes/console.php y el panel. */
    public const HORARIO = ['20:00', '00:00'];

    /** Cache: cuando se pulso "Revisar ahora" en el panel. */
    private const AHORA = 'docs_verificar_ahora';

    /** Cuanto vale un "Revisar ahora": de sobra para leerlo todo (~30 documentos/minuto). */
    private const AHORA_HORAS = 12;

    /** ¿Le toca leer al programador? En su franja, o si alguien pidio "Revisar ahora". */
    public static function tocaLeer(): bool
    {
        [$desde, $hasta] = self::HORARIO;
        $hora = now()->format('H:i');
        // La franja termina a medianoche (20:00-00:00) o la cruza: vale de las 20:00 en adelante o antes del final.
        $enFranja = $desde <= $hasta ? ($hora >= $desde && $hora < $hasta) : ($hora >= $desde || $hora < $hasta);
        return $enFranja || self::pedidaAhora() !== null;
    }

    /**
     * "Revisar ahora" (boton del panel): la lectura arranca en el minuto siguiente, SIN
     * IMPORTAR LA HORA, y en esa pasada se vuelve a leer tambien lo que salio "No se pudo
     * leer" (VerificacionDocumento::inicioDeLaNoche cuenta desde aqui). Sigue hasta leerlo
     * todo: en cuanto no queda nada, el programador ya no lanza ningun proceso
     * (VerificacionDocumento::hayTrabajo). Se puede volver a pulsar cuando se quiera.
     */
    public static function pedirAhora(): void
    {
        Cache::put(self::AHORA, now()->toDateTimeString(), now()->addHours(self::AHORA_HORAS));
    }

    /** Cuando se pidio "Revisar ahora" (si sigue vigente), o null. */
    public static function pedidaAhora(): ?string
    {
        return Cache::get(self::AHORA);
    }

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

        [$parte, $de] = $this->reparto();
        if ($de < 1 || $de > 8 || $parte < 0 || $parte >= $de) {
            $this->error('--parte tiene que ir de 0 a --de menos 1, y --de de 1 a 8.');
            return self::FAILURE;
        }

        $catalogo = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();
        $lote = max(1, (int) $this->option('lote'));
        $hechos = 0;

        // Lo ya leido en pasadas anteriores que se quedo sin poner en la ficha: se pone ahora,
        // sin volver a Drive (la lectura esta guardada). Asi no queda una cola de filas viejas
        // con diferencias que la tarea si podia resolver.
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
     * Para leer con varios procesos A LA VEZ sin que se pisen: la cola se reparte por el resto
     * de ID_EQUIPO entre --de partes, y este proceso lee solo la suya (--parte). Hace falta
     * porque la cola no "reserva" documentos: dos procesos sin repartir cogerian los mismos y
     * leerian dos veces cada PDF. Lo que tarda es esperar a Drive (~6 s por documento), no el
     * servidor, asi que 4 procesos leen cerca de 4 veces mas en el mismo tiempo.
     */
    private function reparto(): array
    {
        return [(int) $this->option('parte'), (int) $this->option('de')];
    }

    /**
     * Pone en la ficha lo que dicen los documentos YA leidos (sin volver a Drive): las
     * lecturas de pasadas anteriores que se quedaron sin aplicar. Solo las que la tarea puede
     * poner sola: las marcadas "a mano" (PDF de otro vehiculo, leido a medias, sin confirmar,
     * o ficha cambiada despues de leer) las decide una persona en el visor, no este comando.
     */
    private function rellenarLoYaLeido(array $tipos, $equipo = null): void
    {
        $puestas = 0;
        // Con --equipo, SOLO esa ficha: esa opcion es para probar una y no puede acabar
        // aplicando de golpe las correcciones pendientes de toda la flota.
        [$parte, $de] = $this->reparto();
        VerificacionDocumento::corregibles()->whereIn('TIPO', $tipos)
            ->when($equipo, fn ($q) => $q->where('ID_EQUIPO', (int) $equipo))
            ->when($de > 1, fn ($q) => $q->whereRaw('ID_EQUIPO % ? = ?', [$de, $parte]))
            ->orderBy('ID_REGISTRO')->chunkById(100, function ($filas) use (&$puestas) {
                foreach ($filas as $reg) {
                    if ($this->corrector->aplicar($reg)['puestos'] ?? []) $puestas++;
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
                // El RACDA es de la EMPRESA y vale para muchos equipos: no tiene "una" placa,
                // tiene una LISTA, y de eso se encarga revisarRacda.
                $deEsteVehiculo = $tipo === VerificacionDocumento::RACDA
                    ? 'si'
                    : $lector->mismoVehiculo($f->PLACA, $f->SERIAL_CHASIS, $leido);
                // ROTC de flota: si este equipo tiene SU fila en la tabla, el documento lo ampara
                // y el vencimiento que vale es el de esa fila (el que va al lado de su serial).
                // La emision del certificado de debajo solo es la de este equipo si el
                // certificado es SUYO y del MISMO periodo: en el 49199 el de debajo vencia el
                // 30/05/2026 y la fila el 03/07/2027 (es el certificado anterior, no el PDF).
                if ($tipo === VerificacionDocumento::ROTC && ($fila = $lector->filaRotc($f->PLACA, $f->SERIAL_CHASIS, $leido))) {
                    if ($deEsteVehiculo !== 'si' || $leido['vence'] !== $fila['vence']) $leido['emision'] = null;
                    $leido['vence'] = $fila['vence'];
                    $leido['en_tabla'] = true;
                    if ($fila['placa_distinta'] ?? false) $leido['placa_en_tabla'] = $fila['placa'];
                    $deEsteVehiculo = 'si';
                }
                if ($deEsteVehiculo === 'no') {
                    $leido['otra_placa'] = true;
                    $estado = VerificacionDocumento::DIFIERE;
                    // El aviso de flota solo cuando el "no" lo dijo la TABLA (sin placa ni serial
                    // tras su rotulo): si lo dijo el rotulo, el aviso de siempre, que lo nombra.
                    $motivo = (($leido['flota'] ?? false) && !empty($leido['seriales_flota']) && !$leido['placa'] && !$leido['serial'])
                        ? mb_substr('Póliza de flota que NO ampara este equipo (serial ' . $f->SERIAL_CHASIS . '): su tabla trae '
                            . count($leido['seriales_flota']) . ' equipos — ' . implode(', ', $leido['seriales_flota']), 0, 255)
                        : 'El documento es de otro vehiculo: dice '
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
                // que haya que reintentar en cada pasada.
                $noEsta = str_contains($e->getMessage(), '404') || stripos($e->getMessage(), 'not found') !== false;
                $estado = $noEsta ? VerificacionDocumento::SIN_ARCHIVO : VerificacionDocumento::ERROR;
                $motivo = $noEsta ? 'El archivo ya no esta en Drive' : 'No se pudo leer: ' . $e->getMessage();
                Log::warning("docs:verificar-documentos $tipo equipo {$f->ID_EQUIPO}: " . $e->getMessage());
            }
        }

        // Si una persona ya habia revisado este documento (se relee por "Revisar ahora", ver
        // VerificacionDocumento::condicionLeido), su decision se respeta: de esta lectura solo se
        // ponen las fechas VACIAS y la fila vuelve a quedar como ella la dejo.
        $revision = VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
            ->where('DRIVE_ID', $driveId)->whereNotNull('APLICADO_POR')
            ->first(['ESTADO', 'A_MANO', 'DIFERENCIAS', 'MOTIVO', 'APLICADO_POR', 'APLICADO_EN']);

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
                // Lo que la tarea no puede aplicar sola (PDF de otro vehiculo, leido a
                // medias, sin confirmar de quien es, el anterior, una placa fuera de la lista del
                // RACDA u otra placa en la tabla del ROTC) va al monton "para revisar".
                // Solo cuando hay algo que decidir: si todo cuadra, no hay nada que mirar.
                'A_MANO'      => $estado === VerificacionDocumento::DIFIERE
                    && (bool) (($leido['otra_placa'] ?? false) || ($leido['lectura_parcial'] ?? false)
                        || ($leido['sin_confirmar'] ?? false) || ($leido['fuera_de_lista'] ?? false)
                        || ($leido['doc_anterior'] ?? false) || !empty($leido['placa_en_tabla'])),
                // Los ilegibles y los fallidos se reintentan en esa noche hasta MAX_INTENTOS
                // (Drive devuelve el documento vacio o a medias de vez en cuando); lo demas se
                // lee una vez. El anexo de poliza de FLOTA sin fechas tambien: sus fechas salen
                // de la firma de la ULTIMA pagina, y un texto que llego cortado se la come.
                'INTENTOS'    => match (true) {
                    in_array($estado, [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR], true)
                        => (int) VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
                            ->where('DRIVE_ID', $driveId)->value('INTENTOS') + 1,
                    default => 0,
                },
                // Lo revisado de nuevo vuelve a estar pendiente de que alguien lo mire.
                'APLICADO_POR' => null,
                'APLICADO_EN'  => null,
            ]
        );
        // updated_at es "cuando se leyo por ultima vez" (VerificacionDocumento::pendientes lo
        // compara con el inicio de la noche). Si la relectura dio lo mismo, Eloquent no guarda
        // nada y no lo mueve: sin esto, un "No se pudo leer" se releeria en bucle toda la noche.
        if (!$reg->wasRecentlyCreated && !$reg->wasChanged()) $reg->touch();

        // Si le subieron otro archivo, la lectura del anterior ya no vale: se retira para que
        // no queden dos filas del mismo documento (ni se pueda aplicar lo que decia el viejo).
        VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
            ->where('ID_REGISTRO', '<>', $reg->ID_REGISTRO)->delete();

        // MANDA EL DOCUMENTO: lo que dice el PDF se pone en la ficha, este vacia o diga otra
        // cosa. Nunca la placa ni el serial (CorrectorFichaDocumento::CAMPOS), y nunca si el
        // PDF es de otro vehiculo; si se leyo a medias o no se pudo confirmar de quien es, solo
        // las fechas que la ficha tiene vacias. Con --no-rellenar no escribe nada: solo anota.
        $puestos = [];
        if ($estado === VerificacionDocumento::DIFIERE && !$this->option('no-rellenar')) {
            $resultado = $this->corrector->aplicar($reg->refresh(), (bool) $revision);
            $puestos = $resultado['puestos'] ?? [];
        }
        // La revision de la persona vuelve a quedar como estaba (ver $revision, arriba). Si el
        // archivo ya no esta o no se pudo leer, eso SI se ve: no se tapa con la revision vieja.
        if ($revision && in_array($estado, [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE], true)) {
            $reg->refresh()->update($revision->only(['ESTADO', 'A_MANO', 'DIFERENCIAS', 'MOTIVO', 'APLICADO_POR', 'APLICADO_EN']));
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
        $this->compararFecha($dif, 'FECHA_EMISION_PROPIEDAD', 'Fecha de emisión', $f->FECHA_EMISION_PROPIEDAD, $leido['emision'] ?? null);

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
            return [VerificacionDocumento::ILEGIBLE, match (true) {
                // El anexo de flota no trae "Desde / Hasta": sus fechas salen de la firma de la
                // ultima pagina (LectorDocumentoPdf, vence_por_firma). Si llega aqui es que no
                // se encontro esa firma; se reintenta como cualquier ilegible.
                (bool) ($leido['flota'] ?? false) => ($leido['sin_confirmar'] ?? false)
                    ? 'Anexo de póliza de flota sin fechas, y no se pudo confirmar si ampara este equipo: míralo en el visor'
                    : 'Anexo de póliza de flota: ampara este equipo, pero no se encontró la fecha de la firma (última página)',
                (bool) $idSeguro                  => 'Se reconocio la aseguradora, pero no la vigencia de la poliza',
                default                           => 'No se encontro la aseguradora ni la vigencia en el documento',
            }, [], $leido];
        }
        if ($anterior = $this->documentoAnterior($f->FECHA_VENC_POLIZA, $leido)) return $anterior;
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
        $this->compararFecha($dif, 'FECHA_EMISION_POLIZA', 'Fecha de emisión', $f->FECHA_EMISION_POLIZA, $leido['emision'] ?? null);

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
        // numero o se nombra a si mismo: "ROTC", "R.O.T.C." (la hoja de presentacion) o
        // "Registro de Operadoras de Transporte de Carga" (Drive a veces devuelve solo esa parte).
        if (empty($leido['nro']) && !preg_match('/\bR\.?\s?O\.?\s?T\.?\s?C\b|Operadoras\s+de\s+Transporte\s+de\s+Carga/iu', $textoRotc)) {
            return [VerificacionDocumento::ILEGIBLE,
                'El documento enlazado no parece un ROTC (no trae el numero ni se nombra)', [], $leido];
        }
        if (!$leido['vence'] && !$leido['emision']) {
            return [VerificacionDocumento::ILEGIBLE, 'No se encontraron las fechas del ROTC en el documento', [], $leido];
        }
        // Su serial esta en la tabla pero con OTRA placa (ver filaRotc): una de las dos esta mal
        // y la placa nunca la cambia la tarea. Nada que aplicar: lo decide una persona.
        if (!empty($leido['placa_en_tabla'])) {
            return [VerificacionDocumento::DIFIERE,
                'La tabla del ROTC trae este serial con la placa ' . $leido['placa_en_tabla']
                    . ' y la ficha dice ' . $f->PLACA . ': revisar cuál es la buena', [], $leido];
        }
        if ($anterior = $this->documentoAnterior($f->FECHA_ROTC, $leido)) return $anterior;
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
        $this->compararFecha($dif, 'FECHA_EMISION_ROTC', 'Fecha de emisión', $f->FECHA_EMISION_ROTC, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $motivo ?: $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /**
     * RACDA: la providencia del MINEC, que es de la EMPRESA. Lo que dice de este equipo es si
     * su placa esta entre las unidades autorizadas; ademas da la fecha en que se emitio y
     * cuantos años vale, de donde sale el vencimiento que guarda la ficha.
     *
     * Un equipo que no aparece en la lista NO se arregla escribiendo en la ficha: o le falta el
     * tramite o tiene enlazada una providencia que no lo cubre. Por eso va a "revisar a mano".
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
        if ($anterior = $this->documentoAnterior($f->FECHA_RACDA, $leido)) return $anterior;
        $dif = [];
        $this->compararFecha($dif, 'FECHA_RACDA', 'Vencimiento', $f->FECHA_RACDA, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_RACDA', 'Fecha de emisión', $f->FECHA_EMISION_RACDA, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /**
     * El resultado de un PDF que vence bastante ANTES de lo que dice la ficha (el anterior, ver
     * VerificacionDocumento::documentoAnterior), o null si es el vigente. Sin diferencias: lo
     * que dice ese PDF ya no vale y no se propone ni se pone; queda para que alguien enlace
     * el vigente.
     */
    private function documentoAnterior(?string $venceFicha, array $leido): ?array
    {
        $motivo = VerificacionDocumento::documentoAnterior($venceFicha, $leido['vence'] ?? null);
        if (!$motivo) return null;
        $leido['doc_anterior'] = true;
        return [VerificacionDocumento::DIFIERE, $motivo, [], $leido];
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

        $q = match (true) {
            $rehacer || $equipo => DB::table('documentacion as d')
                ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
                ->whereNull('e.deleted_at')
                ->where("d.$col", 'like', '/storage/google/%'),
            // Los que se leyeron y salieron "no se pudo leer" o con error, agotados o no.
            (bool) $this->option('reintentar') => VerificacionDocumento::conEnlace($col)
                ->whereExists(fn ($s) => $s->from('verificacion_documento_registro as v')
                    ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')
                    ->where('v.TIPO', $tipo)
                    ->whereIn('v.ESTADO', [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR])),
            default => VerificacionDocumento::pendientes($tipo, $col),
        };

        [$parte, $de] = $this->reparto();
        return $q->when($equipo, fn ($q) => $q->where('d.ID_EQUIPO', (int) $equipo))
            ->when($de > 1, fn ($q) => $q->whereRaw('d.ID_EQUIPO % ? = ?', [$de, $parte]))
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
