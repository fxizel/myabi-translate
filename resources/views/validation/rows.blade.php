@forelse($proposals as $proposal)
@php($term = $proposal->term)
@php($display = $term->attributesForDisplay())
<tr>
    <td class="checkbox-cell"><input type="checkbox" name="selection[{{ $proposal->id }}]" value="{{ $proposal->lock_version }}" data-select-item aria-label="{{ __('ui.select_item') }} {{ $term->label }}"></td>
    <td><span class="source-text">{{ data_get($display, $proposal->attribute.'.reference', $term->source_text) }}</span><a class="key" href="{{ url('terms/'.$term->id) }}?language={{ $proposal->language }}">{{ $term->label }}</a><span class="cell-caption">{{ $types[$term->type] ?? $term->type }} · {{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$proposal->attribute) ? __('catalogue.attributes.'.$proposal->attribute) : $proposal->attribute }}</span></td>
    <td><span class="translation-text" data-current-diff>{{ data_get($term->validated, $proposal->attribute.'.'.$proposal->language) ?? __('ui.no_translation') }}</span></td>
    <td><div class="translation-text" data-diff-current="{{ data_get($term->validated, $proposal->attribute.'.'.$proposal->language) }}">{{ $proposal->value }}</div>@if($proposal->anomalies)<div class="notice error section"><strong>{{ __('ui.blocking_anomalies') }}</strong><ul>@foreach($proposal->anomalies as $anomaly)<li>{{ \App\Domain\Devconf\TranslationValidator::displayMessage($anomaly) }}</li>@endforeach</ul></div>@endif</td>
    <td><small>{{ $proposal->author?->name ?? __('ui.imports') }}</small><span class="cell-caption">{{ $proposal->created_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</span>@if($proposal->author_id === auth()->id())<span class="cell-caption">{{ __('ui.four_eyes') }}</span>@endif</td>
    <td><a class="button small" href="{{ url('terms/'.$term->id) }}?language={{ $proposal->language }}">{{ __('ui.open') }} →</a></td>
</tr>
@empty<tr><td colspan="6"><div class="empty-state"><h2>{{ __('ui.empty_title') }}</h2><p>{{ __('ui.no_proposal') }}</p><a href="{{ url('terms') }}" class="button">{{ __('ui.translations') }} →</a></div></td></tr>@endforelse
