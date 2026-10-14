@extends('layouts.app')
@section('title', __('ui.help'))
@section('content')
@include('partials.heading', ['heading' => __('help.title'), 'description' => __('help.description'), 'eyebrow' => 'ARGE-ABI / myABI'])
@php
    $sections = [
        'catalogue' => ['terms.index', true],
        'translate' => ['terms.index', true],
        'groups' => ['groups.index', true],
        'campaigns' => ['campaigns.index', true],
        'validate' => ['validation.index', auth()->user()->canValidate()],
        'import' => ['imports.index', auth()->user()->canManage()],
        'deliver' => ['publications.index', true],
        'profile' => ['profile.edit', true],
    ];
@endphp
<div class="content-narrow stack-gap">
    <section class="surface block" aria-labelledby="help-start">
        <h2 id="help-start">{{ __('help.start') }}</h2>
        <p class="section">{{ __('help.common') }}</p>
        <p class="section">{{ __('help.permissions') }}</p>
        <nav class="row section" aria-label="{{ __('help.contents') }}">
            @foreach($sections as $section => $destination)
                <a href="#help-{{ $section }}">{{ __('help.sections.'.$section.'.title') }}</a>
            @endforeach
        </nav>
    </section>
    @foreach($sections as $section => [$route, $allowed])
        <section class="surface block" aria-labelledby="help-{{ $section }}">
            <p class="eyebrow">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</p>
            <h2 id="help-{{ $section }}">{{ __('help.sections.'.$section.'.title') }}</h2>
            @foreach(__('help.sections.'.$section.'.paragraphs') as $paragraph)
                <p class="section">{{ $paragraph }}</p>
            @endforeach
            @if($allowed)
                <div class="form-actions section">
                    <a class="button" href="{{ route($route) }}">{{ __('help.sections.'.$section.'.action') }} →</a>
                    @if($section === 'deliver' && auth()->user()->canManage())
                        <a class="button" href="{{ route('exports.index') }}">{{ __('ui.exports') }} →</a>
                    @endif
                </div>
            @endif
        </section>
    @endforeach
    <aside class="notice section" aria-label="{{ __('help.rules') }}">
        <p>{{ __('ui.proposal_hint') }}</p>
        <p class="section">{{ __('ui.four_eyes') }}</p>
        <p class="section">{{ __('ui.immutable_help') }}</p>
    </aside>
</div>
@endsection
