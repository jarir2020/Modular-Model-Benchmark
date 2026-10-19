<!doctype html>
{{-- Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Task Dependencies | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="task-dependencies-app">
            <p>Loading dependencies…</p>
        </div>
    </main>

    <script id="task-dependencies-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
