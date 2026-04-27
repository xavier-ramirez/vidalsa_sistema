@extends('layouts.estructura_base')

@section('title', 'Tablero de Control')

@section('content')

<style>
    /* Forzar fondo blanco solo en el dashboard */
    body, .main-viewport {
        background-color: #ffffff !important;
    }
</style>

<!-- SVG Background unificado -->
@include('partials.background_svg')

<style>
    /* ── Hero del dashboard (estilo FLEETARCHITECT) ── */
    .menu-hero {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        height: 320px;
        box-shadow: 0 18px 40px -16px rgba(15, 23, 42, 0.35);
        margin: 0 0 24px 0;
        background: #0b1c30;
    }
    .menu-hero-stripes {
        position: absolute;
        inset: 0;
        display: flex;
        gap: 6px;
        background: #0b1c30;
        z-index: 0;
    }
    .menu-hero-stripes > div {
        flex: 1 1 0;
        height: 100%;
        overflow: hidden;
    }
    .menu-hero-stripes img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.8s cubic-bezier(0.16, 1, 0.3, 1), filter 0.5s ease;
        /* Previene badges flotantes tipo "Visual Search" que ponen algunos browsers/extensiones */
        pointer-events: none;
        user-select: none;
        -webkit-user-drag: none;
    }
    .menu-hero-stripes > div:hover img {
        transform: scale(1.08);
        filter: brightness(1.1);
    }
    /* Panel con clip especial: mantiene el desplazamiento horizontal para
       ocultar la marca de agua de Gemini IA en la esquina inferior derecha.
       Baseline definido en CSS (NO inline) para que el :hover lo pueda
       override sin guerra de especificidad. */
    .menu-hero-stripes > div.menu-hero-stripe-clip img {
        transform: scale(1.08) translateX(8%);
    }
    .menu-hero-stripes > div.menu-hero-stripe-clip:hover img {
        transform: scale(1.13) translateX(8%);
    }
    .menu-hero-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(
            to right,
            rgba(0, 8, 44, 0.92) 0%,
            rgba(0, 8, 44, 0.65) 45%,
            rgba(0, 8, 44, 0.35) 100%
        );
        pointer-events: none;
        z-index: 1;
    }
    .menu-hero-content {
        position: relative;
        z-index: 2;
        height: 100%;
        padding: 36px 44px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        max-width: 820px;
    }
    .menu-hero-title {
        color: #fff;
        font-weight: 900;
        font-size: 44px;
        line-height: 1.05;
        letter-spacing: -0.02em;
        margin: 0 0 12px 0;
        text-shadow: 0 4px 24px rgba(0,0,0,0.3);
    }
    .menu-hero-title .accent {
        color: #dce1ff;
        display: inline-block;
    }
    /* Stat a la derecha (solo desktop) */
    .menu-hero-stat {
        position: absolute;
        right: 44px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2;
        text-align: right;
        color: rgba(255,255,255,0.9);
    }
    .menu-hero-stat-label {
        font-size: 10px;
        letter-spacing: 0.3em;
        text-transform: uppercase;
        opacity: 0.65;
        margin-bottom: 4px;
    }
    .menu-hero-stat-value {
        font-size: 48px;
        font-weight: 900;
        line-height: 1;
        color: #fff;
    }
    .menu-hero-stat-caption {
        font-size: 11px;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        opacity: 0.7;
        margin-top: 4px;
    }

    @media (max-width: 900px) {
        .menu-hero { height: 240px; }
        .menu-hero-content { padding: 24px 28px; }
        .menu-hero-title { font-size: 28px; }
        .menu-hero-stat { display: none; }
    }
    @media (max-width: 520px) {
        .menu-hero { height: 200px; border-radius: 12px; }
        .menu-hero-content { padding: 20px; }
        .menu-hero-title { font-size: 22px; }
    }
    /* ── Aprovecha mas ancho horizontal en mobile: reducir padding del contenedor ── */
    @media (max-width: 768px) {
        .dashboard-container { padding: 8px 10px !important; }
    }
    @media (max-width: 480px) {
        .dashboard-container { padding: 6px 6px !important; }
        .menu-hero { border-radius: 10px; }
    }

    /* ── Wrapper grid: Salud Operacional + Alertas Documentos, responsive auto-fit ── */
    .cards-wrapper {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        align-items: stretch;
        width: 100%;
        box-sizing: border-box;
    }
    /* Los hijos directos ocupan toda la celda del grid y NO se expanden mas alla */
    .cards-wrapper > * {
        display: flex;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
    }
    .cards-wrapper > * > .salud-card,
    .cards-wrapper > * > .alertas-card,
    .cards-wrapper > .salud-card,
    .cards-wrapper > .alertas-card {
        width: 100%;
        height: 100%;
        min-width: 0;
        max-width: 100%;
        box-sizing: border-box;
    }
    /* Compactacion interna: reducir padding y gap dentro de cada card para bajar alto */
    .cards-wrapper > * .salud-card,
    .cards-wrapper .salud-card { padding: 16px 18px; gap: 18px; }
    .cards-wrapper .alertas-card { padding: 14px 16px; }
    @media (max-width: 1100px) {
        .cards-wrapper { grid-template-columns: 1fr; gap: 12px; }
    }

    /* ── Modal Alertas Documentos (reemplaza el panel desplegable) ── */
    .alertas-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
        z-index: 9500;
        align-items: center;
        justify-content: center;
        padding: 24px;
        animation: alertasFadeIn 0.2s ease-out;
    }
    .alertas-modal-overlay.open { display: flex; }
    .alertas-modal-content {
        background: white;
        width: 100%;
        max-width: 520px;
        max-height: 72vh;
        border-radius: 14px;
        box-shadow: 0 18px 40px -12px rgba(0,0,0,0.32);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: alertasSlideIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .alertas-modal-content .alertas-panel-header { padding: 12px 16px; }
    .alertas-modal-content .alertas-panel-toolbar { padding: 10px 16px 8px; }
    .alertas-modal-content .alertas-panel-body {
        flex: 1;
        overflow-y: auto;
        padding: 4px 14px 14px;
    }
    @keyframes alertasFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes alertasSlideIn { from { transform: translateY(14px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @media (max-width: 600px) {
        .alertas-modal-overlay { padding: 12px; }
        .alertas-modal-content { max-height: 94vh; }
    }

    /* ── Card "Salud Operacional" — ancho completo con grid horizontal espacioso ── */
    .salud-card {
        position: relative;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 28px;
        padding: 22px 28px;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -16px rgba(15, 23, 42, 0.18);
        overflow: hidden;
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease;
    }
    .salud-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 2px 4px rgba(15, 23, 42, 0.05), 0 18px 36px -18px rgba(15, 23, 42, 0.25);
    }
    .salud-card::before {
        content: '';
        position: absolute;
        right: -40px;
        top: -40px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: radial-gradient(circle at center, rgba(0, 103, 177, 0.12), transparent 70%);
        pointer-events: none;
    }
    .salud-main-block {
        position: relative;
        display: flex;
        align-items: center;
        gap: 16px;
        padding-right: 24px;
        border-right: 1px solid #cfe4f9;
        min-width: 240px;
    }
    .salud-icon {
        flex-shrink: 0;
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: #1e293b;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        box-shadow: 0 8px 20px -6px rgba(30, 41, 59, 0.55), inset 0 1px 0 rgba(255,255,255,0.25);
    }
    .salud-icon .material-icons { font-size: 28px; }
    .salud-body {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }
    .salud-label {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #1e3a8a;
    }
    .salud-main {
        display: flex;
        align-items: baseline;
        gap: 8px;
        flex-wrap: wrap;
    }
    .salud-percent {
        font-size: 28px;
        font-weight: 900;
        color: #0b1c30;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    .salud-percent-sub {
        font-size: 10.5px;
        font-weight: 800;
        color: #1e40af;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    /* Columna central: barra + leyenda horizontal — usa todo el ancho disponible */
    .salud-bar-wrap {
        display: flex;
        flex-direction: column;
        gap: 10px;
        min-width: 0;
    }
    .salud-bar {
        display: flex;
        height: 10px;
        border-radius: 999px;
        overflow: hidden;
        background: #e2e8f0;
        box-shadow: inset 0 1px 2px rgba(15,23,42,0.05);
    }
    .salud-bar span { display: block; height: 100%; transition: width 0.4s ease; }
    .salud-bar-ok    { background: linear-gradient(90deg, #10b981, #059669); }
    .salud-bar-maint { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .salud-bar-bad   { background: linear-gradient(90deg, #ef4444, #dc2626); }
    .salud-bar-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 22px;
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
    }
    .salud-bar-legend span { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
    .salud-bar-legend span::before { content: ''; width: 8px; height: 8px; border-radius: 50%; }
    .salud-bar-legend .leg-ok::before    { background: #10b981; }
    .salud-bar-legend .leg-maint::before { background: #f59e0b; }
    .salud-bar-legend .leg-bad::before   { background: #ef4444; }

    /* Columna derecha: 3 stats horizontales */
    .salud-stats-group {
        position: relative;
        display: grid;
        grid-template-columns: repeat(3, auto);
        gap: 18px;
        padding-left: 24px;
        border-left: 1px solid #cfe4f9;
    }
    .salud-stat {
        display: flex;
        flex-direction: column;
        align-items: center;
        min-width: 58px;
    }
    .salud-stat-value {
        font-size: 20px;
        font-weight: 900;
        color: #0b1c30;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    .salud-stat-name {
        display: flex;
        align-items: center;
        gap: 4px;
        margin-top: 4px;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #475569;
        white-space: nowrap;
    }
    .salud-stat-name::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }
    .salud-stat.ok    .salud-stat-name::before { background: #10b981; }
    .salud-stat.maint .salud-stat-name::before { background: #f59e0b; }
    .salud-stat.bad   .salud-stat-name::before { background: #ef4444; }

    @media (max-width: 900px) {
        .salud-card {
            grid-template-columns: auto minmax(0, 1fr);
            grid-template-rows: auto auto;
            gap: 8px 12px;
            padding: 10px 12px;
            border-radius: 14px;
        }
        .salud-main-block {
            grid-column: 1 / -1;
            padding-right: 0;
            border-right: none;
            padding-bottom: 8px;
            border-bottom: 1px solid #cfe4f9;
            min-width: 0;
            gap: 10px;
        }
        .salud-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
        }
        .salud-icon .material-icons { font-size: 20px; }
        .salud-label { font-size: 10px; letter-spacing: 0.08em; }
        .salud-body { gap: 3px; }
        .salud-main { gap: 6px; }
        .salud-percent { font-size: 22px; }
        .salud-percent-sub { font-size: 9.5px; }
        .salud-bar { height: 6px; }
        .salud-bar-wrap { gap: 6px; }
        .salud-stats-group {
            grid-column: 1 / -1;
            padding-left: 0;
            padding-top: 6px;
            border-left: none;
            border-top: none;
            justify-content: space-between;
            gap: 6px;
        }
        .salud-stat { min-width: 0; }
        .salud-stat-value { font-size: 16px; }
        .salud-stat-name { font-size: 8.5px; margin-top: 2px; }
    }
    @media (max-width: 520px) {
        .salud-card { padding: 8px 10px; gap: 6px 8px; }
        .salud-main-block { padding-bottom: 6px; gap: 8px; }
        .salud-icon { width: 34px; height: 34px; border-radius: 9px; }
        .salud-icon .material-icons { font-size: 18px; }
        .salud-percent { font-size: 20px; }
        .salud-bar { height: 5px; }
        .salud-stat-value { font-size: 14px; }
        .salud-stat-name { font-size: 8px; }
        .salud-stats-group { padding-top: 4px; gap: 4px; }
    }

    /* ── Card "Alertas Documentos" — misma superficie blanca limpia que Salud ── */
    .alertas-card {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 20px 22px;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -16px rgba(15, 23, 42, 0.18);
        cursor: pointer;
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease;
    }
    .alertas-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 2px 4px rgba(15, 23, 42, 0.05), 0 18px 36px -18px rgba(15, 23, 42, 0.25);
    }
    .alertas-card::before {
        content: '';
        position: absolute;
        right: -40px;
        top: -40px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: radial-gradient(circle at center, rgba(30, 41, 59, 0.10), transparent 70%);
        pointer-events: none;
    }
    .alertas-card-icon {
        position: relative;
        flex-shrink: 0;
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: #1e293b;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        box-shadow: 0 8px 20px -6px rgba(30, 41, 59, 0.55), inset 0 1px 0 rgba(255,255,255,0.25);
    }
    .alertas-card-icon .material-icons { font-size: 28px; }
    .alertas-card-body {
        display: flex;
        flex-direction: column;
        min-width: 0;
        flex: 1;
    }
    .alertas-card-label {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #1e293b;
        margin-bottom: 2px;
    }
    .alertas-card-main {
        display: flex;
        align-items: baseline;
        gap: 8px;
        flex-wrap: wrap;
    }
    .alertas-card-value {
        font-size: 36px;
        font-weight: 900;
        color: #0b1c30;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    .alertas-card-sub {
        font-size: 12px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .alertas-card-chev {
        position: relative;
        color: #d97706;
        transition: transform 0.2s ease;
    }
    .alertas-card:hover .alertas-card-chev { transform: translateX(3px); }

    @media (max-width: 900px) {
        .alertas-card { gap: 12px; padding: 12px 14px; border-radius: 14px; }
        .alertas-card-icon { width: 40px; height: 40px; border-radius: 11px; }
        .alertas-card-icon .material-icons { font-size: 22px; }
        .alertas-card-label { font-size: 10px; letter-spacing: 0.08em; }
        .alertas-card-value { font-size: 26px; }
        .alertas-card-sub { font-size: 11px; }
    }
    @media (max-width: 520px) {
        .alertas-card { gap: 10px; padding: 10px 12px; }
        .alertas-card-icon { width: 36px; height: 36px; border-radius: 10px; }
        .alertas-card-icon .material-icons { font-size: 20px; }
        .alertas-card-value { font-size: 22px; }
        .alertas-card-sub { font-size: 10.5px; }
    }

    /* ── Estilos del panel "Alertas Documentos" ── */
    /* (El panel flotante fue reemplazado por el modal `.alertas-modal-overlay`;
       los selectores .alertas-panel-header/title/toolbar/body siguen usandose
       dentro del modal por reutilizacion de markup.) */
    .alertas-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: #1e293b;
        color: #fff;
    }
    .alertas-panel-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        font-weight: 800;
        letter-spacing: 0.2px;
    }
    .alertas-panel-title .material-icons { font-size: 20px; color: #ffffff; }
    .alertas-panel-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 14px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    .alertas-search-wrap {
        position: relative;
        flex: 1;
        min-width: 0;
    }
    .alertas-search-wrap .material-icons {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 18px;
        color: #94a3b8;
        pointer-events: none;
    }
    .alertas-search-input {
        width: 100%;
        box-sizing: border-box;
        padding: 8px 12px 8px 34px;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        font-size: 13px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .alertas-search-input:focus {
        border-color: #0067b1;
        box-shadow: 0 0 0 3px rgba(0, 103, 177, 0.14);
    }
    .alertas-export-btn {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    .alertas-export-btn:hover:not(:disabled) {
        color: #0067b1;
        border-color: #0067b1;
        background: #eff6ff;
    }
    .alertas-export-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .alertas-export-btn .material-icons { font-size: 18px; }
    .alertas-panel-body {
        max-height: 420px;
        overflow-y: auto;
        overflow-x: hidden;
        background: #fff;
    }
    .alertas-panel-body::-webkit-scrollbar { width: 6px; }
    .alertas-panel-body::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }
    /* ── Catalogo en el Dashboard ── */
    .dashboard-catalogo-section {
        margin-top: 24px;
    }
    .dashboard-catalogo-title {
        font-size: 16px;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .cat-mini-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 12px;
    }
    .cat-mini-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .cat-mini-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px -6px rgba(15, 23, 42, 0.12);
    }
    .cat-mini-photo {
        width: 100%;
        aspect-ratio: 4 / 3;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }
    .cat-mini-photo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    .cat-mini-photo .placeholder {
        color: #cbd5e0;
        font-size: 32px;
    }
    .cat-mini-anio-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        background: #0067b1;
        color: white;
        font-size: 9px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 2px;
    }
    .cat-mini-body {
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .cat-mini-modelo {
        font-size: 12px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.25;
        text-transform: uppercase;
        word-break: break-word;
    }
    .cat-mini-specs {
        font-size: 10px;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div class="dashboard-container" style="padding: 10px 20px; position: relative; z-index: 1;">

    {{-- ── Hero moderno: 3 imágenes con overlay oscuro y título blanco ── --}}
    <section class="menu-hero">
        <div class="menu-hero-stripes">
            @php $heroImg = asset('images/maquinaria_login_new.webp'); @endphp
            <div><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: left center;"></div>
            <div><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: center center;"></div>
            <div class="menu-hero-stripe-clip"><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: right top;"></div>
        </div>
        <div class="menu-hero-overlay"></div>
        <div class="menu-hero-content">
            <h1 class="menu-hero-title">
                Sistema de Gestión<br>
                de <span class="accent">Equipos Operacionales</span>
            </h1>
        </div>
        <div class="menu-hero-stat">
            <div class="menu-hero-stat-label">Flota activa</div>
            <div class="menu-hero-stat-value">{{ $totalFlotaActiva ?? 0 }}</div>
            <div class="menu-hero-stat-caption">Equipos en operación</div>
        </div>
    </section>

    <!-- Main Grid -->
    <div class="dashboard-grid">

        <!-- Column 1: Resumen Rápido (Cards) -->
        <div class="card-section" style="grid-column: span 12;">
            <div class="cards-wrapper">

                {{-- MOVILIZACIONES SECTION: removido. La lista "Equipos Por Confirmar Recepción"
                     ahora vive en el centro de notificaciones del navbar (layouts/estructura_base). --}}

                {{-- ── Card "Salud Operacional" (al lado de Alertas, 1 col del cards-wrapper) ── --}}
                @php
                    $_flotaTotal = $totalFlotaActiva ?? 0;
                    $_ok  = $equiposOperativos ?? 0;
                    $_mt  = $equiposMantenimiento ?? 0;
                    $_bad = $equiposInoperativos ?? 0;
                    $_pctOk  = $_flotaTotal > 0 ? round(($_ok  / $_flotaTotal) * 100, 1) : 0;
                    $_pctMt  = $_flotaTotal > 0 ? round(($_mt  / $_flotaTotal) * 100, 1) : 0;
                    $_pctBad = $_flotaTotal > 0 ? round(($_bad / $_flotaTotal) * 100, 1) : 0;
                @endphp
                <div class="salud-card">
                    <div class="salud-main-block">
                        <div class="salud-icon">
                            <i class="material-icons">monitoring</i>
                        </div>
                        <div class="salud-body">
                            <span class="salud-label">Salud Operacional</span>
                            <div class="salud-main">
                                <span class="salud-percent">{{ $_pctOk }}%</span>
                                <span class="salud-percent-sub">Operativos</span>
                            </div>
                            <div class="salud-bar" title="Operativos {{ $_pctOk }}% · Mantenimiento {{ $_pctMt }}% · Inoperativos {{ $_pctBad }}%">
                                <span class="salud-bar-ok"    style="width: {{ $_pctOk }}%"></span>
                                <span class="salud-bar-maint" style="width: {{ $_pctMt }}%"></span>
                                <span class="salud-bar-bad"   style="width: {{ $_pctBad }}%"></span>
                            </div>
                        </div>
                    </div>
                    <div class="salud-stats-group">
                        <div class="salud-stat ok">
                            <div class="salud-stat-value">{{ $_ok }}</div>
                            <div class="salud-stat-name">Operativos</div>
                        </div>
                        <div class="salud-stat maint">
                            <div class="salud-stat-value">{{ $_mt }}</div>
                            <div class="salud-stat-name">Mantenim.</div>
                        </div>
                        <div class="salud-stat bad">
                            <div class="salud-stat-value">{{ $_bad }}</div>
                            <div class="salud-stat-name">Inoperat.</div>
                        </div>
                    </div>
                </div>

                <!-- ALERTAS SECTION (rediseñada) — dropup flotante: el panel se superpone arriba sin empujar el layout -->
                <div style="position: relative;">
                    <!-- Card trigger -->
                    <div class="alertas-card" onclick="toggleExpiredDocs()" role="button" tabindex="0"
                         onkeydown="if(event.key==='Enter'||event.key===' ') { event.preventDefault(); toggleExpiredDocs(); }">
                        <div class="alertas-card-icon">
                            <i class="material-icons {{ $totalAlerts > 0 ? 'bell-shake' : '' }}">notifications_active</i>
                        </div>
                        <div class="alertas-card-body">
                            <span class="alertas-card-label">Alertas Documentos</span>
                            <div class="alertas-card-main">
                                <span class="alertas-card-value">{{ $totalAlerts }}</span>
                                <span class="alertas-card-sub">Por Renovar</span>
                            </div>
                        </div>
                        <div class="alertas-card-chev">
                            <i class="material-icons">chevron_right</i>
                        </div>
                    </div>

                </div>

                {{-- Modal Alertas Documentos (abierto desde la card) --}}
                <div class="alertas-modal-overlay" id="expiredDocsContainer" onclick="if(event.target===this) toggleExpiredDocs()">
                    <div class="alertas-modal-content" role="dialog" aria-modal="true" aria-label="Alertas de Documentos">
                        <div class="alertas-panel-header">
                            <div class="alertas-panel-title">
                                <i class="material-icons">description</i>
                                <span>Alertas de Documentos</span>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button"
                                        onclick="downloadDashboardPdf(this, '{{ route('dashboard.exportDocumentsPDF') }}')"
                                        class="alertas-export-btn"
                                        title="Descargar Reporte PDF"
                                        style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.2); color: #fff;">
                                    <i class="material-icons">file_download</i>
                                </button>
                                <button type="button" onclick="toggleExpiredDocs()" title="Cerrar"
                                        style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); color: #fff; border-radius: 8px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                                    <i class="material-icons" style="font-size:18px;">close</i>
                                </button>
                            </div>
                        </div>
                        <div class="alertas-panel-toolbar">
                            <div class="alertas-search-wrap">
                                <i class="material-icons">search</i>
                                <input type="text" id="alertSearch" class="alertas-search-input"
                                       placeholder="Buscar por placa, chasis, modelo…"
                                       onkeyup="filterDashboardAlerts()"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="alertas-panel-body">
                            <div id="dashboardAlertsList">
                                @include('partials.dashboard_alerts')
                            </div>
                        </div>
                    </div>
                </div>


            </div>

            {{-- Slot PWA: boton "Instalar App". Fuera del .cards-wrapper (grid)
                 para que SIEMPRE quede debajo de las dos cards, nunca al lado.
                 Solo se inyecta contenido en mobile/no-standalone via pwa-install.js. --}}
            <div id="pwaInstallSlot" style="margin-top: 12px;"></div>

            {{-- ── Catálogo Destacado ── --}}
            @if(isset($catalogosDestacados) && $catalogosDestacados->count() > 0)
                <div class="dashboard-catalogo-section">
                    <div class="dashboard-catalogo-title">
                        <i class="material-icons" style="color: #0067b1;">directions_car</i>
                        Catálogo de Modelos
                    </div>
                    <div class="cat-mini-grid">
                        @foreach($catalogosDestacados as $catalogo)
                            @php
                                $driveFileId = $catalogo->FOTO_REFERENCIAL
                                    ? basename(str_replace('/storage/google/', '', explode('?', $catalogo->FOTO_REFERENCIAL)[0]))
                                    : null;
                            @endphp
                            <div class="cat-mini-card">
                                <div class="cat-mini-photo">
                                    @if($driveFileId)
                                        <img src="{{ url('/storage/google/' . $driveFileId . '?sz=w300') }}"
                                             alt="{{ $catalogo->MODELO }}"
                                             loading="lazy"
                                             decoding="async"
                                             style="opacity:0; transition:opacity 0.25s ease;"
                                             onload="this.style.opacity=1"
                                             onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
                                    @else
                                        <i class="material-icons placeholder">precision_manufacturing</i>
                                    @endif
                                    
                                    <span class="cat-mini-anio-badge">
                                        <i class="material-icons" style="font-size:10px;">event</i>
                                        {{ $catalogo->ANIO_ESPEC }}
                                    </span>
                                </div>
                                <div class="cat-mini-body">
                                    <span class="cat-mini-modelo">{{ $catalogo->MODELO }}</span>
                                    @if($catalogo->MOTOR || $catalogo->COMBUSTIBLE)
                                        <span class="cat-mini-specs">
                                            {{ collect([$catalogo->MOTOR, $catalogo->COMBUSTIBLE])->filter()->join(' · ') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

    {{-- El panel del usuario se movió al header (ver layouts/estructura_base.blade.php) --}}

    <!-- Partial Modal for Equipment Details (Used by Alerts) -->
    @include('admin.equipos.partials.equipment_details_modal')

    {{-- ============================================================ --}}
    {{-- MODAL DE RECEPCIÓN DIRECTA (Abierto desde el menú) --}}
    {{-- ============================================================ --}}
    @php
        $menuUser = auth()->user();
    @endphp

    {{-- El modal reutiliza exactamente la misma lógica JS de movilizaciones_index.js --}}
    <div id="recepcionDirectaModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; justify-content: center; align-items: center;">
        <div style="background: white; width: 95%; max-width: 450px; max-height: 90vh; border-radius: 16px; padding: 0; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); animation: slideDown 0.3s ease-out; display: flex; flex-direction: column; overflow: hidden;">

            {{-- Header --}}
            <div style="background: linear-gradient(135deg, #1e293b, #0f172a); padding: 14px 18px; color: white; flex-shrink: 0;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="material-icons" style="font-size: 22px;">input</i>
                        <div>
                            <h3 style="margin: 0; font-size: 15px; font-weight: 800;">Recepción Directa</h3>
                            <p style="margin: 0; font-size: 11px; opacity: 0.85;">Sin movilización previa</p>
                        </div>
                    </div>
                    <button type="button" onclick="cerrarRecepcionDirecta()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i class="material-icons" style="font-size: 18px;">close</i>
                    </button>
                </div>
            </div>

            {{-- Body --}}
            <div style="padding: 20px 25px; overflow-y: auto; flex: 1;">

                {{-- PASO 1: Buscar equipos --}}
                <div style="margin-bottom: 20px;">
                    <label for="rdSearchInput" style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">
                        <span style="background: #1e293b; color: white; padding: 2px 8px; border-radius: 50%; font-size: 11px; font-weight: 800; margin-right: 6px;">1</span>
                        Buscar Equipo (Serial, Placa, Motor o #Etiqueta)
                    </label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="rdSearchInput"
                            placeholder="Buscar seriales..."
                            style="flex: 1; padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 10px; font-size: 14px; background: #f8fafc; outline: none;"
                            autocomplete="off"
                            onfocus="this.style.borderColor='#1e293b'" onblur="this.style.borderColor='#cbd5e0'"
                            onkeyup="if(event.key === 'Enter') { if(window.rdSearchTimeout) clearTimeout(window.rdSearchTimeout); buscarEquiposRD(true); } else { if(window.rdSearchTimeout) clearTimeout(window.rdSearchTimeout); window.rdSearchTimeout = setTimeout(() => buscarEquiposRD(), 500); }">
                    </div>
                </div>

                {{-- Resultados de búsqueda --}}
                <div id="rdResultados" style="margin-bottom: 20px; display: none;">
                    <p style="font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 6px; margin-top: 0; text-transform: uppercase;">Resultados</p>
                    <div id="rdResultadosList" style="min-height: 100px; max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fafbfc;">
                        {{-- populated by JS --}}
                    </div>
                </div>

                {{-- Frente receptor --}}
                @php
                    $frentesIdsArray = $menuUser ? $menuUser->getFrentesIds() : [];
                    $assignedFrentes = $frentes->whereIn('ID_FRENTE', $frentesIdsArray);
                @endphp

                @if($assignedFrentes->count() > 1)
                    {{-- Si tiene varios frentes asignados, debe elegir uno para la recepción --}}
                    <div style="margin-bottom: 15px;">
                        <label for="rdFrenteInput" style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">
                            <span style="background: #1e293b; color: white; padding: 2px 8px; border-radius: 50%; font-size: 11px; font-weight: 800; margin-right: 6px;">2</span>
                            FRENTE DE RECEPCIÓN
                        </label>
                        <select id="rdFrenteInput" style="appearance: none; -webkit-appearance: none; width: 100%; padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 10px; font-size: 14px; background: #f8fafc url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%204%205%22%3E%3Cpath%20fill%3D%22%23a0aec0%22%20d%3D%22M2%200L0%202h4zm0%205L0%203h4z%22%2F%3E%3C%2Fsvg%3E') no-repeat right 14px top 50%; background-size: 8px 10px; outline: none; cursor: pointer;">
                            @foreach($assignedFrentes as $fA)
                                <option value="{{ $fA->ID_FRENTE }}">{{ $fA->NOMBRE_FRENTE }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    {{-- Si tiene solo 1 (o es GLOBAL y no tiene asignado, usamos null/hidden) --}}
                    @php
                        $singleFrenteObj = $assignedFrentes->first();
                    @endphp
                    <input type="hidden" id="rdFrenteInput" value="{{ $singleFrenteObj ? $singleFrenteObj->ID_FRENTE : '' }}">
                @endif

                {{-- PASO 3: Ubicación específica (opcional) --}}
                <div style="margin-bottom: 15px;">
                    <label for="rdUbicacionInput" style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">
                        <span style="background: #1e293b; color: white; padding: 2px 8px; border-radius: 50%; font-size: 11px; font-weight: 800; margin-right: 6px;">{{ $assignedFrentes->count() > 1 ? '3' : '2' }}</span>
                        UBICACIÓN DETALLADA (Opcional)
                    </label>
                    <div style="position: relative;">
                        <input type="text" id="rdUbicacionInput"
                            placeholder="Ej. Patio de maniobras..."
                            style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 10px; font-size: 14px; background: #f8fafc; outline: none; box-sizing: border-box;"
                            onfocus="this.style.borderColor='#1e293b'; showUbicacionSuggestions('rd-ubicacion-suggestions')"
                            onblur="this.style.borderColor='#cbd5e0'; setTimeout(()=>hideUbicacionSuggestions('rd-ubicacion-suggestions'), 200)"
                            oninput="filterUbicacionSuggestions(this, 'rd-ubicacion-suggestions')">
                        <div id="rd-ubicacion-suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #cbd5e0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:500; max-height:160px; overflow-y:auto; margin-top:4px;"></div>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div style="padding: 15px 25px; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; flex-shrink: 0; background: #fafbfc;">
                <button type="button" onclick="cerrarRecepcionDirecta()"
                    style="flex: 1; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 10px; font-weight: 600; color: #64748b;">
                    Cancelar
                </button>
                <button type="button" id="btnConfirmarRD" onclick="confirmarRecepcionDirecta()"
                    style="flex: 1; padding: 12px; background: #1e293b; border: none; border-radius: 10px; font-weight: 700; color: white; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(30,41,59,0.3);"
                    onmouseover="this.style.background='#0f172a'; this.style.transform='translateY(-1px)'"
                    onmouseout="this.style.background='#1e293b'; this.style.transform='translateY(0)'">
                    <i class="material-icons" style="font-size: 16px;">check_circle</i>
                    Confirmar
                </button>
            </div>
        </div>
    </div>

@endsection

@section('extra_js')
    <script>
        // Animación del modal de recepción directa
        const _styleRD = document.createElement('style');
        _styleRD.textContent = '@keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }';
        document.head.appendChild(_styleRD);

        // PDF download for Alertas Documentos — usa el spinner global de la app
        window.downloadDashboardPdf = async function(btn, url) {
            if (btn && btn.disabled) return;
            if (btn) btn.disabled = true;

            if (window.showPreloader) window.showPreloader();

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: { 'Accept': 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) throw new Error('Error generando PDF');

                const blob = await response.blob();
                const objUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = objUrl;
                let filename = 'Reporte_Vencimientos_Equipos.pdf';
                const disposition = response.headers.get('Content-Disposition');
                if (disposition && disposition.indexOf('filename=') !== -1) {
                    const m = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
                    if (m && m[1]) filename = m[1].replace(/['"]/g, '');
                }
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { document.body.removeChild(a); window.URL.revokeObjectURL(objUrl); }, 100);

                if (window.showToast) window.showToast('Reporte descargado correctamente.', 'success');

            } catch (err) {
                console.error(err);
                if (window.showToast) window.showToast('Error generando el PDF. Intente de nuevo.', 'error');
            } finally {
                if (window.hidePreloader) window.hidePreloader();
                if (btn) btn.disabled = false;
            }
        };
    </script>
@endsection
