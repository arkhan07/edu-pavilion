<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

@php
    $rtlLanguages = !empty($generalSettings['rtl_languages']) ? $generalSettings['rtl_languages'] : [];

    $isRtl = ((in_array(mb_strtoupper(app()->getLocale()), $rtlLanguages)) or (!empty($generalSettings['rtl_layout']) and $generalSettings['rtl_layout'] == 1));
@endphp
<head>
    @include(getTemplate().'.includes.metas')
    <title>{{ $pageTitle ?? '' }}{{ !empty($generalSettings['site_name']) ? (' | '.$generalSettings['site_name']) : '' }}</title>

    <link href="/assets/default/css/font.css" rel="stylesheet">

    <link rel="stylesheet" href="/assets/default/vendors/sweetalert2/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/default/vendors/toast/jquery.toast.min.css">
    <link rel="stylesheet" href="/assets/default/vendors/simplebar/simplebar.css">
    <link rel="stylesheet" href="/assets/default/css/app.css">
    <link rel="stylesheet" href="/assets/default/css/panel.css">
    <link rel="stylesheet" href="/assets/default/css/responsive.css">

    @if($isRtl)
        <link rel="stylesheet" href="/assets/default/css/rtl-app.css">
    @endif

    @stack('styles_top')
    @stack('scripts_top')

    <style>
        {!! !empty(getCustomCssAndJs('css')) ? getCustomCssAndJs('css') : '' !!}

        {!! getThemeFontsSettings() !!}

        {!! getThemeColorsSettings() !!}
    </style>

    @if(!empty($generalSettings['preloading']) and $generalSettings['preloading'] == '1')
        @include('admin.includes.preloading')
    @endif

</head>
<body class="@if($isRtl) rtl @endif">
<script>if(localStorage.getItem('darkMode')==='true')document.body.classList.add('dark-mode');</script>

<style>
/* ══════════════════════════════════════
   PANEL DARK MODE STYLES
   ══════════════════════════════════════ */
body.dark-mode,
body.dark-mode #panel_app {
    background-color: #121212 !important;
    color: #e2e8f0 !important;
}

/* ── Sidebar ── */
body.dark-mode .panel-sidebar {
    background-color: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .panel-sidebar .sidebar-user-stats {
    border-color: #334155 !important;
}
body.dark-mode .panel-sidebar .border-left {
    border-color: #334155 !important;
}
body.dark-mode .panel-sidebar .create-new-user {
    background-color: #334155 !important;
    color: #e2e8f0 !important;
}

/* Teks menu sidebar — putih di dark mode (desktop, tablet, mobile) */
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) a span {
    color: #e2e8f0 !important;
}
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) a {
    color: #e2e8f0 !important;
}
body.dark-mode .panel-sidebar .sidenav-item .sidenav-item-collapse a {
    color: #cbd5e1 !important;
}
body.dark-mode .panel-sidebar .sidenav-item-collapse {
    border-color: #334155 !important;
}
body.dark-mode .panel-sidebar .sidebar-user-stat-item strong {
    color: #e2e8f0 !important;
}
body.dark-mode .panel-sidebar .sidebar-user-stat-item span,
body.dark-mode .panel-sidebar .user-name h3 {
    color: #e2e8f0 !important;
}

/* Ikon sidebar SVG — lebih terang di dark mode */
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon svg,
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon svg path,
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon svg circle,
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon.assign-strock svg,
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon.assign-strock svg * {
    stroke: #94a3b8 !important;
}
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon.assign-fill svg,
body.dark-mode .panel-sidebar .sidenav-item:not(.sidenav-item-active) .sidenav-item-icon.assign-fill svg * {
    fill: #94a3b8 !important;
}

/* ── Gap foto profil dan nama (mobile: flex-row, desktop: flex-column) ── */
.panel-sidebar .sidebar-user-info-wrap {
    gap: 14px;
}
@media (min-width: 992px) {
    .panel-sidebar .sidebar-user-info-wrap {
        gap: 0; /* desktop pakai mt-lg-15 pada nama */
    }
}
.panel-sidebar .sidebar-user-name-wrap {
    min-width: 0; /* cegah overflow nama panjang */
}

