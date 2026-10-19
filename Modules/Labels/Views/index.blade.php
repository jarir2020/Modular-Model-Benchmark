<!doctype html>
{{-- Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview, running as Claude Code) — Labels module, roadmap #4. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Labels | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="labels-app">
            <p>Loading labels…</p>
        </div>
    </main>

    <script id="labels-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
