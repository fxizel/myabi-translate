<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('ui.app_description') }}">
    <title>@yield('title', __('ui.translations')) · myABI Translate</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=2">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body data-confirm="{{ __('ui.confirm_action') }}" data-unsaved="{{ __('ui.unsaved_warning') }}" data-long-warning="{{ __('ui.long_value_warning') }}" data-unsent-label="{{ __('ui.unsent_draft') }}">
<a class="skip-link" href="#main-content">{{ __('ui.skip_content') }}</a>
@php
    $detailBack = match (true) {
        request()->routeIs('terms.show') => url('terms').'?'.http_build_query(['language' => $language ?? request('language', 'fr')]),
        request()->routeIs('imports.show', 'imports.validation-preview') => url('imports'),
        request()->routeIs('publications.show') => url('publications'),
        default => null,
    };
@endphp
<div class="app-shell @guest guest-shell @endguest">
    <header class="masthead">
        <div class="global-bar">
            <a class="brand" href="{{ url('/') }}" aria-label="myABI Translate · {{ __('ui.dashboard') }}">
                <img class="brand-logo" src="{{ asset('images/branding/myabi-translate-header-v2.png') }}" width="168" height="46" alt="myABI Translate" fetchpriority="high">
            </a>
            @auth
                <div class="global-tools"><a class="header-button" href="{{ url('/') }}" aria-label="{{ __('ui.dashboard') }}" title="{{ __('ui.dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>@include('partials.icon', ['name' => 'home'])</a></div>
            @endauth
            <span class="global-title">{{ __('ui.translation_registry') }}</span>
            @auth
                <div class="identity">
                    <a class="header-button" href="{{ url('help') }}" aria-label="{{ __('ui.help') }}" title="{{ __('ui.help') }}">@include('partials.icon', ['name' => 'help'])</a>
                    <a class="profile-link" href="{{ url('profile') }}" title="{{ __('ui.profile') }}" @if(request()->is('profile')) aria-current="page" @endif>@include('partials.icon', ['name' => 'user'])<span>{{ auth()->user()->name }}</span></a>
                    <span class="profile-organization">{{ auth()->user()->organization?->name ?? 'ARGE-ABI' }}</span>
                </div>
            @else
                <p class="masthead-note">ARGE-ABI</p>
            @endauth
        </div>
        <nav class="app-tab-strip" aria-label="{{ __('ui.workspace_navigation') }}">
            <span class="tab-grid" aria-hidden="true">@include('partials.icon', ['name' => 'grid'])</span>
            @auth
                <a class="app-tab" href="{{ url('terms') }}" @if(!$detailBack) aria-current="page" @endif><span class="tab-star" aria-hidden="true">★</span><span class="tab-name">{{ __('ui.translation_management') }}</span></a>
                @if($detailBack)
                    <span class="app-tab active" aria-current="page"><span class="tab-name">@if(request()->routeIs('terms.show')){{ $term->source_text !== null && $term->source_text !== '' ? $term->source_text : $term->label }}@elseif(request()->routeIs('imports.show')){{ $import->filename }}@elseif(request()->routeIs('publications.show')){{ $publication->number }}@else @yield('title', __('ui.details')) @endif</span><a class="tab-close" href="{{ $detailBack }}" aria-label="{{ __('ui.close_detail') }}" title="{{ __('ui.close_detail') }}">@include('partials.icon', ['name' => 'close'])</a></span>
                @endif
            @else
                <span class="app-tab active" aria-current="page"><span class="tab-name">@yield('title', __('ui.login'))</span></span>
            @endauth
        </nav>
    </header>
    <div class="app-main-layout">
        @auth
            <aside class="app-sidebar">
                <p class="side-label">{{ __('ui.registry') }}</p>
                <nav class="navigation" aria-label="{{ __('ui.main_navigation') }}">
                    <div class="nav-group">
                        @foreach ([['terms', 'languages', 'translations'], ['validation', 'check-list', 'validation'], ['imports', 'upload', 'imports'], ['exports', 'download', 'exports'], ['publications', 'download', 'publications']] as [$path, $icon, $label])
                            @if((!in_array($path, ['imports', 'exports'], true) || ($canManage ?? false)) && ($path !== 'validation' || ($canValidate ?? false)))
                                <a href="{{ url($path) }}" @if(request()->is($path, $path.'/*')) aria-current="page" @endif>@include('partials.icon', ['name' => $icon])<span class="nav-label">{{ __('ui.'.$label) }} @if($path === 'validation' && ($pendingCount ?? 0) > 0)<span class="count">{{ number_format($pendingCount, 0, '.', '’') }}</span>@endif</span></a>
                            @endif
                        @endforeach
                    </div>
                    @if(($canManage ?? false) || ($canAdmin ?? false))
                        <div class="nav-group">
                            <p class="side-label">{{ __('ui.administration') }}</p>
                            @if($canAdmin ?? false)<a href="{{ url('admin') }}" @if(request()->is('admin', 'admin/*')) aria-current="page" @endif>@include('partials.icon', ['name' => 'settings'])<span class="nav-label">{{ __('ui.administration') }}</span></a>@endif
                            <a href="{{ url('audit') }}" @if(request()->is('audit', 'audit/*')) aria-current="page" @endif>@include('partials.icon', ['name' => 'history'])<span class="nav-label">{{ __('ui.audit') }}</span></a>
                        </div>
                    @endif
                </nav>
                <div class="side-context">
                    <p class="side-label">{{ __('ui.work_context') }}</p>
                    <div class="context-item"><span>{{ __('ui.organization') }}</span><strong>{{ auth()->user()->organization?->name ?? 'ARGE-ABI' }}</strong></div>
                    <div class="context-item"><span>{{ __('ui.reference') }}</span><strong>{{ __('ui.de') }} · de_CH</strong></div>
                </div>
                <div class="side-footer">ARGE-ABI<br>{{ __('ui.shared_reference') }}</div>
            </aside>
        @endauth
        <div class="workspace">
            <div class="context-bar">
                <div class="breadcrumb"><a href="{{ url('/') }}">{{ __('ui.registry') }}</a><span aria-hidden="true">›</span><strong>@yield('title', __('ui.translations'))</strong></div>
                <div class="context-actions">
                    @auth<button class="button quiet density-toggle" type="button" data-density-toggle aria-pressed="true" title="{{ __('ui.toggle_density') }}">@include('partials.icon', ['name' => 'rows'])<span>{{ __('ui.compact_view') }}</span></button>@endauth
                    <form action="{{ url('locale') }}" method="post" class="locale-form">@csrf<label class="sr-only" for="interface-locale">{{ __('ui.interface_language') }}</label><select id="interface-locale" name="locale" data-auto-submit>@foreach(['de'=>'DE', 'fr'=>'FR', 'it'=>'IT'] as $code=>$label)<option value="{{ $code }}" @selected(app()->getLocale()===$code)>{{ $label }}</option>@endforeach</select><button class="sr-only">{{ __('ui.save') }}</button></form>
                    @auth<form action="{{ url('logout') }}" method="post">@csrf<button class="text-button">{{ __('ui.logout') }}</button></form>@endauth
                </div>
            </div>
            <main id="main-content" tabindex="-1">
                @if(($writesPaused ?? false) && auth()->check())<div class="notice" role="status">{{ __('ui.writes_paused') }}</div>@endif
                @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
                @if(session('warning'))<div class="notice" role="status">{{ session('warning') }}</div>@endif
                @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
                @if($errors->any())<div class="notice error" role="alert"><strong>{{ __('ui.review_errors') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ __($error) }}</li>@endforeach</ul></div>@endif
                @yield('content')
            </main>
            <footer class="app-footer"><span>ARGE-ABI <span aria-hidden="true">/</span> myABI Translate</span><span>{{ __('ui.shared_reference') }}</span><a href="{{ url('help') }}">{{ __('ui.help') }}</a></footer>
        </div>
    </div>
</div>
</body>
</html>
