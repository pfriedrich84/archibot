<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head>
            <title>{{ config('app.name', 'ArchiBot') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <!--
        THESIS: ArchiBot is a calm transfer register for one document at a time; it refuses the generic all-at-once admin dashboard.
        OWN-WORLD: Mineral stock, archival green dividers, oxide decision marks, crisp ledger rules, and conventional admin controls.
        STORY: See what needs review, inspect the document beside its changes, decide safely, and move on; operational evidence stays available behind disclosure.
        FIRST VIEWPORT: A restrained register rail frames one dominant document workspace, with the next action visible and administration secondary.
        FORM: Transfer Register, third grounded direction, seed 557418cd, blended with polished conventional administration.
        FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, and DESIGN.md
        -->
        <x-inertia::app />
    </body>
</html>
