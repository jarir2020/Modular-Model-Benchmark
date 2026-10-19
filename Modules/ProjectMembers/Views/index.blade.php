<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Project members | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="project-members-app">
            <p>Loading project members…</p>
        </div>
    </main>

    <script id="project-members-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
