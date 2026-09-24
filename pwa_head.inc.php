<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6f4a2f">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Meu Evento">
<link rel="apple-touch-icon" sizes="180x180" href="/img/apple-touch-icon.png?v=2">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="/img/favicon-64.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  });
}
</script>
