<?php

namespace App\Models;

use App\Services\LectorDocumentoPdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Una fila por equipo, tipo de documento y archivo que docs:verificar-documentos ya leyo.
 * Ver la migracion create_verificacion_documento_registro_table.
 */
class VerificacionDocumento extends Model
{
    protected $table = 'verificacion_documento_registro';
    protected $primaryKey = 'ID_REGISTRO';

    /** Tipos y estados viven en el lector, que es quien decide cual poner. */
    public const PROPIEDAD   = LectorDocumentoPdf::PROPIEDAD;
    public const POLIZA      = LectorDocumentoPdf::POLIZA;
    public const ROTC        = LectorDocumentoPdf::ROTC;
    public const RACDA       = LectorDocumentoPdf::RACDA;
    public const COINCIDE    = LectorDocumentoPdf::COINCIDE;
    public const DIFIERE     = LectorDocumentoPdf::DIFIERE;
    public const ILEGIBLE    = LectorDocumentoPdf::ILEGIBLE;
    public const SIN_ARCHIVO = LectorDocumentoPdf::SIN_ARCHIVO;
    public const ERROR       = LectorDocumentoPdf::ERROR;

    /** Los que pide revisar una persona: no se pudo leer, no hay archivo o fallo. */
    public const A_REVISAR = [self::ILEGIBLE, self::SIN_ARCHIVO, self::ERROR];

    /**
     * Cuantas veces seguidas, DENTRO de una misma noche, se reintenta un documento que salio
     * ilegible o con error (Drive devuelve de vez en cuando el documento vacio). Agotados,
     * esa noche ya no se vuelve a leer —asi la tarea termina y se apaga—, pero la noche
     * siguiente SI: cada "No se pudo leer" se vuelve a revisar una vez por noche (ver
     * pendientes() e inicioDeLaNoche()), por si el lector mejoro o se subio otro archivo.
     */
    public const MAX_INTENTOS = 3;

    /** Como se llama cada documento en la pantalla, en el orden en que se revisan. */
    public const NOMBRES = [
        self::PROPIEDAD => 'Título de propiedad',
        self::POLIZA    => 'Póliza',
        self::ROTC      => 'ROTC',
        self::RACDA     => 'RACDA',
    ];

    /** [tipo => columna del enlace en documentacion]. Mismo orden que NOMBRES. */
    public const ENLACES = [
        self::PROPIEDAD => 'LINK_DOC_PROPIEDAD',
        self::POLIZA    => 'LINK_POLIZA_SEGURO',
        self::ROTC      => 'LINK_ROTC',
        self::RACDA     => 'LINK_RACDA',
    ];

    /** El vencimiento de cada documento en la ficha: dice si un PDF es el vigente o uno anterior. */
    public const CAMPO_VENCE = [
        self::POLIZA => 'FECHA_VENC_POLIZA',
        self::ROTC   => 'FECHA_ROTC',
        self::RACDA  => 'FECHA_RACDA',
    ];

    /** La fecha de emision (de origen) de cada documento en la ficha. */
    public const CAMPO_EMISION = [
        self::PROPIEDAD => 'FECHA_EMISION_PROPIEDAD',
        self::POLIZA    => 'FECHA_EMISION_POLIZA',
        self::ROTC      => 'FECHA_EMISION_ROTC',
        self::RACDA     => 'FECHA_EMISION_RACDA',
    ];

    /** Las fechas que trae un documento de $tipo: su emision y, si tiene, su vencimiento. */
    public static function camposDeFecha(string $tipo): array
    {
        return array_values(array_filter([self::CAMPO_EMISION[$tipo] ?? null, self::CAMPO_VENCE[$tipo] ?? null]));
    }

    /**
     * Cuantos dias antes que la ficha tiene que vencer un PDF para ser "el anterior". Una
     * renovacion mueve el vencimiento meses (el ROTC de flota paso del 11/02 al 03/07/2027; un
     * año las polizas); unos dias de diferencia son una ficha mal escrita que el documento
     * corrige (vence 05/12/2026 con emision 05/12/2025 frente a una ficha con 15/12/2026).
     */
    public const DIAS_ANTERIOR = 45;

