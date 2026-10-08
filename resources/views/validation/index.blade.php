@extends('layouts.app')
@section('title', __('ui.validation'))
@section('content')
@include('partials.heading', ['heading'=>__('ui.validation'), 'description'=>__('ui.review_description'), 'eyebrow'=>'02 / '.__('ui.workspace')])
@include('partials.languages',['validationLanguages'=>true])
@include('partials.term-filters', ['validationFilter'=>true])
@php($filteredRunning = collect($filteredOperations ?? [])->contains(fn ($operation) => in_array($operation->status, ['queued', 'processing'])))
<form method="post" action="{{ route('proposals.validate-filtered') }}" class="surface block section" id="validation-filtered-form" data-confirm="{{ __('ui.filtered_confirm', ['count'=>number_format($proposalTotal, 0, '.', '’')]) }}">
    @csrf
    <input type="hidden" name="snapshot_token" value="{{ $filteredSnapshot ?? '' }}">
    <input type="hidden" name="confirmed" value="1">
    <div class="form-actions">
        <button class="button primary" type="submit" @disabled($filteredRunning || $proposalTotal === 0 || !($filteredSnapshot ?? null))>{{ __('ui.filtered_validate', ['count'=>number_format($proposalTotal, 0, '.', '’')]) }}</button>
        @if($canManage ?? false)<label class="check-label"><input type="checkbox" name="override" value="1">{{ __('ui.manager_override') }}</label>@endif
    </div>
    <p class="field-hint">{{ __('ui.filtered_help') }}</p>
    @if($filteredRunning)<p class="field-hint">{{ __('ui.filtered_already_running') }}</p>@endif
    @if($proposalTotal > 0 && !($filteredSnapshot ?? null))<p class="notice error" role="alert">{{ __('ui.filtered_stale') }}</p>@endif
</form>
@include('validation.filtered-operations')
@if($showAll)
<p class="field-hint">{{ __('ui.validation_all_help') }}</p>
@endif
<form method="post" action="{{ url('proposals/bulk') }}" id="validation-form" data-confirm="{{ __('ui.confirm_bulk') }}">@csrf<input type="hidden" name="confirmed" value="1">
@if($canManage ?? false)<label class="check-label section"><input type="checkbox" name="override" value="1">{{ __('ui.manager_override') }}</label>@endif
<div class="bulk-bar"><span><strong data-selected-count>0</strong> {{ __('ui.selected') }} · {{ number_format($proposalTotal, 0, '.', '’') }} {{ __('ui.proposals') }}</span><div class="row"><label class="sr-only" for="bulk-decision">{{ __('ui.decision') }}</label><select id="bulk-decision" class="input" name="decision" data-rejection-reason><option value="validate">{{ __('ui.validate') }}</option><option value="reject">{{ __('ui.reject') }}</option></select><label class="sr-only" for="bulk-reason">{{ __('ui.reason') }}</label><input class="input" id="bulk-reason" name="reason" placeholder="{{ __('ui.rejection_details') }}" maxlength="2000"><button class="button primary" type="submit" data-bulk-submit>{{ __('ui.submit') }} →</button></div></div>
@if($showAll)<p class="notice error" role="alert" data-selection-limit hidden>{{ __('ui.validation_selection_limit') }}</p>@endif
<div class="table-wrap" role="region" aria-label="{{ __('ui.validation') }}" tabindex="0"><table class="validation-table"><thead><tr><th class="checkbox-cell"><input type="checkbox" data-select-all aria-label="{{ __('ui.select_all') }}"></th><th class="source-cell">{{ __('ui.reference') }} · de_CH</th><th class="translation-cell">{{ __('ui.current_value') }}</th><th class="translation-cell">{{ __('ui.proposal') }} · {{ $language }}</th><th>{{ __('ui.author') }}</th><th>{{ __('ui.details') }}</th></tr></thead><tbody>
@include('validation.rows')
</tbody></table></div>
</form>
@if($showAll)
<div class="pagination" data-validation-loader data-total="{{ $proposalTotal }}" data-count-label="{{ __('ui.validation_loaded', ['count'=>':count', 'total'=>':total']) }}" data-loading-label="{{ __('ui.validation_loading') }}" data-error-label="{{ __('ui.validation_load_error') }}">
    <span role="status" aria-live="polite" data-load-status>{{ __('ui.validation_loaded', ['count'=>number_format($proposals->count(), 0, '.', '’'), 'total'=>number_format($proposalTotal, 0, '.', '’')]) }}</span>
    <div class="row">
        @if($proposals->previousPageUrl())<noscript><a class="button" href="{{ $proposals->previousPageUrl() }}">← {{ __('ui.previous') }}</a></noscript>@endif
        @if($proposals->nextPageUrl())<a class="button" href="{{ $proposals->nextPageUrl() }}" data-load-more>{{ __('ui.validation_load_more') }} →</a>@endif
    </div>
</div>
<script src="{{ asset('js/validation.js') }}" defer></script>
@else
@include('partials.pagination', ['items'=>$proposals])
@endif
@endsection
