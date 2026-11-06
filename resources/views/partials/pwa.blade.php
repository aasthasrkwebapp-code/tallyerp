{{-- Installable web app: manifest, icons and the service worker (see public/sw.js). --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#172033">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="TallyERP">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