    /**
     * Si el PDF vence bastante ANTES de lo que ya dice la ficha, es el documento ANTERIOR: se
     * renovo, se puso la fecha nueva en la ficha, pero el archivo enlazado sigue siendo el
     * viejo. Nada de ese PDF sirve para corregir la ficha (ni su vencimiento, ni su emision, ni
     * su aseguradora): "el documento manda" vale para el documento VIGENTE. Devuelve el motivo
     * que se muestra, o null si el PDF no es anterior. Lo usan el verificador, el corrector,
     * la migracion del 18-09-2026 y (en JS, con el mismo umbral) el visor.
     */
    public static function documentoAnterior(?string $venceFicha, ?string $venceDocumento): ?string
    {
        $ficha = $venceFicha ? substr($venceFicha, 0, 10) : null;
        if (!$ficha || !$venceDocumento
            || strtotime($ficha) - strtotime($venceDocumento) <= self::DIAS_ANTERIOR * 86400) return null;
        $fecha = fn (string $f) => implode('/', array_reverse(explode('-', $f)));
        return 'El PDF enlazado es el ANTERIOR: vence el ' . $fecha($venceDocumento) . ' y la ficha ya dice '
            . $fecha($ficha) . '. No se toma nada de él: enlaza el vigente.';
    }

    /** El PDF enlazado es uno anterior al que ya refleja la ficha (ver documentoAnterior). */
    public function esDocumentoAnterior(): bool
    {
        return (bool) ($this->LEIDO['doc_anterior'] ?? false);
    }

    protected $fillable = [
        'ID_EQUIPO', 'ID_AUXILIAR', 'TIPO', 'PLACA', 'SERIAL', 'DRIVE_ID', 'ARCHIVO',
        'LEIDO', 'PROPUESTA', 'DIFERENCIAS', 'ESTADO', 'ORIGEN', 'MOTIVO', 'CARACTERES',
        'INTENTOS', 'A_MANO', 'APLICADO_POR', 'APLICADO_EN',
    ];

    protected $casts = [
        'LEIDO'       => 'array',
        'PROPUESTA'   => 'array',
        'DIFERENCIAS' => 'array',
        'A_MANO'      => 'boolean',
        'APLICADO_EN' => 'datetime',
    ];

    // ── De donde sale la fila ─────────────────────────────────────────────────────

    /** La leyo la tarea de la noche a partir de un documento YA enlazado a una ficha. */
    public const DE_LA_NOCHE = 'noche';

    /** La solto una persona en la carga masiva y todavia no se ha aplicado a ninguna ficha. */
    public const DE_CARGA_MASIVA = 'carga_masiva';

    /**
     * Estado propio de la carga masiva: el PDF se leyo y hay ficha candidata, pero no quedo
     * enlazado del todo (a ninguna ficha, o solo a algunas de un RACDA/ROTC de flota). Lo
     * reintenta cada hora CargaMasivaDocumentos::enlazarLoPendiente; lo que pide una persona
     * (leido con IA, sin fecha) no se enlaza solo y se queda aqui.
     */
    public const POR_ENGANCHAR = 'por_enganchar';

    /** Se leyo pero no se reconocio de que equipo ni de que auxiliar es. */
    public const SIN_FICHA = 'sin_ficha';

    /**
     * Se cargo como un documento (p. ej. titulo) y el PDF es OTRO (una poliza, una factura...):
     * no se asocio a ninguna ficha. Se queda para que se vea que paso con ese archivo, y se
     * descarta o se vuelve a subir con el tipo correcto. La misma clave que
     * CargaMasivaDocumentos::OTRO_DOCUMENTO.
     */
    public const OTRO_DOCUMENTO = 'otro_documento';

    /**
     * Ya se enlazo a su ficha. La fila se queda para que se vea que paso con ese PDF; cuando
     * la tarea de la noche lo relea (ya es un documento de la ficha) la convertira en una
     * lectura suya, con su comparacion de verdad.
     */
    public const APLICADO = 'aplicado';

    /** Los estados de la carga masiva que todavia esperan a una persona. */
    public const DE_LA_CARGA = [self::POR_ENGANCHAR, self::SIN_FICHA, self::OTRO_DOCUMENTO];

    /** Todos los de la carga masiva, incluido el ya resuelto. Para el filtro de la pantalla. */
    public const DE_LA_CARGA_TODOS = [self::POR_ENGANCHAR, self::SIN_FICHA, self::OTRO_DOCUMENTO, self::APLICADO];

    /** Filas que vienen de la carga masiva, en cualquier estado (tambien las ya aplicadas). */
    public function scopeDeCargaMasiva($q)
    {
        return $q->where('ORIGEN', self::DE_CARGA_MASIVA);
    }

