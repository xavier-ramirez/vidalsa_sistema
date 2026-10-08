<?php

namespace App\Console\Commands;

use App\Models\CatalogoSeguro;
use App\Models\DocumentoAnexo;
use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Services\GoogleDriveService;
use App\Services\LectorDocumentoPdf;
use App\Services\LectorGemini;
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
 *   · ROTC                : vencimiento, fecha de emision y numero de ROTC (su nombre es el
 *                           de la operadora, no el propietario: no se compara).
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
                            {--de=1 : En cuantas partes se reparte la cola, para leer con varios procesos a la vez}
                            {--sin-ia : No pedirle ayuda a la IA con los que queden ilegibles}';

    protected $description = 'Compara los documentos de cada ficha (titulo, poliza, ROTC y RACDA) con lo que dicen sus PDF.';

    /** De que hora a que hora la corre el programador (hora de la app). UNICO sitio: lo leen routes/console.php y el panel. */
    public const HORARIO = ['20:00', '00:00'];

    /** Cache: cuando se pulso "Revisar ahora" en el panel. */
    private const AHORA = 'docs_verificar_ahora';

    /** Cache: de que documentos es ese "Revisar ahora", separados por comas ('' = todos). */
    private const AHORA_TIPO = 'docs_verificar_ahora_tipo';

    /** Cuanto vale un "Revisar ahora": de sobra para leerlo todo (~30 documentos/minuto). */
    private const AHORA_HORAS = 12;

    /** ¿Le toca leer al programador? En su franja, o si alguien pidio "Revisar ahora". */
    public static function tocaLeer(): bool
    {
        return self::enFranja() || self::pedidaAhora() !== null;
    }

    /** ¿Estamos dentro de HORARIO? */
    private static function enFranja(): bool
    {
        [$desde, $hasta] = self::HORARIO;
        $hora = now()->format('H:i');
        // La franja termina a medianoche (20:00-00:00) o la cruza: vale de las 20:00 en adelante o antes del final.
        return $desde <= $hasta ? ($hora >= $desde && $hora < $hasta) : ($hora >= $desde || $hora < $hasta);
    }

    /**
     * "Revisar ahora" (botones del panel): la lectura arranca en el minuto siguiente, SIN
     * IMPORTAR LA HORA, y en esa pasada se vuelve a leer tambien lo que salio "No se pudo
     * leer" (VerificacionDocumento::inicioDeLaNoche cuenta desde aqui). Sigue hasta leerlo
     * todo: en cuanto no queda nada, el programador ya no lanza ningun proceso
     * (VerificacionDocumento::hayTrabajo). Se puede volver a pulsar cuando se quiera.
     *
     * Hay un boton por documento ($tipo: propiedad, poliza, rotc o racda) y otro para todos
     * (null). Los pedidos SE SUMAN mientras siga en curso el anterior: pulsar Titulo y luego
     * Poliza lee los dos, no solo el ultimo; y si ya estaban pedidos todos, siguen todos.
     */
    public static function pedirAhora(?string $tipo = null): void
    {
        $antes = self::pedidaAhoraTipos();
        $tipos = ($tipo === null || (self::pedidaAhora() !== null && $antes === null))
            ? null
            : array_values(array_unique(array_merge($antes ?? [], [$tipo])));

        $hasta = now()->addHours(self::AHORA_HORAS);
        Cache::put(self::AHORA, now()->toDateTimeString(), $hasta);
        Cache::put(self::AHORA_TIPO, $tipos === null ? '' : implode(',', $tipos), $hasta);
    }

    /** Cuando se pidio "Revisar ahora" (si sigue vigente), o null. */
    public static function pedidaAhora(): ?string
    {
        return Cache::get(self::AHORA);
    }

    /**
     * Los documentos pedidos con "Revisar ahora", en el orden de la revision, o null si se
     * pidieron todos o no hay pedido en curso. Un pedido de antes de que hubiera un boton por
     * documento no guardo ninguno: cuenta como todos.
     */
    public static function pedidaAhoraTipos(): ?array
    {
        if (self::pedidaAhora() === null) return null;
        $pedidos = explode(',', (string) Cache::get(self::AHORA_TIPO, ''));
        $tipos = array_values(array_filter(array_keys(self::ENLACES), fn ($t) => in_array($t, $pedidos, true)));
        return $tipos ?: null;
    }

    /**
     * Los documentos que toca leer AHORA. En la franja, todos, como siempre. Fuera de ella solo
     * corre por "Revisar ahora", y entonces solo los documentos de los botones pulsados: un
     * pedido de polizas no se pone a leer titulos. Lo usan el comando y
     * VerificacionDocumento::hayTrabajo, asi el programador no lanza procesos por lo que no se pidio.
     */
    public static function tiposDeAhora(): array
    {
        if (self::enFranja()) return array_keys(self::ENLACES);
        return self::pedidaAhoraTipos() ?? array_keys(self::ENLACES);
    }

    /** [tipo => columna del enlace]. El ORDEN es el de la revision (ver VerificacionDocumento). */
    private const ENLACES = VerificacionDocumento::ENLACES;

    public function __construct(private CorrectorFichaDocumento $corrector, private LectorGemini $ia)
    {
        parent::__construct();
    }

    public function handle(LectorDocumentoPdf $lector): int
    {
        $tipos = $this->option('tipo') ? [$this->option('tipo')] : self::tiposDeAhora();
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
        if (!$this->option('no-rellenar')) {
            if (in_array(VerificacionDocumento::PROPIEDAD, $tipos, true)) $this->numerosDeTituloYaLeidos($this->option('equipo'));
            $this->rellenarLoYaLeido($tipos, $this->option('equipo'));
        }

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

        // Lo que sobre de la tanda: la IA por los titulos ya leidos que aun no paso.
        if (in_array(VerificacionDocumento::PROPIEDAD, $tipos, true) && !$this->option('sin-ia')) {
            $hechos += $this->iaEnTitulosYaLeidos($lector, $lote - $hechos);
        }

        if ($hechos === 0) $this->info('No queda ningun documento por revisar.');
        return self::SUCCESS;
    }

    /**
     * Los TITULOS ya leidos que aun no paso la IA (entra siempre en los titulos, pedido del
     * cliente 07-10-2026): se le da el PDF y lo que vea distinto queda para una persona. SIN volver
     * a leerlos ni tocar la ficha: releerlos con el OCR volvia a escribir en las fichas lo que el
     * escaneo leyera mal (simulado el 07-10-2026 con los 774: "3021" por "2021", "ROSS
     * CONSTRUCTORA..." por "CONSTRUCTORA..."). El texto se pide solo para contrastar (contrasteIa).
     */
    private function iaEnTitulosYaLeidos(LectorDocumentoPdf $lector, int $cuantos): int
    {
        if ($cuantos < 1) return 0;
        [$parte, $de] = $this->reparto();
        $hechos = 0;
        $regs = VerificacionDocumento::titulosSinIa()
            ->when($this->option('equipo'), fn ($q, $e) => $q->where('verificacion_documento_registro.ID_EQUIPO', (int) $e))
            ->when($de > 1, fn ($q) => $q->whereRaw('verificacion_documento_registro.ID_EQUIPO % ? = ?', [$de, $parte]))
            ->limit($cuantos)->get();
        foreach ($regs as $reg) {
            if (!$this->ia->disponible() || $this->ia->restantesHoy() < 1) break;
            $f = DB::table('documentacion as d')->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
                ->where('d.ID_EQUIPO', $reg->ID_EQUIPO)
                ->first(['d.ID_EQUIPO', 'd.PLACA', 'd.NOMBRE_DEL_TITULAR', 'd.FECHA_EMISION_PROPIEDAD', 'd.NRO_DE_DOCUMENTO', 'e.SERIAL_CHASIS']);
            if (!$f) continue;
            try {
                $texto = $lector->texto($reg->DRIVE_ID);
            } catch (\Throwable $e) {
                continue;   // sin el texto no se puede contrastar: otra noche
            }
            if (!($visto = $this->leerConIa($reg->DRIVE_ID, $lector))) continue;
            $leido = ['ia' => $visto] + ($reg->LEIDO ?? []);
            $cambios = ['LEIDO' => $leido];
            if ($contra = $this->contrasteIa($f, $visto, $texto, $reg->LEIDO ?? [], $lector)) {
                $cambios = [
                    'LEIDO'       => $leido + ['revisar_ia' => true],
                    'DIFERENCIAS' => ($reg->DIFERENCIAS ?? []) + $contra,
                    'ESTADO'      => VerificacionDocumento::DIFIERE,
                    'A_MANO'      => true,
                    'MOTIVO'      => mb_substr(trim(($reg->MOTIVO ? $reg->MOTIVO . '. ' : '') . 'La IA lee distinto: '
                        . implode(', ', array_column($contra, 'etiqueta'))), 0, 255),
                ];
            }
            $reg->update($cambios);
            $this->line(sprintf('%-9s %-10s %-11s %s', $reg->ID_EQUIPO, 'ia-titulo', $f->PLACA ?: '—',
                $contra ? 'para revisar: ' . implode(', ', array_column($contra, 'etiqueta')) : 'la IA lee lo mismo'));
            $hechos++;
        }
        return $hechos;
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

    /**
     * La diferencia del número del título, SOLO si la ficha no tiene ninguno y el documento sí
     * (pedido del cliente, 01-10-2026). Uno ya escrito no se toca ni se compara: lo puso una
     * persona al subir el título. La usan la lectura de un título y el relleno de lo ya leído.
     */
    private static function numeroParaFichaVacia(?string $enFicha, array $leido): ?array
    {
        if (trim((string) $enFicha) !== '' || empty($leido['nro'])) return null;
        return ['etiqueta' => 'Nro. de documento', 'ficha' => null, 'documento' => (string) $leido['nro']];
    }

    /**
     * Títulos YA LEÍDOS de fichas sin número, leídos antes de que la tarea supiera poner el
     * número: se pone ahora con lo que esa lectura guardó, sin volver a Drive. Sin esto no se
     * rellenarían nunca, porque lo que coincide no se relee. Cuáles son y con qué condiciones
     * lo dice VerificacionDocumento::scopeNumeroDeTituloPorPoner; lo escribe el corrector, como
     * todo, y solo con la ficha todavía vacía.
     */
    private function numerosDeTituloYaLeidos($equipo = null): void
    {
        $puestos = 0;
        [$parte, $de] = $this->reparto();
        VerificacionDocumento::numeroDeTituloPorPoner()
            ->when($equipo, fn ($q) => $q->where('ID_EQUIPO', (int) $equipo))
            ->when($de > 1, fn ($q) => $q->whereRaw('ID_EQUIPO % ? = ?', [$de, $parte]))
            ->orderBy('ID_REGISTRO')->chunkById(100, function ($filas) use (&$puestos) {
                foreach ($filas as $reg) {
                    $nro = self::numeroParaFichaVacia(null, $reg->LEIDO ?? []);
                    if (!$nro) continue;
                    // Quién la revisó y cuándo: el corrector reescribe el motivo; la revisión de una
                    // PERSONA (APLICADO_POR; el corrector no lo toca) tiene que seguir constando, con
                    // lo que se puso solo detrás. Una fila que nadie reviso se queda con lo del corrector.
                    [$motivo, $cuando, $persona] = [trim((string) $reg->MOTIVO), $reg->APLICADO_EN, $reg->APLICADO_POR];
                    $reg->update(['ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => false, 'DIFERENCIAS' => ['NRO_DE_DOCUMENTO' => $nro]]);
                    if ($this->corrector->aplicar($reg->refresh())['puestos'] ?? []) {
                        $puestos++;
                        if ($persona && $motivo !== '') {
                            $reg->refresh()->update([
                                'MOTIVO'      => mb_substr($motivo . ' · Se puso solo: ' . mb_strtolower($nro['etiqueta']), 0, 255),
                                'APLICADO_EN' => $cuando,
                            ]);
                        }
                    }
                }
            }, 'ID_REGISTRO');

        if ($puestos) $this->info("Se puso el número del título en $puestos fichas con lo ya leído (sin volver a Drive).");
    }

    private function procesar(string $tipo, object $f, LectorDocumentoPdf $lector, array $catalogo): void
    {
        $driveId = DocumentoAnexo::driveIdDeLink($f->LINK);
        // Una carga masiva repartida A MEDIAS (un RACDA ya enlazado a la primera ficha y las
        // demas por aplicar) comparte clave con esta lectura (indice unico ID_EQUIPO, TIPO,
        // DRIVE_ID). Leerla ahora se quedaria con esa fila, dejaria de ser de la carga y el
        // resto de las fichas ya no se podria aplicar. Se lee cuando la carga se cierre.
        if ($driveId && VerificacionDocumento::deCargaMasiva()->where('ID_EQUIPO', $f->ID_EQUIPO)
                ->where('TIPO', $tipo)->where('DRIVE_ID', $driveId)
                ->whereIn('ESTADO', VerificacionDocumento::DE_LA_CARGA)->exists()) {
            return;
        }
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
                        VerificacionDocumento::ROTC   => $this->revisarRotc($f, $leido, $texto),
                        VerificacionDocumento::RACDA  => $this->revisarRacda($f, $leido, $lector),
                        default                       => $this->revisarPropiedad($f, $leido, $lector),
                    };
                    if (($leido['sin_confirmar'] ?? false) && $estado === VerificacionDocumento::DIFIERE) {
                        $motivo = 'No se pudo confirmar que el documento sea de este vehículo (no se leyó placa ni serial). ' . $motivo;
                    }
                    // Un documento que vence, sin fecha en la ficha y que tampoco la deja leer, no
                    // "coincide": le falta el dato que vigila la app. Queda para que una persona la
                    // ponga en el visor (asi lo enlaza la carga masiva: ENLAZAR_Y_REVISAR). Sin esto,
                    // leerlo de noche borraba esa marca de "para revisar".
                    $colVence = VerificacionDocumento::CAMPO_VENCE[$tipo] ?? null;
                    if (in_array($estado, [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE], true)
                        && $colVence && empty($f->$colVence) && empty($leido['vence'])) {
                        $leido['sin_fecha'] = true;
                        $motivo = trim(VerificacionDocumento::MOTIVO_SIN_FECHA . ' '
                            . ($estado === VerificacionDocumento::DIFIERE ? $motivo : ''));
                        $estado = VerificacionDocumento::DIFIERE;
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

        // ── Apoyo de la IA: en lo duro y, en los TITULOS, siempre ─────────────────
        // Lo que quedo "No se pudo leer" es justo lo que nadie va a resolver solo: escaneos
        // torcidos y formatos que las reglas no conocen. Ahi (y solo ahi) se le da el PDF
        // entero a Gemini. Lo que devuelve NO se aplica: se guarda aparte y la fila queda
        // "para revisar", para que una persona lo confirme mirando el documento.
        //
        // UNA VEZ POR ARCHIVO: un ilegible se relee 3 veces esa noche y vuelve a la cola cada
        // noche. Sin esta puerta, los mismos documentos se comerian el cupo diario entero
        // todas las noches y el resto no llegaria nunca a pasar por la IA. Lo ya preguntado
        // se reconoce porque su lectura guardada trae la clave 'ia' (aunque venga vacia).
        //
        // TITULOS: la IA entra SIEMPRE (pedido del cliente 07-10-2026), no solo en lo ilegible:
        // el OCR los daba todos por "coincide" sin mirar bien serial, placa ni numero. Lo que vea
        // distinto se contrasta con el texto (ver contrasteIa) y queda para una persona. Lo ya
        // preguntado de ese mismo archivo se reutiliza: el cupo diario no da para preguntar dos veces.
        $esTitulo = $tipo === VerificacionDocumento::PROPIEDAD
            && in_array($estado, [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE], true)
            && empty($leido['otra_placa']);
        if ($esTitulo && !$this->option('sin-ia') && $driveId) {
            $visto = $this->iaYaLeida($f->ID_EQUIPO, $tipo, $driveId) ?? $this->leerConIa($driveId, $lector);
            if ($visto) {
                $leido['ia'] = $visto;
                if ($contra = $this->contrasteIa($f, $visto, $texto, $leido, $lector)) {
                    $diferencias += $contra;
                    $leido['revisar_ia'] = true;
                    $estado = VerificacionDocumento::DIFIERE;
                    $motivo = mb_substr(trim(($motivo ? $motivo . '. ' : '') . 'La IA lee distinto: '
                        . implode(', ', array_column($contra, 'etiqueta'))), 0, 255);
                }
            }
        } elseif ($estado === VerificacionDocumento::ILEGIBLE && !$this->option('sin-ia') && $driveId
            && !$this->yaPasoPorLaIa($f->ID_EQUIPO, $tipo, $driveId)) {
            if ($visto = $this->leerConIa($driveId, $lector)) {
                $leido['ia'] = $visto;
                // El resumen solo si saco ALGO: si tampoco pudo, la fila se queda con su
                // motivo de siempre y la marca sirve para no volver a preguntarle.
                if ($resumen = $this->resumenIa($visto)) {
                    $motivo = mb_substr(trim(($motivo ? $motivo . '. ' : '') . 'La IA leyo: ' . $resumen), 0, 255);
                }
            }
        }

        // Si una persona ya habia revisado este documento (se relee por "Revisar ahora", ver
        // VerificacionDocumento::condicionLeido), su decision se respeta: de esta lectura solo se
        // ponen las fechas VACIAS y la fila vuelve a quedar como ella la dejo.
        // SOLO las de la noche: una fila de la carga masiva ya aplicada tambien lleva
        // APLICADO_POR (quien la enlazo o la ato), pero eso no es revisar el documento. Tomarla por
        // una revision tapaba para siempre lo que esta lectura encontrara en el PDF recien
        // enlazado ("es de otro vehiculo", la aseguradora, un vencimiento distinto).
        $revision = VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
            ->where('DRIVE_ID', $driveId)->where('ORIGEN', VerificacionDocumento::DE_LA_NOCHE)
            ->whereNotNull('APLICADO_POR')
            ->first(['ESTADO', 'A_MANO', 'DIFERENCIAS', 'MOTIVO', 'APLICADO_POR', 'APLICADO_EN']);

        $reg = VerificacionDocumento::updateOrCreate(
            ['ID_EQUIPO' => $f->ID_EQUIPO, 'TIPO' => $tipo, 'DRIVE_ID' => $driveId],
            [
                'PLACA'  => $f->PLACA,
                'SERIAL' => $f->SERIAL_CHASIS,
                // Esta fila la escribe la tarea de la noche. Si el documento llego por la
                // carga masiva y ya se aplico, deja de ser una propuesta y pasa a ser una
                // lectura normal: de aqui en adelante la gobierna la noche.
                'ORIGEN' => VerificacionDocumento::DE_LA_NOCHE,
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
                    && (VerificacionDocumento::leidoParaRevisar($leido)
                        // Sin fecha y sin nada mas: nadie la pondria. Con otras diferencias se
                        // deja al corrector ponerlas; el marca la fila al terminar (sinFecha).
                        || (($leido['sin_fecha'] ?? false) && !$diferencias)),
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
        //
        // SALVO las de la carga masiva que siguen ESPERANDO: esas son PDF que alguien acaba de
        // soltar y que todavia no estan en ninguna ficha. Son propuestas esperando a enlazarse,
        // no lecturas viejas de este documento; borrarlas aqui las haria desaparecer de la tabla
        // sin que nadie las viera.
        //
        // Las que YA se aplicaron si se van: su PDF era el de esta casilla y ha quedado
        // reemplazado por el que se acaba de leer, asi que esa fila habla de un archivo que ya
        // no esta enlazado. Sin esto se quedaban para siempre diciendo "Aplicado".
        $retirar = VerificacionDocumento::where('ID_EQUIPO', $f->ID_EQUIPO)->where('TIPO', $tipo)
            ->where(fn ($q) => $q->where('ORIGEN', VerificacionDocumento::DE_LA_NOCHE)
                ->orWhere('ESTADO', VerificacionDocumento::APLICADO))
            ->where('ID_REGISTRO', '<>', $reg->ID_REGISTRO);
        // Un ROTC de flota ya repartido: ninguna ficha enlaza el original (cada una tiene SU
        // parte) y con esta fila se va lo unico que lo recordaba. A la papelera de Drive; el
        // job no toca un archivo que alguna fila siga enlazando (EnlacesDocumentos::sigueEnUso).
        (clone $retirar)->deCargaMasiva()->get(['DRIVE_ID', 'PROPUESTA'])
            ->filter(fn ($r) => !empty($r->PROPUESTA['flota_rotc']) && $r->DRIVE_ID)
            ->each(fn ($r) => \App\Services\GoogleDriveService::borrarTrasResponder($r->DRIVE_ID));
        $retirar->delete();

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
        // Y tampoco que el PDF es de OTRO vehiculo: eso no se da por revisado (ver
        // CompresionPdfController::OTRO_VEHICULO); un "Revisado" de antes lo escondia para siempre.
        if ($revision && in_array($estado, [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE], true)
            && empty($leido['otra_placa'])) {
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
        if ($nro = self::numeroParaFichaVacia($f->NRO_DE_DOCUMENTO ?? null, $leido)) $dif['NRO_DE_DOCUMENTO'] = $nro;

        // El serial y la placa del TITULO frente a los de la ficha (pedido 05-10-2026). La tarea
        // NUNCA los cambia (no estan en CorrectorFichaDocumento::CAMPOS: son con lo que se sabe de
        // que vehiculo es el PDF): el corrector pone lo demas y los deja a ellos para que una
        // persona elija en el visor cual es el bueno. Un serial leido de menos de 8 caracteres no
        // se compara: casi siempre es una lectura a medias.
        $serialLeido = (string) ($leido['serial'] ?? '');
        $identidad = [
            'SERIAL_CHASIS' => ['Serial de chasis', $f->SERIAL_CHASIS, strlen($lector->codigo($serialLeido)) >= 8 ? $serialLeido : null],
            'PLACA'         => ['Placa', $f->PLACA, $leido['placa'] ?? null],
        ];
        foreach ($identidad as $campo => [$etiqueta, $enFicha, $enDocumento]) {
            if ($enFicha && $enDocumento && $lector->codigo($enFicha) !== $lector->codigo($enDocumento)) {
                $dif[$campo] = ['etiqueta' => $etiqueta, 'ficha' => $enFicha, 'documento' => $enDocumento];
            }
        }

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
        if ($anterior = $this->documentoAnterior($f->FECHA_VENC_POLIZA, $f->FECHA_EMISION_POLIZA, $leido)) return $anterior;
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
     * ROTC: el certificado de circulacion de carga. Trae la operadora, las dos fechas y su
     * numero. La ficha guarda como FECHA_ROTC la de VENCIMIENTO (es la que vigilan las alertas).
     */
    private function revisarRotc(object $f, array $leido, string $textoRotc): array
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
        if ($anterior = $this->documentoAnterior($f->FECHA_ROTC, $f->FECHA_EMISION_ROTC, $leido)) return $anterior;
        // El nombre del ROTC ("Razon Social") es la OPERADORA —siempre la empresa—, no el
        // PROPIETARIO del vehiculo, que es lo que guarda NOMBRE_DEL_TITULAR (sale del titulo). No
        // se comparan: en un vehiculo de otro dueño operado por la empresa salia "otro nombre" y
        // el corrector le ponia la empresa como dueña (visto el 05-10-2026 en 7 fichas: CORPO NAC
        // DE LOGISTICA, ALVARO MARTINEZ, 1000 MILLAS).
        $dif = [];
        $this->compararFecha($dif, 'FECHA_ROTC', 'Vencimiento', $f->FECHA_ROTC, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_ROTC', 'Fecha de emisión', $f->FECHA_EMISION_ROTC, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $this->motivoDe($dif), $dif, $leido]
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
        if ($anterior = $this->documentoAnterior($f->FECHA_RACDA, $f->FECHA_EMISION_RACDA, $leido)) return $anterior;
        $dif = [];
        $this->compararFecha($dif, 'FECHA_RACDA', 'Vencimiento', $f->FECHA_RACDA, $leido['vence'] ?? null);
        $this->compararFecha($dif, 'FECHA_EMISION_RACDA', 'Fecha de emisión', $f->FECHA_EMISION_RACDA, $leido['emision'] ?? null);

        return $dif
            ? [VerificacionDocumento::DIFIERE, $this->motivoDe($dif), $dif, $leido]
            : [VerificacionDocumento::COINCIDE, null, [], $leido];
    }

    /**
     * El resultado de un PDF que vence bastante ANTES de lo que dice la ficha (el anterior, ver
     * VerificacionDocumento::documentoAnterior, que tambien mira cuando empieza), o null si es
     * el vigente. Sin diferencias: lo que dice ese PDF ya no vale y no se propone ni se pone;
     * queda para que alguien enlace el vigente.
     */
    private function documentoAnterior(?string $venceFicha, ?string $emisionFicha, array $leido): ?array
    {
        $motivo = VerificacionDocumento::documentoAnterior($venceFicha, $leido['vence'] ?? null,
            $emisionFicha, VerificacionDocumento::inicioDelDocumento($leido));
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

    // ── Apoyo de la IA ────────────────────────────────────────────────────────────

    /**
     * Baja el PDF de Drive y se lo da entero a la IA (el mismo modelo que la carga masiva, ver
     * LectorGemini). Devuelve lo que vio, o null si no hay clave, no queda cupo o fallo algo:
     * en ese caso la revision sigue exactamente como antes de que existiera esto.
     */
    private function leerConIa(string $driveId, LectorDocumentoPdf $lector): ?array
    {
        if (!$this->ia->disponible() || $this->ia->restantesHoy() < 1) {
            return null;
        }
        try {
            $pdf = $lector->pdf($driveId);
        } catch (\Throwable $e) {
            Log::warning('docs:verificar-documentos: no se pudo bajar el PDF para la IA ' . $driveId . ': ' . $e->getMessage());
            return null;
        }

        return $this->ia->leer($pdf);
    }

    /**
     * Lo que vio la IA, en una linea, para el MOTIVO que lee la persona en el panel. Cadena
     * vacia si tampoco saco nada: entonces no hay nada que contarle a nadie.
     */
    private function resumenIa(array $visto): string
    {
        $partes = array_filter([
            $visto['placa'] ? 'placa ' . $visto['placa'] : null,
            $visto['serial'] ? 'serial ' . $visto['serial'] : null,
            $visto['nro'] ? 'nro ' . $visto['nro'] : null,
            $visto['emision'] ? 'emitido ' . $visto['emision'] : null,
            $visto['vence'] ? 'vence ' . $visto['vence'] : null,
            $visto['vehiculos'] ? count($visto['vehiculos']) . ' vehiculos' : null,
        ]);

        return $partes ? implode(', ', $partes) . ' (confirmalo)' : '';
    }

    /** ¿A este archivo ya se le pregunto a la IA? (su lectura guardada trae la clave 'ia'). */
    private function yaPasoPorLaIa(int $idEquipo, string $tipo, string $driveId): bool
    {
        $leido = VerificacionDocumento::where('ID_EQUIPO', $idEquipo)->where('TIPO', $tipo)
            ->where('DRIVE_ID', $driveId)->value('LEIDO');

        return is_array($leido) && array_key_exists('ia', $leido);
    }

    /** Lo que la IA ya leyo de ESE archivo en una pasada anterior (null si nunca se le pregunto). */
    private function iaYaLeida(int $idEquipo, string $tipo, string $driveId): ?array
    {
        $leido = VerificacionDocumento::where('ID_EQUIPO', $idEquipo)->where('TIPO', $tipo)
            ->where('DRIVE_ID', $driveId)->value('LEIDO');

        return is_array($leido) && is_array($leido['ia'] ?? null) ? $leido['ia'] : null;
    }

    /**
     * Lo que la IA lee en un TITULO distinto de la ficha: placa, serial, numero, emision y
     * propietario. Solo cuenta si el OCR tampoco respalda a la ficha (su valor no esta en el
     * texto, o la fecha leida no es la suya): si el texto lo trae tal cual, la errata es de la IA.
     * Claves IA_*: no estan en CorrectorFichaDocumento::CAMPOS, asi que NUNCA se aplican solas.
     */
    private function contrasteIa(object $f, array $ia, string $texto, array $leido, LectorDocumentoPdf $lector): array
    {
        $plano = $lector->codigo($texto);
        $enTexto = fn (?string $v) => $v !== null && $v !== '' && str_contains($plano, $lector->codigo($v));
        $digitos = fn (?string $v) => preg_replace('/\D/', '', (string) $v);
        $fechaFicha = $f->FECHA_EMISION_PROPIEDAD ? substr((string) $f->FECHA_EMISION_PROPIEDAD, 0, 10) : null;

        $campos = [
            'PLACA'              => ['Placa', $f->PLACA, $ia['placa'] ?? null,
                fn ($a, $b) => $lector->codigo($a) === $lector->codigo($b) || $enTexto($a)],
            'SERIAL_CHASIS'      => ['Serial de chasis', $f->SERIAL_CHASIS, $ia['serial'] ?? null,
                fn ($a, $b) => $lector->codigo($a) === $lector->codigo($b) || $enTexto($a)],
            'NRO_DE_DOCUMENTO'   => ['Nro. de documento', $f->NRO_DE_DOCUMENTO, $ia['nro'] ?? null,
                fn ($a, $b) => $digitos($a) === $digitos($b) || str_contains($digitos($texto), $digitos($a))],
            'FECHA_EMISION_PROPIEDAD' => ['Fecha de emisión', $fechaFicha, $ia['emision'] ?? null,
                fn ($a, $b) => $a === $b || $a === ($leido['emision'] ?? null)],
            'NOMBRE_DEL_TITULAR' => ['Propietario', $f->NOMBRE_DEL_TITULAR, $ia['titular'] ?? null,
                fn ($a, $b) => $lector->compararNombre($a, $b)[0] || $enTexto($a)],
        ];
        $dif = [];
        foreach ($campos as $campo => [$etiqueta, $enFicha, $dice, $iguales]) {
            // "N/A" no es una lectura: es la casilla vacia del formulario (Serial Carroceria: N/A).
            $vacio = fn ($v) => in_array($lector->normalizar($v), ['', 'N A', 'NA', 'NO APLICA'], true);
            if ($vacio($enFicha) || $vacio($dice) || $iguales((string) $enFicha, (string) $dice)) continue;
            $dif['IA_' . $campo] = ['etiqueta' => $etiqueta . ' (según la IA)', 'ficha' => $enFicha, 'documento' => $dice];
        }
        return $dif;
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
                'd.ID_EQUIPO', 'd.PLACA', 'd.NOMBRE_DEL_TITULAR', 'd.FECHA_EMISION_PROPIEDAD', 'd.NRO_DE_DOCUMENTO',
                'd.ID_SEGURO', 'd.FECHA_VENC_POLIZA', 'd.FECHA_EMISION_POLIZA',
                'd.FECHA_ROTC', 'd.FECHA_EMISION_ROTC', 'd.FECHA_RACDA', 'd.FECHA_EMISION_RACDA',
                'e.SERIAL_CHASIS', DB::raw("d.$col as LINK"),
            ]);
    }
}
