<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    {{-- Timestamps are stored in UTC and shown in the Property's timezone (design §7.3). --}}
    <meta name="property-timezone" content="{{ \App\Models\Property::query()->value('timezone') ?? 'Asia/Kuala_Lumpur' }}">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="root"></div>
</body>
</html>
