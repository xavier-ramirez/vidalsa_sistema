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
        'ID_SEGURO',
        'FECHA_VENC_POLIZA', 'FECHA_EMISION_POLIZA',
        'FECHA_EMISION_PROPIEDAD',
        'FECHA_ROTC', 'FECHA_EMISION_ROTC',
        'FECHA_RACDA', 'FECHA_EMISION_RACDA',
    ];

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
            $campoVence = VerificacionDocumento::CAMPO_VENCE[$reg->TIPO] ?? null;
            $vence = $campoVence ? ($reg->DIFERENCIAS[$campoVence] ?? null) : null;
            $anterior = $vence ? VerificacionDocumento::documentoAnterior($vence['ficha'] ?? null, $vence['documento'] ?? null) : null;
            if ($anterior) {
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
                $reg->update([
                    'ESTADO'      => VerificacionDocumento::DIFIERE,
                    'A_MANO'      => true,
                    'MOTIVO'      => mb_substr('Alguien cambió la ficha después de leer el documento: '
                        . $this->etiquetas($quedan) . '. Míralo con el PDF delante.', 0, 255),
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

            $reg->update([
                'ESTADO'       => $quedan ? VerificacionDocumento::DIFIERE : VerificacionDocumento::COINCIDE,
                // A_MANO: solo si lo que queda ya no lo puede poner la tarea: lo que alguien
                // cambio a mano despues de leer el PDF, o lo que una lectura no segura no deja
                // poner (eso se mira en el visor).
                'A_MANO'       => $hayCorregidoAMano || ($bloqueo !== null && $quedan),
                'MOTIVO'       => $this->motivo($reg, $puestos, $quedan),
                'DIFERENCIAS'  => $quedan ?: null,
                'APLICADO_EN'  => now(),
            ]);

            return ['puestos' => $puestos, 'saltados' => $saltados];
        });
    }

    /**
     * Revision A MANO desde el visor: la persona corrigio la ficha en el panel y, ademas, pone
     * los datos que ese panel no tiene (las fechas de emision, el titular del ROTC), con el
     * valor que ella deja escrito. Despues la fila queda "revisada por" ella.
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
            if (!in_array($campo, self::CAMPOS, true) || $campo === 'ID_SEGURO' || !isset($dif[$campo])) {
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

        DB::transaction(function () use ($reg, $limpios, $dif, $usuario) {
            if ($limpios) {
                $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->lockForUpdate()->firstOrFail();
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
        });

        return ['puestos' => array_keys($limpios)];
    }

    /** Lo que se cuenta en la pantalla y en el listado del comando. */
    private function motivo(VerificacionDocumento $reg, array $puestos, array $quedan): string
    {
        if (!$quedan) return 'Puesto solo con lo que dice el documento';
        // Manda la explicacion de lo que hay que MIRAR ("se diferencian en una letra..."), que
        // es para lo que se lee esta columna; lo que se puso solo va detras.
        $base = trim((string) $reg->MOTIVO) ?: ('Falta decidir ' . $this->etiquetas($quedan));
        return mb_substr($base . ' · Se puso solo: ' . implode(', ', $puestos), 0, 255);
    }

    /** "propietario, vencimiento" — lo que queda por decidir, en minusculas. */
    private function etiquetas(array $quedan): string
    {
        return implode(', ', array_map(fn ($d) => mb_strtolower($d['etiqueta']), $quedan));
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
