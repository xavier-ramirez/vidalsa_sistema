<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consumible extends Model
{
    protected $table      = 'consumibles';
    protected $primaryKey = 'ID_CONSUMIBLE';

    protected $fillable = [
        // Bloque 1 — del Excel
        'FECHA',
        'IDENTIFICADOR',
        'DESC_EQUIPO',
        'OBSERVACION',      // la nota del Excel junto al vehículo («Apoyo a ECOINNOVA, C.A»)
        'RESP_NOMBRE',
        'RESP_CI',
        'CANTIDAD',
        // Bloque 2 — del formulario
        'TIPO_CONSUMIBLE',
        'ESPECIFICACION',   // Aceites: viscosidad (15W-40, SAE90). Caucho: medida (11R22.5)
        'UNIDAD',
        'ID_FRENTE',
        'ID_FRENTE_ORIGINAL',   // con el que se cargó; se guarda al cambiar el frente en la Lista
        // Bloque 3 — resueltos después
        'ID_EQUIPO',
        'ID_AUXILIAR',      // si el que surtió es un auxiliar (máquina de soldar, planta…)
        'ID_SUMINISTRO',
        'ESTADO_EQUIPO',
        'NOTAS',
    ];

    protected $casts = [
        'FECHA'    => 'date',
        'CANTIDAD' => 'decimal:2',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function equipo()
    {
        // Con borrados: el consumo ya pasó con ese equipo; la lista, el export y el buscador lo muestran igual.
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO')->withTrashed();
    }

    /**
     * Lo escrito sin la descripción del Excel al frente. El importador guarda «DESCRIPCIÓN | PLACA
     * | …» (IdentificadorConsumo::claveAliasEquipo) y la descripción ya se muestra aparte; solo se
     * quita si la primera parte ES esa descripción (sin descripción en el Excel la primera es la placa).
     */
    public static function escritoSinDescripcion(?string $identificador, ?string $desc): string
    {
        $partes = explode(' | ', (string) $identificador);
        if ($desc !== null && $desc !== '' && $partes[0] === \App\Services\Consumibles\TextoConsumo::norm($desc)) {
            array_shift($partes);
        }

        return implode(' | ', $partes);
    }

    /** Palabras con las que empieza una observación (no un modelo: «PAYLOADER - LW 800K N°6»). */
    private const INICIO_OBSERVACION = '(?:APOYO|PARA\b|PRESTAD|ALQUILAD|AUTORIZAD)';

    /**
     * La observación que el Excel escribe junto al vehículo, tras « - » (guion con espacio a los dos
     * lados) o entre paréntesis, si empieza con una de INICIO_OBSERVACION:
     * «MACK VACUUM - Apoyo a ECOINNOVA, C.A -» → «Apoyo a ECOINNOVA, C.A»; «CAMION DYNA (APOYO)» → «APOYO».
     * «RETROEXCAVADORA - 310J N°1», «VOLQUETA -JAC GALLOP» o «COMPRESOR (MORICHAL)» no se cortan.
     */
    public static function observacionDe(?string $desc): ?string
    {
        $partes = self::partirDescripcion($desc);

        return $partes[1];
    }

    /** La descripción del vehículo sin su observación (la de arriba). */
    public static function descSinObservacion(?string $desc): ?string
    {
        return self::partirDescripcion($desc)[0];
    }

    /** @return array{0:?string, 1:?string} [vehículo, observación] */
    private static function partirDescripcion(?string $desc): array
    {
        $d = trim(preg_replace('/\s+/u', ' ', (string) $desc));
        if ($d === '') {
            return [null, null];
        }
        $ini = self::INICIO_OBSERVACION;
        if (preg_match('/^(.*?\S)\s-\s+(' . $ini . '.*)$/iu', $d, $m)) {
            [$equipo, $obs] = [trim($m[1], ' -'), trim($m[2], ' -')];
        } elseif (preg_match('/^(.*?)\s*\(\s*(' . $ini . '[^)]*)\)\s*(.*)$/iu', $d, $m)) {
            [$equipo, $obs] = [trim($m[1] . ' ' . $m[3], ' -'), trim($m[2])];
        } else {
            return [$d, null];
        }
        if ($equipo === '' || $obs === '') {
            return [$d, null];
        }

        return [$equipo, mb_substr($obs, 0, 255)];
    }

    public function auxiliar()
    {
        return $this->belongsTo(EquipoAuxiliar::class, 'ID_AUXILIAR', 'ID_AUXILIAR')->withTrashed();
    }

    public function frente()
    {
        // withTrashed: es historial; un frente eliminado (borrado lógico) sigue dando su nombre.
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE')->withTrashed();
    }

    /** El frente con el que se cargó, si después se cambió (la Lista lo muestra debajo). */
    public function frenteOriginal()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ORIGINAL', 'ID_FRENTE')->withTrashed();
    }

    public function suministro()
    {
        return $this->belongsTo(SuministroOrigen::class, 'ID_SUMINISTRO', 'ID_SUMINISTRO');
    }

    // ── Labels útiles para vistas ────────────────────────────────

    public static function tiposLabel(): array
    {
        return [
            'GASOIL'       => 'Gasoil',
            'GASOLINA'     => 'Gasolina',
            'ACEITE'       => 'Aceite',
            'CAUCHO'       => 'Caucho',
        ];
    }

    public function getTipoLabelAttribute(): string
    {
        return self::tiposLabel()[$this->TIPO_CONSUMIBLE] ?? $this->TIPO_CONSUMIBLE;
    }
}
