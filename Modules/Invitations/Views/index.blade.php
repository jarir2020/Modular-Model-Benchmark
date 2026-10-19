<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invitations</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="invitations-app" data-page="InvitationsIndex"></div>
    <script>
        window.__PAGE_PROPS__ = @json($pageProps);
    </script>
</body>
</html>