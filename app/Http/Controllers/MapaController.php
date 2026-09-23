<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Services\Gps51Service;
use Illuminate\Http\Request;

/**
 * Modulo de Mapa.
 *
 * Pagina nueva abierta desde el boton "Mapa" del tablero (/menu): mapa base (Leaflet) con las
 * capas de estados, municipios, Faja/bloques petroleros, los proyectos (frentes) y los EQUIPOS
 * con GPS (posición real, vía los enlaces compartidos de GPS51 — ver Gps51Service).
 */
class MapaController extends Controller
{
    use \App\Traits\ExcelLogoCorporativo;

    public function index()
    {
        // Los "proyectos" del mapa SON los frentes de trabajo: al vincular una ubicación se
        // elige uno de estos (no se crean proyectos a mano). Se pasan todos, con el nombre ya
        // limpio (el modelo aplica MojibakeFix), ordenados alfabéticamente para el selector.
        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            ->map(fn ($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE])
            ->values();

        // La GESTIÓN de proyectos en el mapa (crear/asociar puntos, dibujar la línea, borrar
        // puntos/proyectos) depende del PERMISO 'super.admin' (no del rol). El resto ve el mapa
        // en modo consulta. Las rutas de escritura también están gateadas en routes/web.php.
        $puedeEditar = (bool) optional(auth()->user())->can('super.admin');

        return view('mapa', ['frentes' => $frentes, 'puedeEditar' => $puedeEditar]);
    }

