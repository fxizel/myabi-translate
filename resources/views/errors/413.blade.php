@php
    // PHP rejects oversized requests before the session and user locale are available.
    $locale = request()->getPreferredLanguage(['de', 'fr', 'it']) ?? 'de';
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>413 · {{ __('ui.upload_too_large_title', [], $locale) }} · myABI Translate</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=2">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main class="auth-panel">
        <p class="eyebrow">myABI Translate / 413</p>
        <h1>{{ __('ui.upload_too_large_title', [], $locale) }}</h1>
        <section class="surface block">
            <p>{{ __('ui.upload_too_large_help', [], $locale) }}</p>
            <a class="button primary" href="{{ url('imports') }}">{{ __('ui.upload_return', [], $locale) }}</a>
        </section>
    </main>
</body>
</html>
