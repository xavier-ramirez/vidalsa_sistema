@extends('errors.layout')
@section('code', '403')
@section('title', 'Acceso denegado')
@section('message', 'No tienes permiso para acceder a esta sección. Si crees que es un error, contacta al administrador.')
@auth
    {{-- Con sesión abierta el botón lleva al menú (el login lo mandaría allí igual, pero
         «Ir al inicio de sesión» confundía a quien ya entró). --}}
    @section('cta', 'Volver al menú')
    @section('href', route('menu'))
    {{-- Si el administrador le da el permiso con esta página abierta, se recarga sola: pregunta
         su huella de acceso (Usuario::huellaAcceso, la de /version-vistas) cada 5 s con la
         pestaña a la vista y al volver a ella. Esta página no lleva el JS de la app
         (navegacion.js), por eso va aquí. --}}
    @section('script')
    <script>
    (function () {
        var inicial = @json(auth()->user()->huellaAcceso());
        var pidiendo = false;
        function revisar() {
            if (pidiendo || document.hidden) return;
            pidiendo = true;
            fetch(@json(route('version.vistas')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (j) {
                    pidiendo = false;
                    if (j && j.a && j.a !== inicial) window.location.reload();
                });
        }
        setInterval(revisar, 5000);
        document.addEventListener('visibilitychange', revisar);
    })();
    </script>
    @endsection
@endauth
