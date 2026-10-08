@php($comparisonAttributes = array_unique(array_merge(array_keys($comparison['from']['attributes']),array_keys($comparison['to']['attributes']))))
<div class="table-wrap section"><table><thead><tr><th>{{ __('ui.attribute') }}</th><th>{{ __('ui.language') }}</th><th>{{ __('ui.from_revision') }} · r{{ $comparison['from']['revision']->number }}</th><th>{{ __('ui.to_revision') }} · r{{ $comparison['to']['revision']->number }}</th></tr></thead><tbody>
@foreach($comparisonAttributes as $attribute)
@foreach(['de','fr','it','en'] as $lang)
@php($fromValue = data_get($comparison['from']['validated'],$attribute.'.'.$lang,data_get($comparison['from']['attributes'],$attribute.'.translations.'.$lang,'')))
@php($toValue = data_get($comparison['to']['validated'],$attribute.'.'.$lang,data_get($comparison['to']['attributes'],$attribute.'.translations.'.$lang,'')))
<tr><td>{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$attribute) ? __('catalogue.attributes.'.$attribute) : $attribute }}</td><td>{{ strtoupper($lang) }}</td><td class="preserve" data-current-diff>{{ $fromValue }}</td><td class="preserve" data-diff-current="{{ $fromValue }}">{{ $toValue }}</td></tr>
@endforeach
@endforeach
</tbody></table></div>
