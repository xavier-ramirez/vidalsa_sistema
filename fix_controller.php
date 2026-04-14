EN EL GRAFICO:tire_repair
Caucho por Frente — Desglose por Modelo — todos los registros AL FIAL SALE ESTO VEO QUE LOS COLORES EN LOS TOTALES SON
OSCUROS CON LETRAS BLANCAS PERO EN:265/70R17
12 Un
295/80 R22.5 D
4 Un
9.5R17.5
2 Un
7.50R16 PERO DEBAJO D ELOS GRAFICO S DE CADA BARRA SAELE ESO COLORES CLASRO EN EL EL TIPO295/80 R22.5 D:
4
7.50R16: Y LA LETRA NOS ES BLANCA ARRGLE ESO
agriculture
Datos del Equipo
Dispositivo: GPS-CAMION SF132449 S/S
Ubicación: Satélite Beidou/RPM/Altitud57m/Señal47%
Longitud y Latitud: -63.144613,9.699745
Tiempo de actualización: 2026-04-13 22:14:11(Offline)
Tiempo de posicionamiento: 2026-04-12 22:14:11
Velocidad en tiempo real: 0km/h(Señal:47%)
Parada: 1D8H47M
Kilometraje total: 297.5.3km
Km Total: 0km
Combustible: T:25L/A:25L
Estado: ACC OFF 23M35S/Voltaje 23.0V
Vía a Rincón de Monagas, Maturín, Parroquia Las Cocuizas, Municipio Maturín
<?php
$file = 'app/Http/Controllers/ConsumiblesController.php';
$content = file_get_contents($file);

// Fix 1: Remove the "public     public" duplicate
$content = str_replace(
    "    public     public function guardarLote(Request \$request)",
    "    public function guardarLote(Request \$request)",
    $content
);

// Fix 2: Remove the orphaned duplicate block starting at line 197
// The orphaned part starts with: })'ESTADO_EQUIPO'
// and ends with: return redirect()->route('consumibles.index')->with('success', $mensaje);\n    }
$orphanStart = "\n    }'ESTADO_EQUIPO'   => 'PENDIENTE',\n                ]);\n                \$insertados++;\n            }\n\n            DB::commit();\n        } catch (\\Exception \$e) {\n            DB::rollBack();\n            return back()->withErrors(['error' => 'Error al guardar: ' . \$e->getMessage()])->withInput();\n        }\n\n        // Invalidar caché de gráficos — los datos cambiaron\n        Cache::increment('consumibles_data_version');\n\n        \$mensaje = \"\$insertados registros cargados exitosamente. Usa el botón 'Match Automático' para identificar los equipos.\";\n\n        if (\$request->expectsJson()) {\n            return response()->json([\n                'message' => \$mensaje,\n                'insertados' => \$insertados,\n                'redirect' => route('consumibles.index'),\n            ]);\n        }\n\n        return redirect()->route('consumibles.index')->with('success', \$mensaje);\n    }\n";

// Replace with just the closing brace
$content = str_replace($orphanStart, "\n    }\n\n", $content);

// Fix 3: Re-insert the cargar() method before guardarLote (it was deleted by the bad replacement)
// Check if cargar() still exists
if (strpos($content, 'public function cargar()') === false) {
    $cargarMethod = <<<'PHP'
    // ══════════════════════════════════════════════════════════════
    // CARGA DE LOTE — Formulario
    // ══════════════════════════════════════════════════════════════
    public function cargar()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        $tipos  = Consumible::tiposLabel();
        $unidades = ['LITROS' => 'Litros', 'GALONES' => 'Galones', 'UNIDADES' => 'Unidades', 'KG' => 'Kg'];

        return view('admin.consumibles.cargar', compact('frentes', 'tipos', 'unidades'));
    }

    // ══════════════════════════════════════════════════════════════
    // GUARDAR LOTE
    // ══════════════════════════════════════════════════════════════

PHP;
    $content = str_replace(
        "    public function guardarLote(Request \$request)",
        $cargarMethod . "    public function guardarLote(Request \$request)",
        $content
    );
    echo "Re-inserted cargar() method.\n";
}

file_put_contents($file, $content);

// Verify syntax
$result = shell_exec('php -l ' . $file . ' 2>&1');
echo "Syntax check: $result\n";