/* ── Toggle tema — pastikan terlihat di semua perangkat ── */
.sidebar-theme-toggle-row {
    display: flex !important;
    align-items: center;
    gap: 10px;
    padding: 8px 0 4px;
}
body.dark-mode .sidebar-theme-toggle-row span {
    color: #e2e8f0 !important;
}

/* ── Mobile top bar ── */
body.dark-mode .xs-panel-nav {
    background-color: #1e293b !important;
    border-bottom-color: #334155 !important;
}
body.dark-mode .xs-panel-nav .user-name h3,
body.dark-mode .xs-nav-username {
    color: #e2e8f0 !important;
}
/* Sidebar toggler (≡ Menu) — putih di dark mode */
body.dark-mode .xs-panel-nav .sidebar-toggler {
    color: #e2e8f0 !important;
    border-color: rgba(226,232,240,0.2) !important;
}
body.dark-mode .xs-panel-nav .sidebar-toggler span {
    color: #e2e8f0 !important;
}
/* Icon SVG di dalam sidebar-toggler (Feather menu icon) */
body.dark-mode .xs-panel-nav .sidebar-toggler svg,
body.dark-mode .xs-panel-nav .sidebar-toggler .feather {
    stroke: #e2e8f0 !important;
    color: #e2e8f0 !important;
}

/* ── Navbar (top) ── */
body.dark-mode #navbar,
body.dark-mode .navbar {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: none !important;
}
body.dark-mode .navbar-brand img {
    filter: brightness(0) invert(1);
}
/* Hamburger toggler — putih di dark mode */
body.dark-mode .navbar-toggler-icon {
    filter: brightness(0) invert(1) !important;
}
body.dark-mode .navbar-toggler {
    border-color: rgba(226,232,240,0.3) !important;
}
body.dark-mode .navbar-toggle-content {
    background-color: #1e293b !important;
}
/* Desktop navbar links (Home, Courses, dll) — putih di dark mode */
/* Hanya .nav-link, BUKAN li > a (agar <a href="/categories"> tidak terpengaruh) */
body.dark-mode #navbar .navbar-nav .nav-link {
    color: #ffffff !important;
}
body.dark-mode #navbar .navbar-nav .nav-link:hover,
body.dark-mode #navbar .navbar-nav .nav-item.active .nav-link {
    color: #ffffff !important;
    opacity: 0.9;
}

/* Desktop (≥992px): tombol Kategori — background terang, teks HITAM */
@media (min-width: 992px) {
    body.dark-mode #navbar .navbar-toggle-content .menu-category > ul > li.xs-categories-toggle {
        background-color: #f1f5f9 !important;
        color: #000000 !important;
    }
    body.dark-mode #navbar .navbar-toggle-content .menu-category > ul > li.xs-categories-toggle:hover {
        background-color: #e2e8f0 !important;
        color: #000000 !important;
    }
    body.dark-mode #navbar .navbar-toggle-content .xs-categories-toggle svg,
    body.dark-mode #navbar .navbar-toggle-content .xs-categories-toggle .feather {
        stroke: #000000 !important;
        color: #000000 !important;
    }
}
/* Mobile/Tablet (<992px): Kategori tanpa background — semua mode */
@media (max-width: 991px) {
    #navbar .navbar-toggle-content .menu-category,
    #navbar .navbar-toggle-content .menu-category > ul,
    #navbar .navbar-toggle-content .menu-category > ul > li,
    #navbar .navbar-toggle-content .menu-category > ul > li.xs-categories-toggle,
    #navbar .navbar-nav > li > a[href="/categories"],
    #navbar .navbar-nav > li > a[href="/categories"] > .menu-category,
    #navbar .navbar-nav > li.mr-lg-25 {
        background-color: transparent !important;
        background: none !important;
        -webkit-tap-highlight-color: transparent !important;
    }
    #navbar .navbar-toggle-content .menu-category > ul > li.xs-categories-toggle {
        padding: 0 !important;
        border-radius: 0 !important;
    }
    body.dark-mode #navbar .navbar-toggle-content .menu-category > ul > li.xs-categories-toggle {
        color: #ffffff !important;
    }
    body.dark-mode #navbar .navbar-toggle-content .xs-categories-toggle svg,
    body.dark-mode #navbar .navbar-toggle-content .xs-categories-toggle .feather {
        stroke: #ffffff !important;
        color: #ffffff !important;
    }
}

