<!doctype html>
<html data-bs-theme="{{ Cookie::get('theme') }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1" name="viewport">

    <!-- CSRF Token -->
    <meta content="{{ csrf_token() }}" name="csrf-token">
    {{-- Live updates (resources/js/live.js): the public key of the Reverb app, the secret stays on the server. --}}
    <meta content="{{ config('broadcasting.connections.reverb.key') }}" name="mdm-reverb-key">
    @php($reverb = config('broadcasting.connections.reverb.options'))
    @if (filled($reverb['host'] ?? null) && ! in_array(strtolower($reverb['host']), ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true))
        {{-- Reverb on its own public address (REVERB_HOST), as the agents are told too; otherwise the portal's address. --}}
        <meta content="{{ $reverb['host'] }}" name="mdm-reverb-host">
        <meta content="{{ $reverb['port'] ?? '' }}" name="mdm-reverb-port">
        <meta content="{{ $reverb['scheme'] ?? 'https' }}" name="mdm-reverb-scheme">
    @endif

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
        @auth
            @include('partials.navbar')
        @endauth

        <div class="layout">
            <x-navigation />

            @if (isset($subNavigation) && $subNavigation->isNotEmpty())
                <div class="layout-subnav" id="layout-subnav">
                    <div class="sidebar">
                        <div class="sidebar-content">{{ $subNavigation }}</div>
                    </div>
                </div>
            @endif

            @include('partials.navigation-mobile')

            @if($withoutWrapper ?? false)
                {{ $slot }}
            @else
                <div class="layout-content">
                    <div class="content">
                        {{ $slot }}
                    </div>
                </div>
            @endif

            @if (isset($sidebar) && $sidebar->isNotEmpty())
                <div class="layout-sidebar" id="layout-sidebar">
                    {{ $sidebar }}
                </div>
            @endif
        </div>
    </div>

    <x-alerts />

    @livewireScripts
    @livewire('modal-basic', key('modal'))
    @stack('scripts')
</body>

</html>
