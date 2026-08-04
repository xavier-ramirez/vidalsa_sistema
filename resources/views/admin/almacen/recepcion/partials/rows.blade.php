{{-- Filas de la tabla de notas de entrega / traspasos. $traspasos = paginator de
     Traspaso con almacenes cargados via eager loading. El detalle de materiales NO
     se lista aquí: se revisa al abrir la nota (modal de detalle/recepción). --}}

@forelse($traspasos as $t)
    @php
        $e = \App\Models\Traspaso::ESTADOS_META[$t->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
        $neNumero = $t->REFERENCIA ?: $t->NUMERO;
        // Antigüedad desde el envío para el indicador de color.
        // OJO con el orden: en Carbon 3 diffInHours() devuelve un FLOAT CON SIGNO, así que
        // `now()->diffInHours($fechaPasada)` da NEGATIVO (-120 para 5 días) y caía siempre en
        // la rama "< 24" → todas las notas salían con el punto verde y los puntos ámbar/rojo
        // eran código muerto, contradiciendo al KPI "Urgentes +3d" (que se calcula en SQL).
        // El (int) además es obligatorio para el intdiv() de abajo, que no acepta float.
        $horasDesdeEnvio = $t->FECHA_ENVIO ? (int) $t->FECHA_ENVIO->diffInHours(now()) : null;
    @endphp
    <tr data-id="{{ $t->ID_TRASPASO }}">
        <td style="font-family:monospace;font-weight:800;font-size:13px;color:#0f172a;white-space:nowrap;letter-spacing:.3px;">
            {{ $neNumero }}
        </td>
        {{-- Trayecto origen → destino lado a lado. SIN las etiquetas "Origen"/"Destino"
             encima de cada nombre (pedido del cliente): la flecha `east` ya marca la
             dirección, y el título de la columna lo dice. Origen en gris, destino en azul
             y con más peso, que es la jerarquía que antes daban las etiquetas. --}}
        {{-- La columna es ancha, así que origen y frente van en UNA sola línea (nowrap): antes
             un frente largo como "TRANSVERSALES AYACUCHO" se partía en dos renglones y
             desalineaba la fila. Si algún nombre no cupiera, se recorta con ellipsis en vez
             de romperse. --}}
        <td class="tr-ruta-dest">
            <div style="display:flex;align-items:center;justify-content:center;gap:12px;">
                {{-- El origen es UN solo dato: sin el <span> de la etiqueta ya no hace falta
                     el div flex-column que los apilaba. El destino sí lo conserva (nombre del
                     frente + almacén debajo). --}}
                <span class="tr-ruta-origen" style="text-align:center;font-weight:600;color:#4a5568;font-size:13px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ optional($t->almacenOrigen)->NOMBRE ?: '—' }}</span>
                <i class="material-icons" style="font-size:18px;color:#cbd5e0;flex-shrink:0;" title="Origen → Destino">east</i>
                {{-- Destino = el FRENTE al que se envió (Traspaso::getNombreDestinoAttribute),
                     no el almacén: un almacén de PROYECTO sirve a VARIOS frentes, así que
                     todas las notas salían con el mismo nombre y no se distinguía a quién
                     iba cada una. Mismo criterio que la columna Destino de las salidas.
                     El almacén físico se conserva como segunda línea, salvo que repita lo
                     mismo que el frente (frenteDestinoEsRedundante). --}}
                <div style="display:flex;flex-direction:column;align-items:center;min-width:0;text-align:center;">
                    <span class="tr-ruta-frente" style="font-weight:700;color:var(--maquinaria-dark-blue,#1e3a5f);font-size:13px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;">{{ $t->nombre_destino }}</span>
                    @if($t->frenteDestino && !$t->frenteDestinoEsRedundante())
                        {{-- Prefijo "Almacén": el nombre suelto debajo del frente se leía como
                             si fuera otro frente. Un almacén de PROYECTO sirve a VARIOS frentes,
                             así que ese segundo renglón se repite en notas de frentes distintos
                             (p. ej. PATIO EL TIGRE) y sin la palabra no se entendía qué era. --}}
                        <span class="tr-ruta-alm" style="font-size:10.5px;color:#94a3b8;font-weight:600;line-height:1.2;margin-top:1px;white-space:nowrap;" title="Almacén que recibe físicamente">Almacén {{ optional($t->almacenDestino)->NOMBRE }}</span>
                    @endif
                </div>
            </div>
        </td>
        <td style="text-align:center;">
            <span class="estado-pill" style="background:{{ $e[1] }};color:{{ $e[2] }};">{{ $e[0] }}</span>
        </td>
        <td style="font-size:12px;color:#475569;white-space:nowrap;">
            @if($t->FECHA_ENVIO)
                {{ $t->FECHA_ENVIO->format('d/m/Y h:i A') }}
                @if($t->esEnviado() && $horasDesdeEnvio !== null)
                    {{-- El punto toma el color del ESTADO ($e[2], el mismo del pill de la columna
                         de al lado) en vez de su propia escala verde/ámbar/roja: en la misma fila
                         convivían dos colores distintos para la misma nota y se leía como si
                         dijeran cosas contradictorias. La antigüedad se sigue diciendo con
                         palabras ("hace 3 semanas") y en el title. --}}
                    <div class="tr-fecha-rel" style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:2px;">
                        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:{{ $e[2] }};"
                              title="{{ $horasDesdeEnvio < 24 ? 'Hace menos de 24h' : 'Hace '.intdiv($horasDesdeEnvio, 24).' día(s)' }}"></span>
                        <span style="font-size:10.5px;color:#94a3b8;">{{ $t->FECHA_ENVIO->locale('es')->diffForHumans() }}</span>
                    </div>
                @endif
            @else
                —
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="4" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">inbox</i>
            No hay notas de entrega que coincidan con tu vista actual.
        </td>
    </tr>
@endforelse
