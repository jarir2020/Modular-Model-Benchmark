<!doctype html>
{{-- Implemented with model: claude-sonnet-5-5 (Claude Sonnet 5.5) — Checklists module, roadmap #7. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Checklists | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
<main>
    <div id="checklists-app">
        <p>Loading checklists…</p>
    </div>
</main>

<script id="checklists-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