    /**
     * Lo que mira una persona: no se pudo leer, no hay archivo, fallo, la tarea no lo puede
     * poner sola, o es un PDF recien soltado en la carga masiva que espera que lo apliquen.
     */
    public function scopeParaRevisar($q)
    {
        return $q->where(fn ($w) => $w->whereIn('ESTADO', array_merge(self::A_REVISAR, self::DE_LA_CARGA))
            ->orWhere('A_MANO', true));
    }

    /** Lo que la tarea todavia puede poner sola: hay diferencias y el PDF es de este vehiculo. */
    public function scopeCorregibles($q)
    {
        return $q->where('ESTADO', self::DIFIERE)->where('A_MANO', false);
    }

    /**
     * Títulos YA LEÍDOS y dados por buenos ("coincide") de fichas SIN número, cuya lectura sí
     * lo trae: los rellena VerificarDocumentos::numerosDeTituloYaLeidos con lo guardado, sin
     * volver a Drive (pedido del cliente, 01-10-2026). Solo si la lectura es del archivo
     * enlazado AHORA y es fiable (no es de otro vehículo, no se leyó a medias, se confirmó la
     * placa o el serial). UNA definición: la usan esa tarea y hayTrabajo; si no, un título que
     * nunca da número haría arrancar la tarea cada minuto para nada.
     * JSON_UNQUOTE da el mismo texto en MySQL 8 (servidor) y MariaDB (local): 'null', 'true'.
     */
    public function scopeNumeroDeTituloPorPoner($q)
    {
        $json = fn (string $clave) => "JSON_UNQUOTE(JSON_EXTRACT(verificacion_documento_registro.LEIDO, '$.$clave'))";
        return $q->where('verificacion_documento_registro.TIPO', self::PROPIEDAD)
            ->where('verificacion_documento_registro.ORIGEN', self::DE_LA_NOCHE)
            ->where('verificacion_documento_registro.ESTADO', self::COINCIDE)
            ->whereRaw($json('nro') . " REGEXP '^[0-9]{12}$'")
            ->whereRaw('COALESCE(' . $json('otra_placa') . ", 'false') <> 'true'")
            ->whereRaw('COALESCE(' . $json('lectura_parcial') . ", 'false') <> 'true'")
            ->whereRaw('COALESCE(' . $json('sin_confirmar') . ", 'false') <> 'true'")
            ->whereExists(fn ($s) => $s->from('documentacion as d')
                ->whereColumn('d.ID_EQUIPO', 'verificacion_documento_registro.ID_EQUIPO')
                ->where(fn ($w) => $w->whereNull('d.NRO_DE_DOCUMENTO')->orWhere('d.NRO_DE_DOCUMENTO', ''))
                ->whereRaw("d.LINK_DOC_PROPIEDAD LIKE CONCAT('%/', verificacion_documento_registro.DRIVE_ID, '%')"));
    }

    /**
     * Documentos que el comando todavia tiene que leer, de un tipo. UNA sola definicion de la
     * cola: la usan el comando (para su lote), el panel (para "faltan por leer") y las pruebas.
     * Quedan fuera los enlaces que no apuntan a un archivo de Drive —no hay nada que leer— y
     * lo ya revisado, salvo lo ilegible o fallido mientras le queden intentos y, tras "Revisar
     * ahora", los "Datos distintos" (ver condicionLeido). Lo que coincide no se relee.
     */
    public static function pendientes(string $tipo, string $columna)
    {
        return self::conEnlace($columna)
            ->whereNotExists(fn ($s) => self::condicionLeido($s, $tipo, $columna));
    }

