<?php

namespace App\Services;

use App\Models\CatalogoSeguro;
use App\Models\Documentacion;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Observers\DocumentacionObserver;
use Illuminate\Support\Facades\DB;

/**
 * El UNICO sitio donde la verificacion de documentos cambia una ficha (tabla documentacion).
 * Lo usa docs:verificar-documentos en cuanto lee el PDF: MANDA EL DOCUMENTO. Lo que dice el
 * PDF se pone en la ficha, este vacia o diga otra cosa (asi lo pidio el cliente: el papel es
 * el que vale). Lo que una persona quiera corregir a mano lo hace en el panel del visor, desde
 * Control de Auditoría -> Documentos, y alli mismo da la fila por revisada.
 *
 * Lo que NUNCA se escribe, venga como venga: la PLACA y el SERIAL (ver CAMPOS). Y no se
 * escribe NADA cuando el PDF es de otro vehiculo o es el anterior: ahi lo que hay que arreglar
 * es el archivo, no la ficha. Si se leyo a medias o no se pudo confirmar de quien es, solo se
 * ponen las FECHAS que la ficha tiene VACIAS (emision y vencimiento): no pisan nada (regla del
 * cliente, 21-09-2026); el resto lo decide una persona.
 *
 * Antes de escribir se comprueba que la ficha SIGA como estaba cuando se leyo el PDF: si
 * alguien la corrigio a mano entretanto, su correccion manda.
 */
class CorrectorFichaDocumento
{
    /**
     * LO UNICO que se puede escribir en la ficha desde un documento. Es una lista cerrada a
     * proposito: la PLACA y el SERIAL DE CARROCERIA no estan —y no pueden estar— porque son
     * justo lo que se usa para comprobar que el PDF sea de este vehiculo. Si se dejaran
     * cambiar, un documento mal enlazado podria "arreglarse" solo cambiandole la identidad a
     * la ficha, y nadie volveria a notar el error. Esos dos se corrigen a mano, en el equipo.
     */
    public const CAMPOS = [
        'NOMBRE_DEL_TITULAR',
        // El número del título: el verificador SOLO lo propone cuando la ficha no tiene ninguno
        // (pedido del cliente, 01-10-2026); uno ya escrito no se toca ni se compara. Y como todo,
        // se escribe solo si la ficha sigue vacía al aplicarlo (ver $esperado en aplicar()).
        'NRO_DE_DOCUMENTO',
        'ID_SEGURO',
        'FECHA_VENC_POLIZA', 'FECHA_EMISION_POLIZA',
        'FECHA_EMISION_PROPIEDAD',
        'FECHA_ROTC', 'FECHA_EMISION_ROTC',
        'FECHA_RACDA', 'FECHA_EMISION_RACDA',
    ];

    /**
     * El propietario (NOMBRE_DEL_TITULAR) solo lo dice el TITULO: el nombre del ROTC es la
     * operadora (ver VerificarDocumentos::revisarRotc). Una lectura ROTC guardada antes del
     * 06-10-2026 puede traerlo todavia (la migracion de ese dia las borra): no se escribe, ni
     * sola ni a mano.
     */
    private static function seEscribeDesde(string $campo, ?string $tipo): bool
    {
        return $campo !== 'NOMBRE_DEL_TITULAR' || $tipo === VerificacionDocumento::PROPIEDAD;
    }