/* Mobile overlay — teks navbar putih */
body.dark-mode #navbar .navbar-toggle-content .navbar-nav .nav-link,
body.dark-mode #navbar .navbar-toggle-content .navbar-nav li,
body.dark-mode #navbar .navbar-toggle-content .navbar-nav a {
    color: #ffffff !important;
}
@media (max-width: 991px) {
    body.dark-mode #navbar .navbar-toggle-content svg {
        stroke: #ffffff !important;
    }
}

/* ── Ikon navbar (cart + bell) — putih di semua perangkat saat dark mode ── */
body.dark-mode #navbar .feather,
body.dark-mode .navbar .feather,
body.dark-mode .nav-notify-cart-dropdown .feather,
body.dark-mode .nav-icons-or-start-live .feather {
    color: #ffffff !important;
    stroke: #ffffff !important;
}
body.dark-mode #navbar .btn-transparent,
body.dark-mode .navbar .btn-transparent,
body.dark-mode .nav-notify-cart-dropdown .btn-transparent {
    color: #ffffff !important;
}

/* ── Text ── */
body.dark-mode .text-dark-blue,
body.dark-mode .text-secondary,
body.dark-mode .text-dark,
body.dark-mode p,
body.dark-mode span:not(.badge):not([class*="btn"]),
body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
body.dark-mode label,
body.dark-mode .input-label,
body.dark-mode td,
body.dark-mode th {
    color: #e2e8f0 !important;
}
body.dark-mode .text-gray,
body.dark-mode .text-muted {
    color: #94a3b8 !important;
}

/* ── User dropdown — background gelap mengikuti dark mode ── */
body.dark-mode .navbar-auth-user-dropdown .custom-dropdown-body {
    background-color: #1e293b !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4) !important;
    border: 1px solid #334155 !important;
}
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item a,
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item a span,
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item a.text-gray {
    color: #f1f5f9 !important;
}
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item:hover {
    background-color: #273549 !important;
}
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item:hover a,
body.dark-mode .navbar-auth-user-dropdown .navbar-auth-user-dropdown-item:hover a span {
    color: #ffffff !important;
}
body.dark-mode .navbar-auth-user-dropdown .dropdown-user-avatar {
    background-color: #273549 !important;
    border-color: #334155 !important;
}
body.dark-mode .navbar-auth-user-dropdown .dropdown-user-avatar .font-weight-bold,
body.dark-mode .navbar-auth-user-dropdown .dropdown-user-avatar .text-secondary {
    color: #f1f5f9 !important;
}
body.dark-mode .navbar-auth-user-dropdown .dropdown-user-avatar .text-gray {
    color: #94a3b8 !important;
}
/* Icon img.icons di dalam dropdown — putih di dark mode */
body.dark-mode .navbar-auth-user-dropdown .custom-dropdown-body img.icons {
    filter: brightness(0) invert(1) !important;
}

/* ── Cards / Panels ── */
body.dark-mode .card,
body.dark-mode .panel,
body.dark-mode .bg-white,
body.dark-mode .activities-container,
body.dark-mode .modal-content,
body.dark-mode .dropdown-menu,
body.dark-mode .accordion-row {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: none !important;
}

