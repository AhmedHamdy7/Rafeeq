{{--
    The API docs page. Scramble's own page embeds the whole 3 MB document in the HTML; this one
    lets Stoplight fetch it from /docs/api.json, which is served from a file (ApiDocsController).
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ config('scramble.ui.title', 'RAFEEQ API') }}</title>
    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">
    <style>html, body { margin: 0; height: 100%; }</style>
</head>
<body style="height: 100vh; overflow-y: hidden">
    <elements-api apiDescriptionUrl="{{ $specUrl }}" router="hash" layout="sidebar" tryItCredentialsPolicy="omit"></elements-api>
</body>
</html>
