<!doctype html>
<html data-bs-theme="{{ Cookie::get('theme') }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1" name="viewport">

    <!-- CSRF Token -->
    <meta content="{{ csrf_token() }}" name="csrf-token">

    <title>{{ config('app.name', 'HeimGuard') }}</title>

    {{-- Installable as an app (steelants/laravel-general): manifest, icons, service worker. --}}
    @pwa('HeimGuard', 'HeimGuard', '#f9fbfc')
    <link href="{{ asset('/favicon.ico') }}" rel="shortcut icon" type="image/x-icon">
    <link href="{{ asset('/favicon.svg') }}" rel="icon" type="image/svg+xml">

    <!-- Fonts -->

    <!-- Scripts -->
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>

<body>
    <div id="app">
        <div class="row g-0 h-100">
            <div class="align-content-center col-md-6 d-none d-md-flex flex-column justify-content-center bg-body-tertiary">
                <div class="container py-4 text-center">
                    <a class="d-inline-block mb-3" href="{{ url('/') }}">
                        <img alt="" height="96" src="{{ asset('/favicon.svg') }}" width="96">
                    </a>
                    <h1>{{ config('app.name', 'HeimGuard') }}</h1>
                    <p class="text-black-50">
                        {{ __('Simple management of your Windows and Linux devices') }}
                    </p>
                </div>
            </div>
            <div class="col-md-6 d-flex flex-column grid justify-content-center align-content-center">
                <div class="container py-4 px-4">
                    <div class="d-md-none text-center mb-4">
                            <img src="{{ asset('storage/images/logo.png') }}" class="mb-4" width="64px" height="64px">
                    </div>

                    <x-alerts/>

                    <div class="row justify-content-center">
                        <div class="col-md-10 col-xl-6">
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