    /**
     * "Este documento YA se leyo", en SQL. UNICO sitio donde vive la regla: la usan
     * pendientes() (como NOT EXISTS) y avanceDe() (como EXISTS dentro de un SUM). Si
     * estuviera escrita dos veces, un dia dejarian de contar lo mismo.
     */
    private static function condicionLeido($q, string $tipo, string $columna)
    {
        $idEnlace = self::idEnlace($columna);
        $inicio   = self::inicioDeLaNoche();

        return $q->from('verificacion_documento_registro as v')
            ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')
            ->where('v.TIPO', $tipo)
            ->whereRaw("v.DRIVE_ID = $idEnlace")
            // "Leido" es lo que leyo ESTA tarea. Una fila de la carga masiva NO cuenta: ahi solo
            // dice que alguien solto ese PDF y lo enlazo, no que se haya comparado con la ficha.
            // Sin esto, un documento aplicado desde la carga masiva no se revisaba NUNCA (su fila
            // ya ocupaba el sitio) y ademas el avance lo daba por leido: la barra mentia.
            ->where('v.ORIGEN', self::DE_LA_NOCHE)
            // Ya leido de verdad, o "No se pudo leer" que agoto sus intentos ESTA noche.
            ->where(fn ($w) => $w->whereNotIn('v.ESTADO', [self::ILEGIBLE, self::ERROR])
                ->orWhere(fn ($x) => $x->where('v.INTENTOS', '>=', self::MAX_INTENTOS)
                    ->where('v.updated_at', '>=', $inicio)))
            // Y, si se pulso "Revisar ahora", se vuelven a leer UNA vez por pulsacion los "Datos
            // distintos" (los "No se pudo leer" ya van por la regla de arriba). Lo que ya COINCIDE
            // no se relee nunca con el boton, le falte o no una fecha (lo pidio el cliente,
            // 21-09-2026). Solo con el boton, no cada noche.
            ->when(\App\Console\Commands\VerificarDocumentos::pedidaAhora(), fn ($c, $pedida) => $c
                ->where(fn ($w) => $w->where('v.ESTADO', '<>', self::DIFIERE)
                    ->orWhere('v.updated_at', '>=', $pedida)));
    }

    /**
     * Cuantos documentos de $tipo hay cargados y cuantos faltan por leer, en UNA consulta.
     *
     * Antes eran dos (conEnlace()->count() y pendientes()->count()) y cada una repetia el
     * mismo join de documentacion con equipos: cuatro tipos = ocho consultas para pintar
     * cuatro barras de avance, ~58 ms medidos. Ahora son cuatro.
     */
    public static function avanceDe(string $tipo, string $columna): array
    {
        $sub = \Illuminate\Support\Facades\DB::query()->selectRaw('1');
        self::condicionLeido($sub, $tipo, $columna);

        $fila = self::conEnlace($columna)
            ->selectRaw(
                'COUNT(*) as total, SUM(CASE WHEN EXISTS (' . $sub->toSql() . ') THEN 0 ELSE 1 END) as faltan',
                $sub->getBindings()
            )
            ->first();

        return ['total' => (int) ($fila->total ?? 0), 'faltan' => (int) ($fila->faltan ?? 0)];
    }

    /**
     * Cuando empezo la franja de lectura en curso (o la ultima, si ahora no hay ninguna):
     * hoy a las VerificarDocumentos::HORARIO[0] si ya pasaron, si no ayer; o, si despues se
     * pulso "Revisar ahora", ese momento. Un "No se pudo leer" que no se ha vuelto a leer
     * desde entonces vuelve a la cola.
     */
    public static function inicioDeLaNoche(): string
    {
        $inicio = now()->setTimeFromTimeString(\App\Console\Commands\VerificarDocumentos::HORARIO[0]);
        if ($inicio->isFuture()) $inicio->subDay();
        return max($inicio->toDateTimeString(), (string) \App\Console\Commands\VerificarDocumentos::pedidaAhora());
    }

    /**
     * ¿Le queda algo a docs:verificar-documentos? Algo por leer (pendientes) o algo ya leido
     * que la tarea puede poner sola (corregibles). Lo mira el programador antes de lanzar los
     * lectores: sin trabajo no se arranca ningun proceso. Son consultas EXISTS, de milisegundos.
     */
    public static function hayTrabajo(): bool
    {
        if (self::corregibles()->exists()) return true;
        // Lo que coincide no se relee: sin esto, con todo leído la tarea ni arrancaría y el
        // número del título ya leído no se pondría nunca (ver scopeNumeroDeTituloPorPoner).
        if (self::numeroDeTituloPorPoner()->exists()) return true;
        foreach (self::ENLACES as $tipo => $columna) {
            if (self::pendientes($tipo, $columna)->exists()) return true;
        }
        return false;
    }

    /**
     * TODAS las fichas que tienen ese documento cargado en Drive, este leido o no. Es la misma
     * base que pendientes() —de ahi sale— y con las dos se sabe por donde va la revision:
     * "leidos X de Y". Sin esto, "faltan por leer" no dice si falta mucho o poco.
     */
    public static function conEnlace(string $columna)
    {
        return \Illuminate\Support\Facades\DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->whereNull('e.deleted_at')
            ->where("d.$columna", 'like', '/storage/google/%')
            ->whereRaw(self::idEnlace($columna) . " <> ''");
    }

