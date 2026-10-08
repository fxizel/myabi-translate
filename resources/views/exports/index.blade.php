@extends('layouts.app')
@section('title', __('ui.exports'))
@section('content')
@include('partials.heading', ['heading' => __('ui.exports'), 'description' => __('ui.exports_description')])
@php
    $selectedType = old('type', '');
    $selectedType = is_scalar($selectedType) ? (string) $selectedType : '';
    $selectedVersion = old('version_id', $versions->first()?->id);
    $selectedVersion = is_scalar($selectedVersion) ? (string) $selectedVersion : (string) ($versions->first()?->id ?? '');
    $selectedOrganization = old('organization_id', $defaultOrganizationId);
    $selectedOrganization = is_scalar($selectedOrganization) ? (string) $selectedOrganization : (string) ($defaultOrganizationId ?? '');
    $exportUnavailable = $versions->isEmpty() || $organizations->isEmpty();
@endphp
<section class="surface block" aria-labelledby="export-title">
    <h2 id="export-title">{{ __('ui.export_download') }}</h2>
    <p class="field-hint section" id="export-help">{{ __('ui.export_help') }}</p>
    @if($exportUnavailable)
        <div class="notice section" role="status">{{ __('ui.export_empty') }}</div>
    @endif
    <form class="section" method="post" action="{{ route('exports.download') }}" aria-describedby="export-help">
        @csrf
        <div class="form-grid">
            <label class="field">
                <span>{{ __('ui.type') }}</span>
                <select class="input" name="type" required>
                    <option value="" @selected($selectedType === '')>{{ __('ui.export_choose_type') }}</option>
                    @foreach($types as $code => $label)
                        <option value="{{ $code }}" @selected($selectedType === (string) $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>{{ __('ui.version') }}</span>
                <select class="input" name="version_id" required>
                    @forelse($versions as $version)
                        <option value="{{ $version->id }}" @selected($selectedVersion === (string) $version->id)>{{ $version->number }} · {{ __('ui.status_'.$version->status) }}</option>
                    @empty
                        <option value="">{{ __('ui.none') }}</option>
                    @endforelse
                </select>
            </label>
            <label class="field">
                <span>{{ __('ui.organization') }}</span>
                <select class="input" name="organization_id" required>
                    <option value="" @selected($selectedOrganization === '')>{{ __('ui.export_choose_organization') }}</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}" @selected($selectedOrganization === (string) $organization->id)>{{ $organization->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="form-actions">
            <button class="button primary" @disabled($exportUnavailable)>@include('partials.icon', ['name' => 'download']){{ __('ui.export_download') }}</button>
        </div>
    </form>
</section>
@endsection
