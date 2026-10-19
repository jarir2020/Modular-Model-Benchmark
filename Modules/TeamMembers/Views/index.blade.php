<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Team members | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="team-members-app">
            <p>Loading team members…</p>
        </div>
    </main>

    <script id="team-members-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