    /**
     * Pone en la ficha lo que dice el documento. Con $soloFechasVacias (una fila que ya reviso
     * una persona: su decision se respeta) solo pone las fechas que la ficha tiene vacias; lo
     * mismo hace sola cuando la lectura no es segura (ver admiteFechasVacias). Devuelve:
     *   ['error' => 'texto']                       no se pudo (y por que)
     *   ['puestos' => [...], 'saltados' => [...]]  etiquetas de lo escrito y de lo respetado
     */
    public function aplicar(VerificacionDocumento $reg, bool $soloFechasVacias = false): array
    {
        $bloqueo = $this->porQueNoSePuede($reg);
        if ($bloqueo && !$this->admiteFechasVacias($reg)) {
            return ['error' => $bloqueo];
        }

        // Los nombres del catalogo, para poder decir "MAMPRECA" y no "1" cuando lo que queda
        // pendiente es la aseguradora.
        $aseguradoras = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();

        return DB::transaction(function () use ($reg, $aseguradoras, $soloFechasVacias) {
            // Se bloquean LAS DOS filas: la ficha y la lectura. Sin bloquear la lectura, dos
            // pasadas a la vez (o una persona guardando mientras corre la tarea) pasan las dos por
            // la puerta y la segunda, al ver la ficha ya cambiada, la marcaria como "lo cambio
            // alguien a mano" siendo mentira: lo habia cambiado la primera.
            $reg = VerificacionDocumento::where('ID_REGISTRO', $reg->ID_REGISTRO)->lockForUpdate()->first();
            if (!$reg) return ['error' => 'Esa lectura ya no existe: vuelve a leer el documento.'];
            $bloqueo = $this->porQueNoSePuede($reg);
            if ($bloqueo && !$this->admiteFechasVacias($reg)) return ['error' => $bloqueo];
            $soloVacias = $soloFechasVacias || $bloqueo !== null;
            $fechas     = VerificacionDocumento::camposDeFecha($reg->TIPO);

            $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->lockForUpdate()->first();
            if (!$doc) return ['error' => 'La ficha de ese equipo ya no existe.'];

            // Una lectura guardada de un PDF que ya al leerlo vencia ANTES de lo que decia la
            // ficha (el anterior: se renovo y la ficha ya tenia la fecha nueva) no escribe NADA.
            // El verificador ya no las produce, pero las guardadas antes si, y la tarea las
            // aplicaria al rellenar: pondria la fecha vieja encima de la buena. (Si la ficha
            // cambio DESPUES de leer, eso lo resuelve la comprobacion de mas abajo.)
            if ($anterior = $this->anteriorPorSuVencimiento($reg, $doc)) {
                $reg->update([
                    'A_MANO'      => true,
                    'MOTIVO'      => mb_substr($anterior, 0, 255),
                    'DIFERENCIAS' => null,
                    'LEIDO'       => ['doc_anterior' => true] + ($reg->LEIDO ?? []),
                ]);
                return ['puestos' => [], 'saltados' => []];
            }

            $puestos = $saltados = $cambios = $quedan = [];
            $hayCorregidoAMano = false;

            foreach ($reg->DIFERENCIAS as $campo => $d) {
                if (!self::seEscribeDesde($campo, $reg->TIPO)) continue;
                // Red de seguridad: solo los datos de la lista. Si alguna vez se añade una
                // diferencia nueva al verificador, tiene que pasar por aqui a proposito.
                if (!in_array($campo, self::CAMPOS, true)) {
                    $quedan[$campo] = $d;
                    continue;
                }
                // Lectura no segura o fila ya revisada por una persona: solo una fecha de este
                // documento que la ficha tenia VACIA al leerlo. Lo demas queda para quien decida.
                if ($soloVacias && !(in_array($campo, $fechas, true) && ($d['ficha'] ?? null) === null && empty($d['a_mano']))) {
                    $quedan[$campo] = $d;
                    continue;
                }
                $ahora = $doc->{$campo};
                if ($ahora instanceof \DateTimeInterface) $ahora = $ahora->format('Y-m-d');

                // Lo que la ficha tenia cuando se leyo el PDF. Si ya no es eso, alguien lo
                // corrigio a mano despues: su correccion manda. La aseguradora se compara por
                // su ID (ficha_valor), no por el nombre que se muestra.
                $esperado = array_key_exists('ficha_valor', $d) ? $d['ficha_valor'] : ($d['ficha'] ?? '');
                if ((string) $ahora !== (string) $esperado) {
                    // Ese dato ya no se toca solo: quien decida tiene que mirar el documento
                    // (si se reintentara, la pasada siguiente pisaria la correccion de la persona).
                    $saltados[] = $d['etiqueta'];
                    $hayCorregidoAMano = true;
                    $quedan[$campo] = [
                        'etiqueta'  => $d['etiqueta'],
                        'ficha'     => $campo === 'ID_SEGURO' ? ($aseguradoras[$ahora] ?? (string) $ahora) : (is_scalar($ahora) ? (string) $ahora : null),
                        'documento' => $d['documento'],
                        'a_mano'    => true,
                    ];
                    continue;
                }

                // La aseguradora se guarda por su ID del catalogo; el resto, tal cual se leyo.
                $doc->{$campo} = $campo === 'ID_SEGURO' ? (int) $d['valor'] : $d['documento'];
                $puestos[] = $d['etiqueta'];
                // Para el historial se guardan los nombres, no los IDs: es lo que se lee.
                $cambios[$campo] = ['antes' => $d['ficha'], 'despues' => $d['documento']];
            }

            // Con solo fechas vacias y ninguna por poner, la fila se queda como la dejo quien la
            // leyo o la reviso (no es que alguien cambiara la ficha), y si la lectura no era
            // segura se dice por que no se puso nada.
            if (!$puestos && $soloVacias && !$hayCorregidoAMano) {
                return $bloqueo ? ['error' => $bloqueo] : ['puestos' => [], 'saltados' => $saltados];
            }
            if (!$puestos) {
                // Nada que escribir: alguien cambio la ficha despues de leer el PDF, o lo que
                // quedaba no es de los datos que este servicio puede tocar. La fila SE GUARDA
                // igual, marcada para mirar a mano: si no, la tarea no la resuelve nunca, la
                // reintenta en cada pasada para siempre y el panel sigue enseñando un valor
                // de ficha que ya no existe.
                // Si lo unico que queda es lo que esta lista no toca (el serial o la placa del
                // titulo), no es que alguien cambiara la ficha: lo elige una persona en el visor.
                $reg->update([
                    'ESTADO'      => VerificacionDocumento::DIFIERE,
                    'A_MANO'      => true,
                    'MOTIVO'      => mb_substr($this->paraElegir($quedan)
                        ?? 'Alguien cambió la ficha después de leer el documento: ' . $this->etiquetas($quedan) . '. Míralo con el PDF delante.', 0, 255),
                    'DIFERENCIAS' => $quedan,
                ]);
                return ['puestos' => [], 'saltados' => $saltados];
            }
            $doc->save();

            // Historial del equipo. DocumentacionObserver solo audita PLACA, NRO_DE_DOCUMENTO y
            // NOMBRE_DEL_TITULAR: los datos de la poliza (aseguradora y fechas) no dejarian
            // rastro, asi que se registran aqui — y solo esos, para no duplicar los suyos.
            $propios = array_diff_key($cambios, array_flip(DocumentacionObserver::AUDITED));
            if ($propios) {
                EquipoAuditLog::registrar($reg->ID_EQUIPO, 'edit', $propios + ['_origen' => 'Verificación de documentos (automática)']);
            }

            // Un documento que vence y sigue SIN fecha (ni la ficha ni el PDF la tienen; ver
            // VerificarDocumentos): poner lo demas no lo deja resuelto, sigue para revisar.
            $colVence = VerificacionDocumento::CAMPO_VENCE[$reg->TIPO] ?? null;
            $sinFecha = !empty($reg->LEIDO['sin_fecha']) && $colVence && empty($doc->{$colVence});
            $reg->update([
                'ESTADO'       => ($quedan || $sinFecha) ? VerificacionDocumento::DIFIERE : VerificacionDocumento::COINCIDE,
                // A_MANO: solo si lo que queda ya no lo puede poner la tarea: lo que alguien
                // cambio a mano despues de leer el PDF, lo que una lectura no segura no deja
                // poner, lo que esta lista nunca toca (el serial o la placa) o la fecha que falta:
                // eso se mira en el visor.
                'A_MANO'       => $hayCorregidoAMano || ($bloqueo !== null && $quedan) || $sinFecha
                                  || (bool) array_diff_key($quedan, array_flip(self::CAMPOS)),
                'MOTIVO'       => $sinFecha && !$quedan
                    ? mb_substr(trim((string) $reg->MOTIVO) . ' · Se puso solo: ' . implode(', ', $puestos), 0, 255)
                    : $this->motivo($reg, $puestos, $quedan),
                'DIFERENCIAS'  => $quedan ?: null,
                'APLICADO_EN'  => now(),
            ]);

            return ['puestos' => $puestos, 'saltados' => $saltados];
        });
    }

