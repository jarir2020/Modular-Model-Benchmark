<!doctype html>
{{-- Implemented with model: kr/qwen3-coder-next (Qwen 3 Coder Next) — Comments module, roadmap #5. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $pageProps['csrfToken'] }}">
    <title>Comments | Project Workbench</title>
    @vite('resources/js/app.js')
</head>
<body>
    <main>
        <div id="comments-app">
            <p>Loading comments…</p>
        </div>
    </main>

    <script id="comments-page-props" type="application/json">@json($pageProps)</script>
</body>
</html>
