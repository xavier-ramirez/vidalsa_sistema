{{-- ════════════════════════════════════════════════════════════════════════
     Encabezado de módulo — el <section class="page-title-card"> que estaba
     copiado en 16 vistas, cada una reescribiendo el mismo markup.

     Uso mínimo:
        @include('admin.partials.page_header', ['titulo' => 'Gestión de Frentes'])

     Parámetros (solo $titulo es obligatorio):
        titulo        texto del <h1>. Se imprime con {!! !!} porque varias vistas
                      le pasan HTML ya armado (un badge, un <span> con estilo).
        align         'left' | 'center'. Si no se pasa, no se emite text-align.
        margin        margen del <section>.
        padding       padding del <section>.
        extra         CSS suelto que se concatena al style del <section>
                      (p.ej. 'width:98%;max-width:1600px;').
        tituloEstilo  CSS del <span> del título (font-size, font-family…).
        tituloId      id del <span>, para las vistas que lo cambian por JS.
        h1Estilo      CSS del <h1>.
        despuesTitulo HTML que va DENTRO del <h1>, como hermano del título (el badge
                      de conteo de Usuarios). Tiene que quedar ahí y no dentro del
                      span: la regla .page-title:has(#user-count-badge) del CSS
                      global depende de esa estructura.
        acciones      NOMBRE de una vista a incluir a la derecha del título
                      (filtros, buscador, botones). Si viene, se usa el
                      contenedor flex; si no, solo el <h1>.
        separador     true para la barrita vertical entre título y acciones.

     IMPORTANTE: cada propiedad se emite SOLO si se pasa. No se rellenan valores
     por defecto (ni padding:0 ni text-align) porque varias reglas de
     estilos_globales.css tocan .page-title-card dentro de media queries; emitir
     un inline que el original no tenía cambiaría quién gana en móvil.
═══════════════════════════════════════════════════════════════════════════ --}}
@php
    // OJO con empty(): en PHP empty('0') es TRUE, así que un 'padding' => '0'
    // —que varias vistas sí pasan— se descartaba en silencio y el inline no salía.
    // Se compara contra '' explícitamente.
    $phPuesto = fn ($v) => isset($v) && $v !== '';

    $phEstilo = '';
    if ($phPuesto($align ?? null))   { $phEstilo .= 'text-align:' . $align . ';'; }
    if ($phPuesto($margin ?? null))  { $phEstilo .= 'margin:' . $margin . ';'; }
    if ($phPuesto($padding ?? null)) { $phEstilo .= 'padding:' . $padding . ';'; }
    if ($phPuesto($extra ?? null))   { $phEstilo .= $extra; }

    $phTituloEstilo = 'color:#000;' . ($tituloEstilo ?? '');
@endphp
<section class="page-title-card"@if ($phEstilo) style="{{ $phEstilo }}"@endif>
    @if ($phPuesto($acciones ?? null))
        <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
            <h1 class="page-title" style="margin:0;{{ $h1Estilo ?? '' }}">
                <span class="page-title-line2" style="{{ $phTituloEstilo }}"@if ($phPuesto($tituloId ?? null)) id="{{ $tituloId }}"@endif>{!! $titulo !!}</span>
            </h1>
            @if (! empty($separador))
                <span aria-hidden="true"
                    style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
            @endif
            @include($acciones)
        </div>
    @else
        <h1 class="page-title"@if ($phPuesto($h1Estilo ?? null)) style="{{ $h1Estilo }}"@endif>
            <span class="page-title-line2" style="{{ $phTituloEstilo }}"@if ($phPuesto($tituloId ?? null)) id="{{ $tituloId }}"@endif>{!! $titulo !!}</span>
            @if ($phPuesto($despuesTitulo ?? null)){!! $despuesTitulo !!}@endif
        </h1>
    @endif
</section>
