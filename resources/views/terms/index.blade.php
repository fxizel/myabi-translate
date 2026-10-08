@extends('layouts.app')
@section('title', __('ui.translations'))
@section('content')
@include('partials.heading', ['heading'=>__('ui.translations'), 'description'=>__('ui.translate_description'), 'eyebrow'=>'01 / '.__('ui.workspace')])
@include('partials.languages')
@include('partials.term-filters')
@if(isset($typeCounts) && count($typeCounts))<nav class="type-counts" aria-label="{{ __('ui.type') }}">@foreach($typeCounts as $typeCode=>$typeTotal)<a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->except('page'),['type'=>$typeCode])) }}" @if(request('type')===$typeCode) aria-current="page" @endif>{{ $types[$typeCode] ?? $typeCode }} <span>{{ number_format($typeTotal,0,'.','’') }}</span></a>@endforeach</nav>@endif
<form id="bulk-terms" method="post" action="{{ url('proposals/bulk-store') }}" data-bulk-proposals data-confirm="{{ __('ui.confirm_bulk') }}">@csrf<input type="hidden" name="confirmed" value="1"><div class="bulk-bar"><span>{{ number_format($terms->total(), 0, '.', '’') }} {{ __('ui.results') }} · <strong data-selected-count>0</strong> {{ __('ui.selected') }}</span><div class="row"><details><summary>{{ __('ui.columns') }}</summary><div class="check-group section">@foreach(['key','scope','state'] as $column)<label class="check-label"><input type="checkbox" checked data-column-toggle="{{ $column }}">{{ __('ui.'.$column) }}</label>@endforeach</div></details><button class="button" data-bulk-submit disabled>{{ __('ui.propose') }} →</button></div></div></form>
<div class="table-wrap" role="region" aria-label="{{ __('ui.translations') }}" tabindex="0"><table class="catalogue-table"><thead><tr><th class="checkbox-cell"><input type="checkbox" form="bulk-terms" data-select-all aria-label="{{ __('ui.select_all') }}"></th><th data-column="key">{{ __('ui.key') }}</th><th class="source-cell">{{ __('ui.reference') }} <small>de_CH</small></th><th class="translation-cell">{{ __('ui.current_value') }} <small>{{ $language }}</small></th><th class="translation-cell">{{ __('ui.proposal') }}</th><th data-column="state">{{ __('ui.state') }}</th><th data-column="scope">{{ __('ui.scope') }}</th></tr></thead><tbody>
@forelse($terms as $term)
@php($display = $term->attributesForDisplay())
@php($hasPending = $term->pending_count > 0)
@php($firstAttribute = $term->display_attribute)
@php($restoredSingle = old('term_id') == $term->id && old('attribute') === $firstAttribute && old('language') === $language && !old('proposal_id') && !old('supersedes_id') && \Illuminate\Support\Arr::has(session()->getOldInput(), 'value'))
@php($restoredBulk = old('rows.'.$term->id.'.attribute') === $firstAttribute && old('rows.'.$term->id.'.language') === $language && \Illuminate\Support\Arr::has(session()->getOldInput(), 'rows.'.$term->id.'.value'))
@php($restoredValue = $restoredSingle ? old('value','') : ($restoredBulk ? old('rows.'.$term->id.'.value','') : ''))
<tr>
    <td class="checkbox-cell">@if(!$hasPending && auth()->user()->canTranslate($language,$term->organization_id) && !$term->obsolete)<input type="checkbox" form="bulk-terms" name="rows[{{ $term->id }}][selected]" value="1" data-select-item data-term-id="{{ $term->id }}" @checked(old('rows.'.$term->id.'.selected')) aria-label="{{ __('ui.select_item') }} {{ $term->label }}">@endif</td>
    <td data-column="key"><a class="key" href="{{ url('terms/'.$term->id) }}?language={{ $language }}">{{ $term->label }}</a><span class="cell-caption">{{ $types[$term->type] ?? $term->type }}</span></td>
    <td><span class="cell-caption">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$firstAttribute) ? __('catalogue.attributes.'.$firstAttribute) : $firstAttribute }}</span><a class="source-text" href="{{ url('terms/'.$term->id) }}?language={{ $language }}#translation-0">{{ data_get($display, $firstAttribute.'.reference', '—') }}</a>@if($term->context)<span class="cell-caption">{{ $term->context }}</span>@endif</td>
    <td><div class="translation-text">@if($term->current_value !== null && $term->current_value !== ''){{ $term->current_value }}@else<span class="empty-value">{{ __('ui.no_translation') }}</span>@endif</div></td>
    <td>
        @if($hasPending)<div class="translation-text">{{ $term->pending_value }}</div><span class="cell-caption">{{ trans_choice('catalogue.pending_count', $term->pending_count, ['count'=>$term->pending_count]) }}@if($term->pending_count > 1) · {{ __('catalogue.latest_proposal') }}@endif · <a href="{{ url('terms/'.$term->id) }}?language={{ $language }}#proposals">{{ __('ui.details') }} →</a></span>
        @elseif(auth()->user()->canTranslate($language, $term->organization_id) && !$term->obsolete)
        <form method="post" action="{{ url('terms/'.$term->id.'/proposals') }}">@csrf<input type="hidden" name="term_id" value="{{ $term->id }}"><input type="hidden" name="attribute" value="{{ $firstAttribute }}"><input type="hidden" name="language" value="{{ $language }}"><input type="hidden" name="lock_version" value="{{ $term->lock_version ?? 1 }}"><x-proposal-editor :id="'proposal-'.$term->id" :label="__('ui.proposal').' · '.$term->label" :value="$restoredValue" :restored="$restoredSingle || $restoredBulk" :source="data_get($display, $firstAttribute.'.reference', '')" :compact="true" /><div class="form-actions"><button class="button small" type="submit">{{ __('ui.propose') }} →</button></div></form>
        @else<span class="empty-value">{{ __('ui.no_proposal') }}</span>@endif
        @if($term->other_pending_attributes->isNotEmpty())<div class="cell-caption">{{ __('catalogue.other_pending_attributes') }} : @foreach($term->other_pending_attributes as $pendingAttribute=>$pendingCount)<a href="{{ url('terms/'.$term->id) }}?language={{ $language }}#translation-{{ array_search($pendingAttribute, array_keys($display), true) }}">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$pendingAttribute) ? __('catalogue.attributes.'.$pendingAttribute) : $pendingAttribute }} ({{ $pendingCount }})</a>@unless($loop->last) · @endunless @endforeach</div>@endif
    </td>
    <td data-column="state">@include('partials.status',['status'=>$term->current_state ?? 'missing'])</td>
    <td data-column="scope"><small>{{ $term->organization?->code ?? __('ui.common') }}</small>@if($term->scope_unconfirmed)<span class="cell-caption">{{ __('ui.scope_unconfirmed') }}</span>@endif</td>
</tr>
@empty<tr><td colspan="7"><div class="empty-state"><h2>{{ __('ui.empty_title') }}</h2><p>{{ __('ui.empty_description') }}</p>@if($canManage ?? false)<a href="{{ url('imports') }}" class="button primary">{{ __('ui.new_import') }} →</a>@endif</div></td></tr>@endforelse
</tbody></table></div>
@include('partials.pagination', ['items'=>$terms])
@endsection
