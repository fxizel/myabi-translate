@extends('layouts.app')
@section('title', __('ui.translations'))
@section('content')
@include('partials.heading', ['heading'=>__('ui.translations'), 'description'=>__('ui.translate_description'), 'eyebrow'=>'01 / '.__('ui.workspace')])
@include('partials.languages')
@include('partials.term-filters')

<form id="bulk-terms" method="post" action="{{ url('proposals/bulk-store') }}" data-bulk-proposals data-confirm="{{ __('ui.confirm_bulk') }}">
@csrf
<input type="hidden" name="confirmed" value="1">
<input type="hidden" name="language" value="{{ $language }}">
@if($canManage ?? false)
<section class="surface block catalogue-actions" aria-labelledby="catalogue-actions-title">
    <h2 id="catalogue-actions-title">{{ __('campaigns.selection') }}</h2>
    <p class="field-hint">{{ __('campaigns.selection_hint') }}</p>
    <div class="catalogue-action-grid">
        <div class="catalogue-action-group">
            <label class="field" for="selection-group"><span>{{ __('campaigns.group') }}</span></label>
            <div class="row">
                <select class="input" id="selection-group" name="group_id"><option value="">{{ __('campaigns.no_group') }}</option>@foreach($groups->whereNull('archived_at') as $group)<option value="{{ $group->id }}">{{ $group->title }}</option>@endforeach</select>
                <button class="button" type="submit" formaction="{{ route('groups.assign') }}" data-catalogue-submit>{{ __('campaigns.assign_group') }}</button>
            </div>
        </div>
        <div class="catalogue-action-group">
            <label class="field" for="selection-campaign"><span>{{ __('campaigns.campaign') }}</span></label>
            <div class="row">
                <select class="input" id="selection-campaign" name="campaign_id"><option value="">{{ __('campaigns.choose_campaign') }}</option>@foreach($compositionCampaigns as $compositionCampaign)<option value="{{ $compositionCampaign->id }}">{{ $compositionCampaign->title }} · {{ strtoupper($compositionCampaign->language) }}</option>@endforeach</select>
                <button class="button" type="submit" formaction="{{ route('campaigns.compose') }}" data-catalogue-submit>{{ __('campaigns.add_existing') }}</button>
                <button class="button quiet" type="submit" formaction="{{ route('campaigns.prepare') }}" data-catalogue-submit>{{ __('campaigns.new_campaign') }}</button>
            </div>
        </div>
    </div>
