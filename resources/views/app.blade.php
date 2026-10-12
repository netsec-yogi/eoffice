<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csp-nonce" content="{{ Vite::cspNonce() }}">
        <title inertia>{{ config('app.name') }}</title>
        <script src="/js/theme.js" nonce="{{ Vite::cspNonce() }}"></script>
        @vite('resources/js/app.ts')
        <x-inertia::head />
    </head>
    <body class="h-full">
        <x-inertia::app />
    </body>
</html>
