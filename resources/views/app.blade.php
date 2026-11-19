<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#1D4ED8">

        <title inertia>{{ config('app.name', 'Nusantara ERP') }}</title>

        <!-- Inter + IBM Plex Sans untuk angka tabular-nums; JetBrains Mono khusus untuk nomor dokumen & SKU (12.2) -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="h-full font-sans text-[13px] antialiased bg-[#F6F8FA] text-slate-900 dark:bg-[#0D1117] dark:text-slate-100 selection:bg-[#1D4ED8] selection:text-white">
        @inertia
    </body>
</html>
