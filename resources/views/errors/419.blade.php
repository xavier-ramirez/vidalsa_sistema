@extends('errors.layout')
{{-- code vacío a propósito: en login el "419" no le dice nada al usuario; basta el mensaje. --}}
@section('code', '')
@section('title', 'Tu sesión expiró')
@section('message', 'Por seguridad, ingresá de nuevo para continuar.')
@section('cta', 'Ingresar')
