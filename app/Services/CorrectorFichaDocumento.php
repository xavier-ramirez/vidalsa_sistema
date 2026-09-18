<?php

namespace App\Services;

use App\Models\CatalogoSeguro;
use App\Models\Documentacion;
use App\Models\EquipoAuditLog;
use App\Models\VerificacionDocumento;
use App\Observers\DocumentacionObserver;
use Illuminate\Support\Facades\DB;

/**
 * El UNICO sitio donde la verificacion de documentos cambia una ficha (tabla documentacion).
 * Lo usan los dos caminos:
 *
 *   · AUTOMATICO — docs:verificar-documentos, en cuanto lee el PDF: MANDA EL DOCUMENTO. Lo
 *     que dice el PDF se pone en la ficha, este vacia o diga otra cosa (asi lo pidio el
 *     cliente: el papel es el que vale).
 *   · A MANO — el boton "Corregir ficha" de Control de Auditoría
 *     (CompresionPdfController::aplicarDocumento), para lo que quedo sin aplicar.
 *
 * Lo que NUNCA se escribe, venga como venga: la PLACA y el SERIAL (ver CAMPOS). Y no se
 * escribe NADA cuando el PDF es de otro vehiculo, se leyo a medias o no se pudo confirmar de
 * quien es: ahi lo que hay que arreglar es el archivo, no la ficha.
 *
 * En los dos casos se comprueba antes que la ficha SIGA como estaba cuando se leyo el PDF: si
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
     * Pone en la ficha lo que dice el documento. Devuelve:
     *   ['error' => 'texto']                       no se pudo (y por que)
     *   ['puestos' => [...], 'saltados' => [...]]  etiquetas de lo escrito y de lo respetado
     *
     * $usuarioId es quien lo pidio (null = lo hizo el propio comando de noche).
     */
    public function aplicar(VerificacionDocumento $reg, ?int $usuarioId): array
    {
        if ($error = $this->porQueNoSePuede($reg)) {
            return ['error' => $error];
        }

        // Los nombres del catalogo, para poder decir "MAMPRECA" y no "1" cuando lo que queda
        // pendiente es la aseguradora.
        $aseguradoras = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();

        return DB::transaction(function () use ($reg, $usuarioId, $aseguradoras) {
            // Se bloquean LAS DOS filas: la ficha y la lectura. Sin bloquear la lectura, dos
            // clics seguidos (o un clic mientras corre la pasada de la noche) pasan los dos por
            // la puerta y el segundo, al ver la ficha ya cambiada, la marcaria como "lo cambio
            // alguien a mano" siendo mentira: lo habia cambiado el primero.
            $reg = VerificacionDocumento::where('ID_REGISTRO', $reg->ID_REGISTRO)->lockForUpdate()->first();
            if (!$reg) return ['error' => 'Esa lectura ya no existe: vuelve a leer el documento.'];
            if ($error = $this->porQueNoSePuede($reg)) return ['error' => $error];

            $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->lockForUpdate()->first();
            if (!$doc) return ['error' => 'La ficha de ese equipo ya no existe.'];

            $puestos = $saltados = $cambios = $quedan = [];
            $hayCorregidoAMano = false;

            foreach ($reg->DIFERENCIAS as $campo => $d) {
                // Red de seguridad: solo los datos de la lista. Si alguna vez se añade una
                // diferencia nueva al verificador, tiene que pasar por aqui a proposito.
                if (!in_array($campo, self::CAMPOS, true)) {
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
                    // No se vuelve a ofrecer el boton para ese dato: quien decida tiene que
                    // mirar el documento (si se dejara, el siguiente clic pisaria la correccion
                    // de la persona).
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

            if (!$puestos) {
                // Nada que escribir: alguien cambio la ficha despues de leer el PDF, o lo que
                // quedaba no es de los datos que este servicio puede tocar. La fila SE GUARDA
                // igual, marcada para mirar a mano: si no, ningun boton la arregla, la pasada
                // de cada noche la reintenta para siempre y el panel sigue enseñando un valor
                // de ficha que ya no existe.
                $reg->update([
                    'ESTADO'      => VerificacionDocumento::DIFIERE,
                    'A_MANO'      => true,
                    'MOTIVO'      => mb_substr('Alguien cambió la ficha después de leer el documento: '
                        . $this->etiquetas($quedan) . '. Míralo con el PDF delante.', 0, 255),
                    'DIFERENCIAS' => $quedan,
                ]);
                return $usuarioId
                    ? ['error' => 'La ficha ya no dice lo que decía cuando se leyó el documento: queda marcada para revisarla con el PDF delante.']
                    : ['puestos' => [], 'saltados' => $saltados];
            }
            $doc->save();

            // Historial del equipo. DocumentacionObserver solo audita PLACA, NRO_DE_DOCUMENTO y
            // NOMBRE_DEL_TITULAR: los datos de la poliza (aseguradora y fechas) no dejarian
            // rastro, asi que se registran aqui — y solo esos, para no duplicar los suyos.
            $propios = array_diff_key($cambios, array_flip(DocumentacionObserver::AUDITED));
            if ($propios) {
                EquipoAuditLog::registrar($reg->ID_EQUIPO, 'edit', $propios + [
                    '_origen' => $usuarioId ? 'Verificación de documentos' : 'Verificación de documentos (automática)',
                ]);
            }

            $reg->update([
                'ESTADO'       => $quedan ? VerificacionDocumento::DIFIERE : VerificacionDocumento::COINCIDE,
                // A_MANO: solo si lo que queda ya no lo arregla ningun boton, es decir, lo que
                // alguien cambio a mano despues de leer el PDF.
                'A_MANO'       => $hayCorregidoAMano,
                'MOTIVO'       => $this->motivo($reg, $puestos, $quedan, $hayCorregidoAMano, $usuarioId),
                'DIFERENCIAS'  => $quedan ?: null,
                'APLICADO_POR' => $usuarioId,
                'APLICADO_EN'  => now(),
            ]);

            return ['puestos' => $puestos, 'saltados' => $saltados];
        });
    }

    /** Lo que se cuenta en la pantalla y en el listado del comando. */
    private function motivo(VerificacionDocumento $reg, array $puestos, array $quedan, bool $hayCorregidoAMano, ?int $usuarioId): string
    {
        if (!$quedan) {
            return $usuarioId ? 'Corregido con lo que dice el documento' : 'Puesto solo con lo que dice el documento';
        }
        if ($usuarioId) {
            return mb_substr('Se corrigio ' . implode(', ', $puestos)
                . ($hayCorregidoAMano ? '; lo demas lo cambio alguien a mano y hay que mirarlo'
                                      : '; falta decidir ' . $this->etiquetas($quedan)), 0, 255);
        }
        // Rellenado automatico: manda la explicacion de lo que hay que MIRAR ("se diferencian
        // en una letra..."), que es para lo que se lee esta columna; lo que se puso solo va
        // detras. Si se sustituyera, la fila perderia justo el dato que la hace entendible.
        $base = trim((string) $reg->MOTIVO) ?: ('Falta decidir ' . $this->etiquetas($quedan));
        return mb_substr($base . ' · Se puso solo: ' . implode(', ', $puestos), 0, 255);
    }

    /** "propietario, vencimiento" — lo que queda por decidir, en minusculas. */
    private function etiquetas(array $quedan): string
    {
        return implode(', ', array_map(fn ($d) => mb_strtolower($d['etiqueta']), $quedan));
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
