<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Teams | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="teams-app">
            <p>Loading teams…</p>
        </div>
    </main>

    <script id="teams-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>