/* ── Tables ── */
body.dark-mode .table {
    color: #e2e8f0 !important;
}
body.dark-mode .table th,
body.dark-mode .table td {
    border-color: #334155 !important;
}
body.dark-mode .table thead th {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(255,255,255,0.03) !important;
}
body.dark-mode .table-hover tbody tr:hover {
    background-color: rgba(255,255,255,0.05) !important;
    color: #e2e8f0 !important;
}
body.dark-mode .table-bordered,
body.dark-mode .table-bordered th,
body.dark-mode .table-bordered td {
    border-color: #334155 !important;
}

/* ── Forms ── */
body.dark-mode .form-control,
body.dark-mode .custom-select,
body.dark-mode textarea.form-control {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .form-control:focus,
body.dark-mode .custom-select:focus {
    background-color: #1e293b !important;
    border-color: var(--primary) !important;
    color: #e2e8f0 !important;
}
body.dark-mode .form-control::placeholder {
    color: #64748b !important;
}
body.dark-mode .input-group-text {
    background-color: #334155 !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .custom-file-label {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .custom-file-label::after {
    background-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .custom-control-label::before {
    background-color: #0f172a !important;
    border-color: #334155 !important;
}

/* ── Select2 ── */
body.dark-mode .select2-container--default .select2-selection--single,
body.dark-mode .select2-container--default .select2-selection--multiple {
    background-color: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-mode .select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #e2e8f0 !important;
}
body.dark-mode .select2-dropdown,
body.dark-mode .select2-search--dropdown .select2-search__field {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .select2-container--default .select2-results__option {
    color: #e2e8f0 !important;
    background-color: #1e293b !important;
}
body.dark-mode .select2-container--default .select2-results__option[aria-selected=true] {
    background-color: #334155 !important;
}
body.dark-mode .select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: var(--primary) !important;
    color: #fff !important;
}

/* ── Borders / Dividers ── */
body.dark-mode .border,
body.dark-mode .border-bottom,
body.dark-mode .border-top,
body.dark-mode .border-left,
body.dark-mode .border-right,
body.dark-mode hr {
    border-color: #334155 !important;
}

/* ── Nav tabs ── */
body.dark-mode .nav-tabs {
    border-color: #334155 !important;
}
body.dark-mode .nav-tabs .nav-link {
    color: #94a3b8 !important;
    border-color: transparent !important;
}
body.dark-mode .nav-tabs .nav-link.active {
    background-color: #1e293b !important;
    border-color: #334155 #334155 #1e293b !important;
    color: var(--primary) !important;
}

/* ── Modal ── */
body.dark-mode .modal-header,
body.dark-mode .modal-footer {
    border-color: #334155 !important;
}
body.dark-mode .close {
    color: #e2e8f0 !important;
    opacity: 0.8;
}

/* ── Dropdown ── */
body.dark-mode .dropdown-item {
    color: #e2e8f0 !important;
}
body.dark-mode .dropdown-item:hover,
body.dark-mode .dropdown-item:focus {
    background-color: #334155 !important;
    color: #fff !important;
}
body.dark-mode .dropdown-divider {
    border-color: #334155 !important;
}

/* ── Buttons ── */
body.dark-mode .btn-light,
body.dark-mode .btn-white {
    background-color: #334155 !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .btn-outline-secondary {
    border-color: #475569 !important;
    color: #94a3b8 !important;
}

/* ── Pagination ── */
body.dark-mode .page-item .page-link {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .page-item.active .page-link {
    background-color: var(--primary) !important;
    border-color: var(--primary) !important;
    color: #fff !important;
}
body.dark-mode .page-item.disabled .page-link {
    background-color: #0f172a !important;
    color: #64748b !important;
}

/* ── Alerts ── */
body.dark-mode .alert-light,
body.dark-mode .alert-secondary {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}

/* ── SweetAlert2 in panel ── */
body.dark-mode .swal2-popup {
    background-color: #1e293b !important;
}
body.dark-mode .swal2-popup .swal2-title,
body.dark-mode .swal2-popup .swal2-html-container,
body.dark-mode .swal2-popup .swal2-content {
    color: #e2e8f0 !important;
}

/* ── Badge gray ── */
body.dark-mode .badge-light,
body.dark-mode .badge-secondary {
    background-color: #334155 !important;
    color: #e2e8f0 !important;
}

/* ── bg-gray variants ── */
body.dark-mode .bg-gray200,
body.dark-mode .bg-gray100 {
    background-color: #0f172a !important;
}

/* ── Panel theme toggle button (sidebar desktop) ── */
.panel-theme-toggle {
    background: transparent;
    border: 1px solid rgba(100,116,139,0.25);
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #475569;
    transition: background 0.2s ease, color 0.2s ease;
    flex-shrink: 0;
    line-height: 1;
    padding: 0;
}
.panel-theme-toggle:hover {
    background: rgba(0,0,0,0.06);
}
body.dark-mode .panel-theme-toggle {
    border-color: rgba(255,255,255,0.20);
    color: #e2e8f0;
    background: transparent;
}
body.dark-mode .panel-theme-toggle:hover {
    background: rgba(255,255,255,0.10);
}

/* xs-panel-nav: toggle hanya tampil sebagai icon, tanpa lingkaran menonjol */
.xs-panel-nav .panel-theme-toggle {
    background: transparent !important;
    border: none !important;
    border-radius: 6px !important;
    width: 28px !important;
    height: 28px !important;
    color: #475569;
}
.xs-panel-nav .panel-theme-toggle:hover {
    background: rgba(0,0,0,0.07) !important;
}
body.dark-mode .xs-panel-nav .panel-theme-toggle {
    background: transparent !important;
    border: none !important;
    color: #cbd5e1 !important;
}
body.dark-mode .xs-panel-nav .panel-theme-toggle:hover {
    background: rgba(255,255,255,0.10) !important;
}

/* ── CSS-driven icon visibility ── */
.panel-theme-toggle .icon-sun { display: none !important; }
.panel-theme-toggle .icon-moon { display: inline !important; }
body.dark-mode .panel-theme-toggle .icon-sun { display: inline !important; }
body.dark-mode .panel-theme-toggle .icon-moon { display: none !important; }

.theme-toggle .sun-icon { display: none !important; }
.theme-toggle .moon-icon { display: inline !important; }
body.dark-mode .theme-toggle .sun-icon { display: inline !important; }
body.dark-mode .theme-toggle .moon-icon { display: none !important; }

/* ── xs-panel-nav layout ── */
.min-width-0 { min-width: 0; }
.xs-panel-nav {
    min-height: 52px;
}

.xs-nav-user {
    flex: 1 1 0%;
    min-width: 0;
    gap: 10px;
}

.xs-nav-username {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin: 0;
}

.xs-nav-actions {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: 16px;
    padding-left: 16px;
    border-left: 1px solid rgba(100,116,139,0.2);
}
body.dark-mode .xs-nav-actions {
    border-left-color: rgba(226,232,240,0.12);
}

@media (max-width: 359px) {
    .xs-nav-actions {
        margin-left: 10px;
        padding-left: 10px;
    }
    .xs-nav-username { font-size: 13px !important; }
}

@media (min-width: 768px) and (max-width: 991px) {
    .xs-nav-actions {
        margin-left: 24px;
        padding-left: 20px;
        gap: 10px;
    }
    .panel-theme-toggle { width: 36px; height: 36px; }
}

/* ── Sidebar overlay layout (mobile) ── */
@media (max-width: 991px) {
    .panel-sidebar .sidebar-theme-toggle-row {
        margin-left: 4px !important;
        padding: 6px 0;
    }
    .panel-sidebar .sidebar-user-stats {
        margin-left: 4px !important;
    }
    .panel-sidebar.nav-show {
        overflow-y: auto;
    }
}

/* ── Panel content layout ── */
@media (max-width: 991px) {
    .panel-content {
        padding-top: 100px !important;
    }
}
@media (max-width: 575px) {
    .panel-content {
        padding-top: 110px !important;
        padding-left: 10px !important;
        padding-right: 10px !important;
    }
}

/* ── Wizard / settings footer buttons ── */
@media (max-width: 767px) {
    .create-webinar-footer {
        flex-wrap: wrap !important;
        gap: 10px;
        padding-bottom: 10px;
    }
    .create-webinar-footer > div {
        flex: 1 1 auto;
        display: flex;
        gap: 8px;
    }
    .create-webinar-footer .btn {
        flex: 1 1 0%;
        min-width: 0;
        margin-left: 0 !important;
        font-size: 12px !important;
        padding: 7px 6px !important;
        white-space: normal;
        text-align: center;
        line-height: 1.3;
    }
}
@media (max-width: 380px) {
    .create-webinar-footer {
        flex-direction: column !important;
        gap: 8px;
    }
    .create-webinar-footer > div {
        width: 100%;
    }
    .create-webinar-footer .btn {
        width: 100% !important;
        font-size: 13px !important;
        padding: 9px 12px !important;
    }
}
</style>

@include('web.default.includes.loading')

@php
    $isPanel = true;
@endphp

<div id="panel_app">

    @include('web.default.includes.navbar')

    <div class="d-flex justify-content-end">
        @include('web.default.panel.includes.sidebar')

        <div class="panel-content">
            @yield('content')
        </div>
    </div>

    @if($authUser->checkAccessToAIContentFeature())
        @include('web.default.panel.includes.aiContent.generator')
    @endif

    {{-- Modal Preview Gambar --}}
    <div class="modal fade" id="fileViewPanelModal" tabindex="-1" aria-labelledby="fileViewPanelModal" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <img src="" class="w-100" height="350px" alt="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('public.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="imageModal" tabindex="-1" role="dialog" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
            <h5 class="modal-title" id="imageModalLabel">Image Preview</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            </div>
            <div class="modal-body">
            <img src="" class="img-cover modal-image" alt="Gambar Modal">
            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('public.close') }}</button>
            </div>
        </div>
        </div>
    </div>

</div>

<script src="/assets/default/js/app.js"></script>
<script src="/assets/default/vendors/moment.min.js"></script>
<script src="/assets/default/vendors/feather-icons/dist/feather.min.js"></script>
<script src="/vendor/laravel-filemanager/js/stand-alone-button.js"></script>
<script src="/assets/default/vendors/sweetalert2/dist/sweetalert2.min.js"></script>
<script src="/assets/default/vendors/toast/jquery.toast.min.js"></script>
<script type="text/javascript" src="/assets/default/vendors/simplebar/simplebar.min.js"></script>

<script>
    var deleteAlertTitle = '{{ trans('public.are_you_sure') }}';
    var deleteAlertHint = '{{ trans('public.deleteAlertHint') }}';
    var deleteAlertConfirm = '{{ trans('public.deleteAlertConfirm') }}';
    var deleteAlertCancel = '{{ trans('public.cancel') }}';
    var deleteAlertSuccess = '{{ trans('public.success') }}';
    var deleteAlertFail = '{{ trans('public.fail') }}';
    var deleteAlertFailHint = '{{ trans('public.deleteAlertFailHint') }}';
    var deleteAlertSuccessHint = '{{ trans('public.deleteAlertSuccessHint') }}';
    var forbiddenRequestToastTitleLang = '{{ trans('public.forbidden_request_toast_lang') }}';
    var forbiddenRequestToastMsgLang = '{{ trans('public.forbidden_request_toast_msg_lang') }}';
    var deleteRequestLang = '{{ trans('update.delete_request') }}';
    var deleteRequestDescriptionLang = '{{ trans('update.delete_request_description') }}';
    var requestDetailsLang = '{{ trans('update.request_details') }}';
    var sendRequestLang = '{{ trans('update.send_request') }}';
    var closeLang = '{{ trans('public.close') }}';
    var generatedContentLang = '{{ trans('update.generated_content') }}';
    var copyLang = '{{ trans('public.copy') }}';
    var doneLang = '{{ trans('public.done') }}';
    var select = '{{ trans('public.select') }}';
    var chooseInstructorAlertHint = '{{ trans('public.chooseInstructorAlertHint') }}';
    var chooseInstructorAlertFailHint = '{{ trans('public.chooseInstructorAlertFailHint') }}';
    var chooseInstructorAlertSuccessHint = '{{ trans('public.chooseInstructorAlertSuccessHint') }}';
    var approveAlertConfirm = '{{ trans('public.approveAlertConfirm') }}';
    var rejectAlertConfirm = '{{ trans('public.rejectAlertConfirm') }}';
</script>

@if(session()->has('toast'))
    <script>
        (function () {
            "use strict";
            $.toast({
                heading: '{{ session()->get('toast')['title'] ?? '' }}',
                text: '{{ session()->get('toast')['msg'] ?? '' }}',
                bgColor: '@if(session()->get('toast')['status'] == 'success') #43d477 @else #f63c3c @endif',
                textColor: 'white',
                hideAfter: 10000,
                position: 'bottom-right',
                icon: '{{ session()->get('toast')['status'] }}'
            });
        })(jQuery)
    </script>
@endif

@include('web.default.includes.purchase_notifications')

@stack('styles_bottom')
@stack('scripts_bottom')

<script src="/assets/default/js//parts/main.min.js"></script>
<script src="/assets/default/js/panel/public.min.js"></script>
<script src="/assets/default/js/parts/content_delete.min.js"></script>
<script src="/assets/default/js/panel/ai-content-generator.min.js"></script>

@stack('scripts_bottom2')

<script>
/* Panel Dark Mode Toggle Logic */
(function() {
    "use strict";
    var DARK_KEY = 'darkMode';

    function applyTheme(isDark) {
        if (isDark) {
            document.body.classList.add('dark-mode');
        } else {
            document.body.classList.remove('dark-mode');
        }
        localStorage.setItem(DARK_KEY, isDark);
    }

    var isDark = localStorage.getItem(DARK_KEY) === 'true';
    applyTheme(isDark);

    document.querySelectorAll('.panel-theme-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var nowDark = !document.body.classList.contains('dark-mode');
            applyTheme(nowDark);
        });
    });
})();
</script>