    /**
     * Revision A MANO desde el visor: la persona corrigio la ficha en el panel y, ademas, pone
     * los datos de CAMPOS que ese panel no tenga como campo propio, con el valor que ella deja
     * escrito; el boton "Revisado" de la tabla pone aqui las fechas vacias (fechasVacias).
     * Despues la fila queda "revisada por" ella.
     *
     * $valores es [campo => valor]. Solo se aceptan datos de CAMPOS que el verificador marco
     * como distintos en esa fila (nunca la placa ni el serial, nunca un campo cualquiera), y
     * cada uno con su forma: fecha aaaa-mm-dd o un nombre de hasta LectorDocumentoPdf::LARGO_TITULAR. La
     * aseguradora no entra aqui: tiene su propio campo en el panel del visor.
     * Devuelve ['error' => ...] o ['puestos' => [...]].
     */
    public function ponerAMano(VerificacionDocumento $reg, array $valores, Usuario $usuario): array
    {
        $dif = $reg->DIFERENCIAS ?? [];
        $limpios = [];
        foreach ($valores as $campo => $valor) {
            $valor = trim((string) $valor);
            if (!in_array($campo, self::CAMPOS, true) || $campo === 'ID_SEGURO' || !isset($dif[$campo])
                || !self::seEscribeDesde($campo, $reg->TIPO)) {
                return ['error' => "Ese dato no se puede poner desde aquí: $campo"];
            }
            if (str_starts_with($campo, 'FECHA_')) {
                [$a, $m, $d] = array_map('intval', explode('-', $valor) + [0, 0, 0]);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) || !checkdate($m, $d, $a)) {
                    return ['error' => 'Fecha no válida en ' . ($dif[$campo]['etiqueta'] ?? $campo)];
                }
            } elseif ($valor === '' || mb_strlen($valor) > LectorDocumentoPdf::LARGO_TITULAR) {
                return ['error' => 'Nombre vacío o demasiado largo en ' . ($dif[$campo]['etiqueta'] ?? $campo)];
            }
            $limpios[$campo] = $valor;
        }

        $fallo = DB::transaction(function () use ($reg, $limpios, $usuario) {
            // Se bloquea TAMBIÉN la lectura, como en aplicar(): si no, la tarea nocturna puede
            // escribir esta misma fila entre el cambio de la ficha y el "revisado a mano", y el
            // trabajo de la persona queda pisado o marcado como si lo hubiera cambiado otro.
            $reg = VerificacionDocumento::where('ID_REGISTRO', $reg->ID_REGISTRO)->lockForUpdate()->first();
            // Como en aplicar(): si la fila ya no está, se corta. Antes seguía en silencio con el
            // modelo viejo y marcarRevisadoPor() no tocaba ninguna fila, pero el panel respondía
            // "listo".
            if (!$reg) return ['error' => 'Esa lectura ya no existe: vuelve a leer el documento.'];
            // Y se vuelve a comprobar el permiso de cada campo contra la fila YA bloqueada: la
            // validación de arriba se hizo sobre el retrato anterior al bloqueo, y entre medias
            // la tarea nocturna pudo reescribir DIFERENCIAS.
            $dif = $reg->DIFERENCIAS ?? [];
            foreach (array_keys($limpios) as $campo) {
                if (!isset($dif[$campo])) {
                    return ['error' => 'Esa lectura cambió mientras la revisabas: vuelve a abrirla.'];
                }
            }
            if ($limpios) {
                // first() y no firstOrFail(), igual que aplicar(): si la ficha ya no esta, se
                // responde con el mismo mensaje de siempre en vez de reventar con un 500 (o, en
                // la tanda, ensenarle al usuario el texto de Eloquent).
                $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->lockForUpdate()->first();
                if (!$doc) return ['error' => 'La ficha de ese equipo ya no existe.'];
                $cambios = [];
                foreach ($limpios as $campo => $valor) {
                    $antes = $doc->{$campo};
                    if ($antes instanceof \DateTimeInterface) $antes = $antes->format('Y-m-d');
                    $doc->{$campo} = $valor;
                    $cambios[$campo] = ['antes' => $antes, 'despues' => $valor];
                }
                $doc->save();
                // Como en aplicar(): lo que DocumentacionObserver no audita, aqui.
                $propios = array_diff_key($cambios, array_flip(DocumentacionObserver::AUDITED));
                if ($propios) {
                    EquipoAuditLog::registrar($reg->ID_EQUIPO, 'edit', $propios + ['_origen' => 'Verificación de documentos (revisión a mano)']);
                }
            }
            $reg->marcarRevisadoPor($usuario);
            return null;
        });

        return $fallo ?: ['puestos' => array_keys($limpios)];
    }

    /**
     * Las fechas de este documento que la ficha tiene VACIAS [campo => aaaa-mm-dd]: lo que pone
     * el boton "Revisado" de la tabla antes de dar la fila por revisada (lo pidio el cliente el
     * 21-09-2026: las emisiones vacias de los anexos de flota se quedaban sin poner). Mismas
     * reglas que la tarea con una lectura no segura (ver aplicar): nada si el PDF es de otro
     * vehiculo, el anterior o una providencia que no nombra la placa; y solo si la ficha SIGUE
     * vacia (si alguien la lleno despues de leer, se respeta).
     */
    public function fechasVacias(VerificacionDocumento $reg): array
    {
        if ($this->porQueNoSePuede($reg) && !$this->admiteFechasVacias($reg)) return [];
        // Una lectura guardada antes de la regla del PDF anterior no lleva su marca: se mira
        // por el vencimiento, como en aplicar().
        $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->first();
        if (!$doc) return [];
        if ($this->anteriorPorSuVencimiento($reg, $doc)) return [];

        $fechas = [];
        foreach (VerificacionDocumento::camposDeFecha($reg->TIPO) as $campo) {
            $d = $reg->DIFERENCIAS[$campo] ?? null;
            if ($d && ($d['ficha'] ?? null) === null && !empty($d['documento']) && empty($d['a_mano'])
                && $doc->{$campo} === null) {
                $fechas[$campo] = $d['documento'];
            }
        }
        return $fechas;
    }

    /** Lo que se cuenta en la pantalla y en el listado del comando. */
    private function motivo(VerificacionDocumento $reg, array $puestos, array $quedan): string
    {
        if (!$quedan) return 'Puesto solo con lo que dice el documento';
        // Manda la explicacion de lo que hay que MIRAR ("se diferencian en una letra..."), que
        // es para lo que se lee esta columna; lo que se puso solo va detras. Si solo queda el
        // serial o la placa, la explicacion es esa: la de antes era de lo que ya se puso.
        $base = $this->paraElegir($quedan) ?? (trim((string) $reg->MOTIVO) ?: ('Falta decidir ' . $this->etiquetas($quedan)));
        return mb_substr($base . ' · Se puso solo: ' . implode(', ', $puestos), 0, 255);
    }

    /**
     * Lo que queda es SOLO lo que esta lista no toca (el serial o la placa del titulo): el motivo
     * pide que una persona elija en el visor. Si queda algo mas, null (manda el otro motivo).
     */
    private function paraElegir(array $quedan): ?string
    {
        if (!$quedan || array_intersect_key($quedan, array_flip(self::CAMPOS))) return null;
        return 'El documento no coincide con la ficha en: ' . $this->etiquetas($quedan) . '. Elige en el visor cuál es el bueno.';
    }

    /** "propietario, vencimiento" — lo que queda por decidir, en minusculas. */
    private function etiquetas(array $quedan): string
    {
        return implode(', ', array_map(fn ($d) => mb_strtolower($d['etiqueta']), $quedan));
    }

    /**
     * ¿Es el PDF ANTERIOR, visto por el vencimiento que se leyo? Vale tambien para lecturas
     * guardadas antes de la regla (no llevan la marca doc_anterior). Con la emision de la
     * ficha y el inicio del PDF: el que empieza cuando la ficha o despues no es el anterior
     * (ver documentoAnterior). Devuelve el motivo o null.
     */
    private function anteriorPorSuVencimiento(VerificacionDocumento $reg, Documentacion $doc): ?string
    {
        $campoVence = VerificacionDocumento::CAMPO_VENCE[$reg->TIPO] ?? null;
        $vence = $campoVence ? ($reg->DIFERENCIAS[$campoVence] ?? null) : null;
        if (!$vence) return null;
        $campoEmision = VerificacionDocumento::CAMPO_EMISION[$reg->TIPO] ?? null;
        return VerificacionDocumento::documentoAnterior($vence['ficha'] ?? null, $vence['documento'] ?? null,
            $campoEmision ? $doc->$campoEmision?->format('Y-m-d') : null, VerificacionDocumento::inicioDelDocumento($reg->LEIDO));
    }

    /**
     * ¿Se pueden poner al menos las fechas vacias aunque la lectura no sea segura? Si el PDF
     * no se pudo confirmar o se leyo a medias, si; si es de otro vehiculo, el anterior o una
     * providencia que no nombra la placa, NO: nada de ese documento es de esta ficha.
     */
    private function admiteFechasVacias(VerificacionDocumento $reg): bool
    {
        return $reg->ESTADO === VerificacionDocumento::DIFIERE
            && !empty($reg->DIFERENCIAS)
            && !$reg->esDeOtroVehiculo()
            && !$reg->esDocumentoAnterior()
            && !($reg->LEIDO['fuera_de_lista'] ?? false);
    }

    /** Las razones por las que un documento NO se puede volcar en la ficha, en su orden. */
    private function porQueNoSePuede(VerificacionDocumento $reg): ?string
    {
        return match (true) {
            $reg->esDeOtroVehiculo() => 'Ese PDF es de otro vehículo: hay que corregir el archivo enlazado, no copiar sus datos.',
            $reg->esLecturaParcial() => 'Ese documento se leyó a medias: dice menos que la ficha. Ábrelo y corrígelo a mano.',
            $reg->sinConfirmar()     => 'De ese PDF no se pudo leer la placa ni el serial: ábrelo y comprueba que sea de este vehículo.',
            !$reg->aplicable()       => 'Ese documento ya coincide con la ficha: no hay nada que cambiar.',
            default                  => null,
        };
    }
}
