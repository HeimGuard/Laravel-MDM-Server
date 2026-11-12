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
    @livewireStyles
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])

    {{-- <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('{{ asset('/service-worker.js') }}');
            });
        }
    </script> --}}

</head>

<body>
    <div id="app">
		{{ $slot }}
    </div>

    <x-alerts />

    @livewireScripts
    @livewire('modal-basic', key('modal'))
    @stack('scripts')
</body>

</html>