    /**
     * Capa "Equipos" del mapa, paso 1: los equipos con enlace de GPS51 que el usuario puede ver
     * (sus frentes, menos los bloqueados) con la posición que YA está en caché, y en `pendientes`
     * los que falta consultar. Responde al instante: GPS51 es lento y las posiciones que faltan
     * las pide el mapa por tandas a equiposGpsPosiciones(). El authcode NO viaja al navegador.
     */
    public function equiposGps(Request $request)
    {
        // Un enlace de GPS51 del que no se puede sacar el authcode (otro formato) no se lista: nunca
        // tendría posición y quedaría para siempre como "cargando".
        $equipos = $this->equiposConGps($request)
            ->with(['frenteActual:ID_FRENTE,NOMBRE_FRENTE', 'documentacion:ID_EQUIPO,PLACA', 'tipo:id,nombre'])
            ->get(['ID_EQUIPO', 'id_tipo_equipo', 'CODIGO_PATIO', 'NUMERO_ETIQUETA', 'MARCA', 'MODELO',
                   'SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'LINK_GPS', 'ID_FRENTE_ACTUAL', 'ESTADO_OPERATIVO'])
            ->filter(fn ($e) => Gps51Service::authcode($e->LINK_GPS) !== null);

        $enCache = Gps51Service::enCache($equipos->map(fn ($e) => Gps51Service::authcode($e->LINK_GPS))->all());

        $pendientes = [];
        $items = $equipos->map(function ($e) use ($enCache, &$pendientes) {
            $authcode = Gps51Service::authcode($e->LINK_GPS);
            $gps = $authcode ? ($enCache[$authcode] ?? null) : null;
            if ($authcode && $gps === null) {
                $pendientes[] = $e->ID_EQUIPO;
            }
            return [
                'id'            => $e->ID_EQUIPO,
                'placa'         => optional($e->documentacion)->PLACA,
                'codigo'        => $e->CODIGO_PATIO,
                'etiqueta'      => $e->NUMERO_ETIQUETA,
                'serial_chasis' => $e->SERIAL_CHASIS,
                'serial_motor'  => $e->SERIAL_DE_MOTOR,
                'tipo'          => optional($e->tipo)->nombre,
                'marca'         => $e->MARCA,
                'modelo'        => $e->MODELO,
                'estado'        => $e->ESTADO_OPERATIVO,
                'frente'        => $e->frenteActual
                    ? ['id' => $e->frenteActual->ID_FRENTE, 'nombre' => $e->frenteActual->NOMBRE_FRENTE]
                    : null,
                'gps'           => $gps,   // ver Gps51Service::normalizar(); null = aún sin consultar
            ];
        })->values();

        return response()->json(['equipos' => $items, 'pendientes' => $pendientes, 'lote' => Gps51Service::LOTE]);
    }

    /**
     * Capa "Equipos", paso 2: la posición de una tanda de equipos (hasta Gps51Service::LOTE ids),
     * consultando a GPS51 las que no están en caché. Devuelve { id: gps } — gps null si GPS51 no
     * respondió (el mapa lo reintenta en la siguiente vuelta).
     */
    public function equiposGpsPosiciones(Request $request)
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'max:' . Gps51Service::LOTE],
            'ids.*' => ['integer'],
        ]);

        $equipos = $this->equiposConGps($request)->whereIn('ID_EQUIPO', $data['ids'])->get(['ID_EQUIPO', 'LINK_GPS']);
        $posiciones = Gps51Service::posiciones($equipos->map(fn ($e) => Gps51Service::authcode($e->LINK_GPS))->all());

        $out = [];
        foreach ($equipos as $e) {
            $authcode = Gps51Service::authcode($e->LINK_GPS);
            $out[$e->ID_EQUIPO] = $authcode ? ($posiciones[$authcode] ?? null) : null;
        }

        return response()->json(['posiciones' => (object) $out]);
    }

    /** Dirección escrita de la posición actual de un equipo (la ficha del mapa la pide al abrirse). */
    public function equipoGpsDireccion(Request $request, int $id)
    {
        $equipo = $this->equiposConGps($request)->whereKey($id)->first(['ID_EQUIPO', 'LINK_GPS']);
        abort_unless($equipo, 404);

        $authcode = Gps51Service::authcode($equipo->LINK_GPS);
        $pos = $authcode ? (Gps51Service::posiciones([$authcode])[$authcode] ?? null) : null;
        if (!$pos || empty($pos['ok'])) {
            return response()->json(['direccion' => null]);
        }

        return response()->json(['direccion' => Gps51Service::direccion($authcode, $pos['lat'], $pos['lng'])]);
    }

    /**
     * Excel de la capa Equipos: lo que el panel del mapa tiene filtrado (`ids`, los que se ven en
     * la lista), con su frente, la dirección escrita de donde está AHORA y cómo identificarlo
     * (placa; si no tiene, serial de chasis; si no, serial de motor). Mismo encabezado y estilo
     * que la "Exportación de Data" de Equipos (trait ExcelLogoCorporativo).
     *
     * Las posiciones salen de la caché que el mapa acaba de llenar (dura EQ_REFRESCO = 2 min); si
     * caducó se usa la última conocida, y solo lo que no tiene ninguna se pide a GPS51 con tope de
     * tiempo (en frío, 130 equipos tardan ~40 s). Las direcciones van todas juntas en una
     * consulta (Gps51Service::direcciones).
     */
    public function exportarEquiposGps(Request $request)
    {
        $data = $request->validate(['ids' => ['nullable', 'string', 'max:20000']]);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($data['ids'] ?? '')))));
        // Se mandaron ids pero ninguno sirve: no se exporta nada (vacío NO es "todo").
        abort_if(trim((string) ($data['ids'] ?? '')) !== '' && !$ids, 404, 'No hay equipos con GPS para exportar.');

        // El filtro de frentes del usuario (equiposConGps) manda: los ids solo pueden RECORTAR.
        $equipos = $this->equiposConGps($request)
            ->when($ids, fn ($q) => $q->whereIn('ID_EQUIPO', $ids))
            ->with(['frenteActual:ID_FRENTE,NOMBRE_FRENTE', 'documentacion:ID_EQUIPO,PLACA', 'tipo:id,nombre'])
            ->get(['ID_EQUIPO', 'id_tipo_equipo', 'MARCA', 'MODELO', 'SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'LINK_GPS', 'ID_FRENTE_ACTUAL'])
            ->filter(fn ($e) => Gps51Service::authcode($e->LINK_GPS) !== null)
            ->values();
        abort_if($equipos->isEmpty(), 404, 'No hay equipos con GPS para exportar.');

        @set_time_limit(120);
        $acs = $equipos->map(fn ($e) => Gps51Service::authcode($e->LINK_GPS))->all();
        // 1) la posición fresca (la que el mapa acaba de pedir), 2) si caducó, la última conocida
        // (la columna ÚLTIMA SEÑAL dice de cuándo es) y 3) solo lo que no tiene ninguna se pide a
        // GPS51, con tope de tiempo. Lo que ni así responde sale como "Sin respuesta de GPS51".
        $pos = Gps51Service::enCache($acs);
        $pos += Gps51Service::ultimasConocidas(array_values(array_diff($acs, array_keys($pos))));
        $hasta = microtime(true) + 20;
        foreach (array_chunk(array_values(array_diff($acs, array_keys($pos))), Gps51Service::LOTE) as $tanda) {
            if (microtime(true) > $hasta) break;
            $pos += array_filter(Gps51Service::posiciones($tanda));
        }

        $conPunto = fn ($p) => $p && !empty($p['ok']) && empty($p['fuera_de_venezuela']);
        $conPosicion = collect($pos)->filter($conPunto);
        $puntos = $conPosicion->map(fn ($p) => [$p['lat'], $p['lng']])->values()->all();
        // Las direcciones se piden con el enlace de señal más reciente (el más seguro de seguir
        // vigente); si ese ya no sirve (no da ninguna), se prueba con los dos siguientes.
        $dirs = [];
        foreach ($conPosicion->sortByDesc('ultima_senal')->keys()->take(3) as $ac) {
            if ($dirs = Gps51Service::direcciones($ac, $puntos)) break;
        }

        $filas = $equipos->map(function ($e) use ($pos, $dirs, $conPunto) {
            $p = $pos[Gps51Service::authcode($e->LINK_GPS)] ?? null;
            $placa = trim((string) optional($e->documentacion)->PLACA);
            [$ident, $identPor] = $placa !== '' ? [$placa, 'PLACA']
                : (trim((string) $e->SERIAL_CHASIS) !== '' ? [trim($e->SERIAL_CHASIS), 'SERIAL DE CHASIS']
                : (trim((string) $e->SERIAL_DE_MOTOR) !== '' ? [trim($e->SERIAL_DE_MOTOR), 'SERIAL DE MOTOR'] : ['—', '—']));
            return [
                'frente'   => $e->frenteActual ? mb_strtoupper(trim($e->frenteActual->NOMBRE_FRENTE)) : 'SIN FRENTE',
                'tipo'     => $e->tipo ? mb_strtoupper($e->tipo->nombre) : '—',
                'marca'    => mb_strtoupper($e->MARCA ?: '—'),
                'modelo'   => mb_strtoupper($e->MODELO ?: '—'),
                'ident'    => mb_strtoupper($ident),
                'identPor' => $identPor,
                'estado'   => self::estadoGps($p),
                'senal'    => ($p && !empty($p['ultima_senal']))
                    ? \Carbon\Carbon::createFromTimestampMs($p['ultima_senal'])->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—',
                'dir'      => $conPunto($p) ? ($dirs[Gps51Service::claveDireccion($p['lat'], $p['lng'])] ?? 'DIRECCIÓN NO DISPONIBLE') : '—',
                'lat'      => $conPunto($p) ? $p['lat'] : null,
                'lng'      => $conPunto($p) ? $p['lng'] : null,
            ];
        })->sortBy(fn ($f) => $f['frente'] . '|' . $f['tipo'] . '|' . $f['ident'], SORT_NATURAL)->values();

        $frentes = $filas->pluck('frente')->unique();
        $sub = $frentes->count() === 1 ? 'PROYECTO: "' . $frentes->first() . '"' : 'UBICACIÓN ACTUAL SEGÚN GPS';

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getProperties()->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Equipos con GPS')->setCompany('Constructora Vidalsa 27, C.A.');
        $libro->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Equipos con GPS');
        foreach (['A' => 8, 'B' => 25, 'C' => 26, 'D' => 16, 'E' => 18, 'F' => 22, 'G' => 18, 'H' => 20, 'I' => 17, 'J' => 70, 'K' => 22] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $this->encabezadoCorporativo($hoja, "EQUIPOS CON GPS\n" . $sub, 'H', 'I', 'K');
        $ultima = $this->cabeceraTablaCorporativa($hoja, ['N°', 'FRENTE', 'TIPO', 'MARCA', 'MODELO', "PLACA / SERIAL", "IDENTIFICADO\nPOR", 'ESTADO DEL GPS', "ÚLTIMA\nSEÑAL", 'DIRECCIÓN (UBICACIÓN REAL)', 'COORDENADAS']);

        $r = 6;
        foreach ($filas as $i => $f) {
            $hoja->fromArray([str_pad($i + 1, 2, '0', STR_PAD_LEFT), $f['frente'], $f['tipo'], $f['marca'], $f['modelo'], $f['ident'],
                              $f['identPor'], $f['estado'], $f['senal'], $f['dir']], null, 'A' . $r);
            // Coordenadas como enlace al mapa (se abre en Google Maps).
            if ($f['lat'] !== null) {
                $hoja->setCellValue('K' . $r, number_format($f['lat'], 5, '.', '') . ', ' . number_format($f['lng'], 5, '.', ''));
                $hoja->getCell('K' . $r)->getHyperlink()->setUrl('https://www.google.com/maps?q=' . $f['lat'] . ',' . $f['lng']);
                $hoja->getStyle('K' . $r)->getFont()->setUnderline(true)->getColor()->setARGB('FF0563C1');
            } else {
                $hoja->setCellValue('K' . $r, '—');
            }
            $r++;
        }
        $fin = $r - 1;

        // Estilo de las filas EN LOTE, como en Equipos: centradas salvo tipo y dirección, alto 30 y cebra.
        $hoja->getStyle("A6:{$ultima}{$fin}")->getAlignment()->setWrapText(true)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        foreach (['C', 'J'] as $col) {
            $hoja->getStyle("{$col}6:{$col}{$fin}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }
        for ($f = 6; $f <= $fin; $f++) {
            // 30 pt como en Equipos; una dirección larga pide más (≈75 letras por línea en la
            // columna J): Excel no ajusta solo el alto de una fila que ya lo trae fijado.
            $lineas = (int) ceil(mb_strlen((string) $hoja->getCell('J' . $f)->getValue()) / 75);
            $hoja->getRowDimension($f)->setRowHeight(max(30, $lineas * 13 + 6));
            $hoja->getStyle("A{$f}:{$ultima}{$f}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB((($f - 5) % 2 === 0) ? 'FFF1F5F9' : 'FFFFFFFF');
        }
        // En línea en verde y en negrita (lo que más se busca en la hoja).
        foreach ($filas as $i => $f) {
            if ($f['estado'] === 'EN LÍNEA') {
                $hoja->getStyle('H' . ($i + 6))->getFont()->setBold(true)->getColor()->setARGB('FF15803D');
            }
        }

        // Fila de total, como en Equipos (más cuántos están en línea).
        $enLinea = $filas->where('estado', 'EN LÍNEA')->count();
        $hoja->setCellValue('A' . $r, 'TOTAL');
        $hoja->mergeCells("B{$r}:E{$r}");
        $hoja->setCellValue('B' . $r, $filas->count() . ' EQUIPOS LISTADOS · ' . $enLinea . ' EN LÍNEA');
        $hoja->mergeCells("F{$r}:{$ultima}{$r}");
        $hoja->getStyle("A{$r}:{$ultima}{$r}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $hoja->getStyle("A{$r}:B{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("A{$r}:{$ultima}{$r}")->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF1E293B');
        $hoja->getStyle("A{$r}:{$ultima}{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
        $hoja->getRowDimension($r)->setRowHeight(28);
        $hoja->getStyle("A5:{$ultima}{$r}")->applyFromArray(self::bordeFinoCorporativo());
        $hoja->setSelectedCell('A1');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $archivo = tempnam(sys_get_temp_dir(), 'gps_cvidalsa_');
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($libro, 'Xlsx')->save($archivo);
        $nombre = 'Equipos_con_GPS_' . date('Y-m-d_H-i') . '.xlsx';
        return response()->download($archivo, $nombre, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    /** Estado del GPS en palabras para el Excel (los mismos casos que distingue el mapa). */
    private static function estadoGps(?array $p): string
    {
        if (!$p) return 'SIN RESPUESTA DE GPS51';
        if (empty($p['ok'])) {
            return ['enlace_vencido' => 'ENLACE DE GPS VENCIDO', 'sin_posicion' => 'SIN POSICIÓN REGISTRADA'][$p['motivo'] ?? ''] ?? 'ENLACE DE GPS INVÁLIDO';
        }
        if (!empty($p['fuera_de_venezuela'])) return 'POSICIÓN FUERA DE VENEZUELA';
        return !empty($p['en_linea']) ? 'EN LÍNEA' : 'SIN CONEXIÓN';
    }

    /** Equipos con enlace de GPS51 dentro de los frentes que el usuario puede ver. */
    private function equiposConGps(Request $request)
    {
        $query = Equipo::query()->where('LINK_GPS', 'like', '%gps51%');
        return $request->user()->aplicarScopeFrentesEquipos($query);
    }
}