    /** El id de Drive dentro del enlace guardado (/storage/google/ID?v=N), en SQL. */
    private static function idEnlace(string $columna): string
    {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX(d.$columna, '/storage/google/', -1), '?', 1)";
    }

    /**
     * ¿Se puede corregir la ficha con lo que dice este documento? Solo si hay diferencias que
     * aplicar y el documento es de ESTE vehiculo: cuando el PDF resulto ser de otra placa, lo
     * que hay que arreglar es el archivo enlazado, no copiar sus datos a esta ficha.
     */
    public function aplicable(): bool
    {
        return $this->ESTADO === self::DIFIERE
            && !empty($this->DIFERENCIAS)
            && !$this->A_MANO;
    }

    /** El documento se leyo a medias: lo que dice es MENOS que lo que tiene la ficha. */
    public function esLecturaParcial(): bool
    {
        return (bool) ($this->LEIDO['lectura_parcial'] ?? false);
    }

    /** El PDF enlazado es de otro vehiculo (lo dicen su placa o su serial). */
    public function esDeOtroVehiculo(): bool
    {
        return (bool) ($this->LEIDO['otra_placa'] ?? false);
    }

    /** No se pudo leer ni la placa ni el serial: no hay forma de saber de quien es el PDF. */
    public function sinConfirmar(): bool
    {
        return (bool) ($this->LEIDO['sin_confirmar'] ?? false);
    }

    /**
     * Una persona miro el PDF, corrigio la ficha a mano (en el panel del visor) y da la fila
     * por buena: queda como "coincide", sin diferencias pendientes y sin la marca de
     * "revisar a mano", con su nombre en el motivo. Es su decision; si vuelve a subirse
     * otro archivo, la verificacion lo leera de nuevo como a cualquiera.
     */
    public function marcarRevisadoPor(Usuario $usuario): void
    {
        // Lo de la carga masiva que YA esta en su ficha pero quedo para revisar (enlazado sin
        // fecha, o un BL sin numero): darlo por revisado solo lo saca de ese monton; sigue
        // "Aplicado" y conserva lo que dice su motivo.
        if ($this->ORIGEN === self::DE_CARGA_MASIVA) {
            $this->update([
                'A_MANO'       => false,
                'MOTIVO'       => self::motivoQueCabe((string) $this->MOTIVO,
                                    'Revisado a mano por ' . ($usuario->NOMBRE_COMPLETO ?: $usuario->CORREO_ELECTRONICO) . '. '),
                'APLICADO_POR' => $usuario->getKey(),
            ]);
            return;
        }

        $this->update([
            'ESTADO'       => self::COINCIDE,
            'A_MANO'       => false,
            'DIFERENCIAS'  => null,
            'MOTIVO'       => mb_substr('Revisado a mano por ' . ($usuario->NOMBRE_COMPLETO ?: $usuario->CORREO_ELECTRONICO), 0, 255),
            'APLICADO_POR' => $usuario->getKey(),
            'APLICADO_EN'  => now(),
        ]);
    }

    /**
     * Las marcas de LEIDO que dejan una lectura para que la mire una persona aunque no traiga
     * DIFERENCIAS (de otro vehiculo, leida a medias, sin confirmar de quien es, fuera de la lista
     * del RACDA, el PDF anterior, otra placa en la tabla del ROTC). UNA lista: la usan
     * VerificarDocumentos (A_MANO) y fechaPuesta (no dar por resuelta una fila que sigue por esto).
     */
    public const LEIDO_PARA_REVISAR = ['otra_placa', 'lectura_parcial', 'sin_confirmar', 'fuera_de_lista', 'doc_anterior', 'placa_en_tabla'];

    /** ¿La lectura trae alguna de LEIDO_PARA_REVISAR? */
    public static function leidoParaRevisar(?array $leido): bool
    {
        return (bool) array_filter(array_intersect_key($leido ?? [], array_flip(self::LEIDO_PARA_REVISAR)));
    }

    /** El motivo de un documento que vence y no tiene fecha ni la deja leer (ver VerificarDocumentos). */
    public const MOTIVO_SIN_FECHA = 'No tiene fecha de vencimiento y el documento no la deja leer: ponla en el visor.';

    /**
     * Un motivo que cabe en su columna (255): si no, se recorta por DELANTE —lo de atras es lo que
     * dice que falta o que hacer—, despues de $prefijo, que se queda entero. UNA sola regla para
     * la carga masiva y para "Revisado a mano".
     */
    public static function motivoQueCabe(string $motivo, string $prefijo = ''): string
    {
        $cabe = 255 - mb_strlen($prefijo);
        return $prefijo . (mb_strlen($motivo) > $cabe ? '…' . mb_substr($motivo, -($cabe - 1)) : $motivo);
    }

    /**
     * Se puso la fecha de vencimiento de un documento que estaba SIN ella: el que la carga masiva
     * enlazo asi (CargaMasivaDocumentos::ENLAZAR_Y_REVISAR) o el que la lectura de la noche dejo
     * para revisar por lo mismo (LEIDO sin_fecha). Lo llaman el observer de EquipoAuxiliar y
     * DocumentacionObserver::fechasPuestas ($tabla y sus columnas del enlace y del vencimiento).
     * Va por el archivo, y un PDF compartido (un RACDA de varias unidades) sigue para revisar
     * mientras a ALGUNA de sus fichas vivas le falte la fecha. Una lectura de la noche con otras
     * diferencias pendientes solo pierde lo de la fecha: lo demas lo sigue mirando una persona.
     */
    public static function fechaPuesta(string $tabla, string $colLink, string $colVence, ?string $link): void
    {
        $driveId = DocumentoAnexo::driveIdDeLink($link);
        if (!$driveId) return;
        $faltan = DB::table($tabla . ' as t')
            ->where("t.$colLink", 'like', '/storage/google/' . $driveId . '%')->whereNull("t.$colVence")
            ->when($tabla === 'documentacion', fn ($q) => $q->join('equipos as e', 'e.ID_EQUIPO', '=', 't.ID_EQUIPO')->whereNull('e.deleted_at'))
            ->when($tabla === 'equipos_auxiliares', fn ($q) => $q->whereNull('t.deleted_at'))
            ->exists();
        if ($faltan) return;

        static::where('DRIVE_ID', $driveId)->where('A_MANO', true)->get()
            ->filter(fn ($r) => ($r->ORIGEN === self::DE_CARGA_MASIVA && $r->ESTADO === self::APLICADO)
                || ($r->ORIGEN === self::DE_LA_NOCHE && !empty($r->LEIDO['sin_fecha'])))
            ->each(function ($r) {
                if ($r->ORIGEN === self::DE_CARGA_MASIVA) {
                    $r->update(['A_MANO' => false]);
                    return;
                }
                $leido = $r->LEIDO;
                unset($leido['sin_fecha']);
                // Solo se da por resuelta si la fecha era lo UNICO: con otras diferencias o
                // marcas (p. ej. la placa fuera de la lista del RACDA) se quita lo de la fecha y
                // lo demas lo sigue mirando una persona.
                $r->update(empty($r->DIFERENCIAS) && !self::leidoParaRevisar($leido)
                    ? ['ESTADO' => self::COINCIDE, 'A_MANO' => false, 'LEIDO' => $leido, 'MOTIVO' => 'Fecha de vencimiento puesta a mano']
                    : ['LEIDO' => $leido, 'MOTIVO' => trim(str_replace(self::MOTIVO_SIN_FECHA, '', (string) $r->MOTIVO)) ?: null]);
            });
    }

    /**
     * Se le puso el numero (en el visor) a un BL que la carga masiva enlazo SIN el
     * (CargaMasivaDocumentos::ENLAZAR_Y_REVISAR): la fila de ese PDF sale de "para revisar", si
     * era lo UNICO que faltaba (revisar_por; un BL pasado del tope sigue para que se mire).
     */
    public static function numeroDeBlPuesto(?string $link): void
    {
        $driveId = DocumentoAnexo::driveIdDeLink($link);
        if (!$driveId) return;
        static::where('DRIVE_ID', $driveId)->where('ORIGEN', self::DE_CARGA_MASIVA)
            ->where('ESTADO', self::APLICADO)->where('A_MANO', true)->get()
            ->filter(fn ($r) => empty($r->PROPUESTA['embarque']['nro'])
                && !array_diff($r->PROPUESTA['revisar_por'] ?? ['numero'], ['numero']))
            ->each(fn ($r) => $r->update(['A_MANO' => false]));
    }

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }
}
