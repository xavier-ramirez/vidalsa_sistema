<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reporte de fallas. Activo polimorfico (vehiculo o auxiliar) via
 * (ACTIVO_TIPO, ACTIVO_ID) — no morphTo: PK custom de las dos tablas
 * referenciadas (ID_EQUIPO / ID_AUXILIAR) hacen explicito el lookup.
 */
class Falla extends Model
{
    use SoftDeletes;

    protected $table      = 'fallas';
    protected $primaryKey = 'ID_FALLA';

    protected $fillable = [
        'CODIGO_REPORTE', 'FECHA_EMISION', 'TIPO_REPORTE', 'ESTADO_REPORTE',
        'ACTIVO_TIPO', 'ACTIVO_ID',
        'ESTADO_PREVIO', 'ESTADO_AL_CREAR',
        // Seccion 1 — informacion general
        'FRENTE_TRABAJO',
        // Seccion 2 — identificacion del equipo
        'KILOMETRAJE', 'HORAS',
        // Seccion 3 — tipo de mantenimiento requerido
        'DESCRIPCION_AVERIA', 'TIPO_MANTENIMIENTO',
        // Seccion 4 — exclusiva para taller
        'MECANICO_ASIGNADO', 'FECHA_RECEPCION', 'DIAGNOSTICO', 'ACCIONES_REALIZADAS',
        // Solicitante / cierre
        'ID_USUARIO_REPORTA', 'NOMBRE_REPORTA', 'CARGO_REPORTA', 'EMAIL_REPORTA',
        'ID_USUARIO_CIERRA',  'NOMBRE_CIERRA',  'CARGO_CIERRA',
        'FECHA_CIERRE', 'OBSERVACIONES_CIERRE',
    ];

    protected $casts = [
        'FECHA_EMISION'   => 'datetime',
        'FECHA_CIERRE'    => 'datetime',
        'FECHA_RECEPCION' => 'date',
    ];

    /**
     * Relacion al activo (Equipo o EquipoAuxiliar) segun ACTIVO_TIPO.
     * No es una relacion Eloquent: hidratamos manualmente. Patron usado
     * tambien por MovilizacionController para los aux sintetizados.
     */
    public function activo()
    {
        if ($this->ACTIVO_TIPO === 'equipo') {
            return Equipo::find($this->ACTIVO_ID);
        }
        if ($this->ACTIVO_TIPO === 'equipo_auxiliar') {
            return EquipoAuxiliar::find($this->ACTIVO_ID);
        }
        return null;
    }

    public function reporta()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_REPORTA', 'ID_USUARIO');
    }

    public function cierra()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_CIERRA', 'ID_USUARIO');
    }

    /** Tipo de mantenimiento requerido (Seccion 3 del acta). */
    public static function tiposMantenimiento(): array
    {
        return [
            'PREVENTIVO' => 'Preventivo',
            'CORRECTIVO' => 'Correctivo',
        ];
    }

    /**
     * El reporte en una línea — "RF-00028 · 12/09/2026 · Nombre de quien reportó" — para el
     * aviso sobre el estado del equipo en /admin/equipos ($falla->resumen). Lo lleva también
     * la respuesta de crear, para que la fila lo muestre sin recargar.
     */
    public function getResumenAttribute(): string
    {
        return implode(' · ', array_filter([
            $this->CODIGO_REPORTE,
            $this->FECHA_EMISION?->format('d/m/Y'),
            $this->NOMBRE_REPORTA,
        ]));
    }

    /**
     * Como se nombra el activo de un reporte en el encabezado de los modales de cierre
     * (equipos, auxiliares y el modulo de Fallas):
     *   · equipo:  su identificador — placa > serial > codigo > marca y modelo;
     *   · detalle: "TIPO · MARCA MODELO".
     * Fuente unica: antes cada pantalla armaba el identificador por su cuenta.
     */
    public static function datosActivo(Equipo|EquipoAuxiliar|null $activo): array
    {
        if (!$activo) {
            return ['equipo' => '', 'detalle' => ''];
        }

        $esAux       = $activo instanceof EquipoAuxiliar;
        $marcaModelo = trim(($activo->MARCA ?? '') . ' ' . ($activo->MODELO ?? ''));
        $tipo        = $esAux
            ? (EquipoAuxiliar::tiposLabel()[$activo->TIPO] ?? $activo->TIPO)
            : $activo->tipo?->nombre;
        $ident       = $esAux
            ? ($activo->SERIAL ?: $activo->CODIGO_INTERNO)
            : ($activo->documentacion?->PLACA ?: ($activo->SERIAL_CHASIS ?: $activo->CODIGO_PATIO));

        return [
            'equipo'  => $ident ?: $marcaModelo,
            'detalle' => implode(' · ', array_filter([$tipo, $marcaModelo])),
        ];
    }

    /**
     * Respuesta 409 cuando se intenta cambiar a mano el estado de un activo que tiene un reporte
     * de falla ABIERTO: ese estado lo gobierna el reporte y el front abre el modal de cierre con
     * estos datos. Fuente única para equipos, auxiliares y la API móvil. Con $activo va también
     * el encabezado del modal (datosActivo); la API móvil no lo usa y no lo recibe.
     */
    public static function respuestaReporteAbierto(self $falla, Equipo|EquipoAuxiliar|null $activo = null): \Illuminate\Http\JsonResponse
    {
        $datos = [
            'id'     => $falla->ID_FALLA,
            'codigo' => $falla->CODIGO_REPORTE,
            'tipo'   => $falla->TIPO_REPORTE,
        ];

        return response()->json([
            'success'       => false,
            'message'       => 'Este equipo tiene un reporte de falla abierto. Para cambiar su estado debes cerrar el reporte.',
            'falla_abierta' => $activo ? $datos + self::datosActivo($activo) : $datos,
        ], 409);
    }
}
