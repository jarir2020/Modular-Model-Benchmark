<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Roles | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="roles-app">
            <p>Loading roles…</p>
        </div>
    </main>

    <script id="roles-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