<script>
    $('body').on('submit', 'form', function (e) {
        // form pilih metode Espay diproses via AJAX di popup (tanpa pindah halaman)
        if ($(this).is('.js-espay-pay-form')) {
            return;
        }
        if (window.location.pathname.startsWith('/panel/financial/')) {
            var $overlay = $('#global-loading-overlay');
            if ($overlay.length) {
                $overlay.removeClass('hidden').show();
            }
            $(this).find('button[type="submit"], .btn-primary').addClass('loadingbar primary').prop('disabled', true);
        }
    });

    @if(session()->has('registration_package_limited'))
    (function () {
        "use strict";
        handleLimitedAccountModal('{!! session()->get('registration_package_limited') !!}')
    })(jQuery)
    {{ session()->forget('registration_package_limited') }}
    @endif

    {!! !empty(getCustomCssAndJs('js')) ? getCustomCssAndJs('js') : '' !!}

    $('body').on('click', '.panel-file-view', function (e) {
        e.preventDefault();
        var input = $(this).attr('data-input');
        var img_src = $('#' + input).val();
        $('#fileViewPanelModal').find('img').attr('src', img_src);
        $('#fileViewPanelModal').modal('show');
    });

    $('body').on('click', '.img-modal', function (e) {
        var imageUrl = $(this).attr("src");
        var title = $(this).data("title");
        $(".modal-title").text(title);
        $(".modal-image").attr("src", imageUrl);
        $("#imageModal").modal("show");
     });
</script>
</body>
</html>