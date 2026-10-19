<!doctype html>
{{-- Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Tasks module, roadmap #2. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Tasks | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="tasks-app">
            <p>Loading tasks…</p>
        </div>
    </main>

    <script id="tasks-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
