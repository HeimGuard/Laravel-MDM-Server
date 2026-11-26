{{-- Installable as an app: what the @pwa directive of steelants/laravel-general prints, with the icon and the manifest in a URL that
     changes with the file. The directive's apple-touch-icon (/pwa/ios/180.png) and manifest had no version, so a phone that
     added the portal to the home screen kept the old icon for ever after the icon was replaced (the ?v= in manifest.json does not
     reach them). The version is a hash of the file: replacing the icon is all it takes. --}}
@php
    $pwaVersion = fn (string $file): string => is_file($path = public_path($file)) ? substr(md5_file($path), 0, 8) : '0';
@endphp
<!-- PWA -->
<meta name="description" content="HeimGuard">
<meta name="author" content="HeimGuard">
<meta name="theme-color" content="#f9fbfc">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="HeimGuard">
<meta name="mobile-web-app-capable" content="yes">
<meta property="og:image" content="{{ asset('/pwa/ios/180.png') }}?v={{ $pwaVersion('pwa/ios/180.png') }}">
<link rel="manifest" href="{{ asset('/manifest.json') }}?v={{ $pwaVersion('manifest.json') }}">
<link rel="apple-touch-icon" href="{{ asset('/pwa/ios/180.png') }}?v={{ $pwaVersion('pwa/ios/180.png') }}">
@if (is_file(public_path('sw.js')))
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('{{ asset('/sw.js') }}').catch(function (err) {
                console.warn('SW registration failed:', err);
            });
        }
    </script>
@endif