</section>
@endif
<p class="field-hint">{{ __('tables.sort_help') }}</p>
<div class="bulk-bar"><span>{{ number_format($terms->total(), 0, '.', '’') }} {{ __('ui.results') }} · <strong data-selected-count>0</strong> {{ __('ui.selected') }}</span><div class="row"><details><summary>{{ __('ui.columns') }}</summary><div class="check-group section">@foreach(['key','state'] as $column)<label class="check-label"><input type="checkbox" checked data-column-toggle="{{ $column }}">{{ __('ui.'.$column) }}</label>@endforeach</div></details><button class="button" type="submit" data-bulk-submit disabled>{{ __('ui.propose') }} →</button></div></div>
</form>
<div class="table-wrap" role="region" aria-label="{{ __('ui.translations') }}" tabindex="0"><table class="catalogue-table" data-sortable-table data-sort-asc="{{ __('tables.sort_asc') }}" data-sort-desc="{{ __('tables.sort_desc') }}"><thead><tr><th class="checkbox-cell"><input type="checkbox" form="bulk-terms" data-select-all aria-label="{{ __('ui.select_all') }}"></th>
@include('partials.sortable-heading', ['columnIndex'=>1, 'columnName'=>'key', 'label'=>__('ui.key')])
@include('partials.sortable-heading', ['columnIndex'=>2, 'cellClass'=>'source-cell', 'label'=>__('ui.reference'), 'suffix'=>'de_CH'])
@include('partials.sortable-heading', ['columnIndex'=>3, 'cellClass'=>'translation-cell', 'label'=>__('ui.current_value'), 'suffix'=>$language])
@include('partials.sortable-heading', ['columnIndex'=>4, 'cellClass'=>'translation-cell', 'label'=>__('ui.proposal')])
@include('partials.sortable-heading', ['columnIndex'=>5, 'columnName'=>'state', 'label'=>__('ui.state')])
@include('partials.sortable-heading', ['columnIndex'=>6, 'columnName'=>'group', 'label'=>__('campaigns.group')])
</tr></thead><tbody>
@forelse($terms as $term)
@php($display = $term->attributesForDisplay())
@php($hasPending = $term->pending_count > 0)
@php($canPropose = !$hasPending && auth()->user()->canTranslate($language) && !$term->obsolete)
@php($firstAttribute = $term->display_attribute)
@php($restoredSingle = old('term_id') == $term->id && old('attribute') === $firstAttribute && old('language') === $language && !old('proposal_id') && !old('supersedes_id') && \Illuminate\Support\Arr::has(session()->getOldInput(), 'value'))
@php($restoredBulk = old('rows.'.$term->id.'.attribute') === $firstAttribute && old('rows.'.$term->id.'.language') === $language && \Illuminate\Support\Arr::has(session()->getOldInput(), 'rows.'.$term->id.'.value'))
@php($restoredValue = $restoredSingle ? old('value','') : ($restoredBulk ? old('rows.'.$term->id.'.value','') : ''))
<tr>
    <td class="checkbox-cell">@if(($canManage ?? false) || $canPropose)<input type="checkbox" form="bulk-terms" name="term_ids[]" value="{{ $term->id }}" data-select-item data-term-id="{{ $term->id }}" data-proposal-eligible="{{ $canPropose ? '1' : '0' }}" @checked(in_array($term->id, (array) old('term_ids', [])) || old('rows.'.$term->id.'.selected')) aria-label="{{ __('ui.select_item') }} {{ $term->label }}">@endif</td>
    <td data-column="key" data-sort-value="{{ $term->label }}"><a class="key" href="{{ url('terms/'.$term->id) }}?language={{ $language }}">{{ $term->label }}</a><span class="cell-caption">{{ $types[$term->type] ?? $term->type }}</span></td>
    <td data-sort-value="{{ data_get($display, $firstAttribute.'.reference', '') }}"><span class="cell-caption">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$firstAttribute) ? __('catalogue.attributes.'.$firstAttribute) : $firstAttribute }}</span><a class="source-text" href="{{ url('terms/'.$term->id) }}?language={{ $language }}#translation-0">{{ data_get($display, $firstAttribute.'.reference', '—') }}</a>@if($term->context)<span class="cell-caption">{{ $term->context }}</span>@endif</td>
    <td data-sort-value="{{ $term->current_value ?? '' }}"><div class="translation-text">@if($term->current_value !== null && $term->current_value !== ''){{ $term->current_value }}@else<span class="empty-value">{{ __('ui.no_translation') }}</span>@endif</div></td>
    <td data-sort-value="{{ $term->pending_value ?? '' }}">
        @if($hasPending)<div class="translation-text">{{ $term->pending_value }}</div><span class="cell-caption">{{ trans_choice('catalogue.pending_count', $term->pending_count, ['count'=>$term->pending_count]) }}@if($term->pending_count > 1) · {{ __('catalogue.latest_proposal') }}@endif · <a href="{{ url('terms/'.$term->id) }}?language={{ $language }}#proposals">{{ __('ui.details') }} →</a></span>
        @elseif(auth()->user()->canTranslate($language) && !$term->obsolete)
        <form method="post" action="{{ url('terms/'.$term->id.'/proposals') }}">@csrf<input type="hidden" name="term_id" value="{{ $term->id }}"><input type="hidden" name="attribute" value="{{ $firstAttribute }}"><input type="hidden" name="language" value="{{ $language }}"><input type="hidden" name="lock_version" value="{{ $term->lock_version ?? 1 }}"><x-proposal-editor :id="'proposal-'.$term->id" :label="__('ui.proposal').' · '.$term->label" :value="$restoredValue" :restored="$restoredSingle || $restoredBulk" :source="data_get($display, $firstAttribute.'.reference', '')" :compact="true" /><div class="form-actions"><button class="button small" type="submit">{{ __('ui.propose') }} →</button></div></form>
        @else<span class="empty-value">{{ __('ui.no_proposal') }}</span>@endif
        @if($term->other_pending_attributes->isNotEmpty())<div class="cell-caption">{{ __('catalogue.other_pending_attributes') }} : @foreach($term->other_pending_attributes as $pendingAttribute=>$pendingCount)<a href="{{ url('terms/'.$term->id) }}?language={{ $language }}#translation-{{ array_search($pendingAttribute, array_keys($display), true) }}">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$pendingAttribute) ? __('catalogue.attributes.'.$pendingAttribute) : $pendingAttribute }} ({{ $pendingCount }})</a>@unless($loop->last) · @endunless @endforeach</div>@endif
    </td>
    <td data-column="state" data-sort-value="{{ __('ui.status_'.($term->current_state ?? 'missing')) }}">@include('partials.status',['status'=>$term->current_state ?? 'missing'])</td>
    <td data-column="group">@if(!$term->group || $groups->contains('id',$term->term_group_id))@include('groups.badge',['group'=>$term->group])@else<span class="empty-value">{{ __('campaigns.restricted_group') }}</span>@endif </td>
</tr>
@empty<tr><td colspan="7"><div class="empty-state"><h2>{{ __('ui.empty_title') }}</h2><p>{{ __('ui.empty_description') }}</p>@if($canManage ?? false)<a href="{{ url('imports') }}" class="button primary">{{ __('ui.new_import') }} →</a>@endif</div></td></tr>@endforelse
</tbody></table></div>
@include('partials.pagination', ['items'=>$terms])
<script src="{{ asset('js/table-sort.js') }}" defer></script>
@endsection
