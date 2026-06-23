@extends('errors.layout')
{{-- code vacío a propósito: en login el "419" no le dice nada al usuario; basta el mensaje.
     En su lugar un badge con reloj como ancla visual del aviso. --}}
@section('code', '')
@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="9"></circle>
    <path d="M12 7.5V12l3 2"></path>
</svg>
@endsection
@section('title', 'Tu sesión expiró')
@section('message', 'Por seguridad, ingresá de nuevo para continuar.')
@section('cta', 'Ingresar')
