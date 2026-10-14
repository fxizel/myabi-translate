<div class="cell-caption">{{ __('ui.origin_organization') }} : {{ $proposal->organization?->name ?? __('ui.publisher_source') }}</div>
@php($originImports = collect([$proposal->import])->filter()->merge($proposal->imports)->unique('id'))
@if($originImports->isNotEmpty())
<div class="cell-caption">{{ __('ui.associated_imports') }} : @foreach($originImports as $originImport)@if(!$loop->first) · @endif #{{ $originImport->id }} ({{ $originImport->organization?->code ?? __('ui.publisher_source') }})@endforeach</div>
@if($proposal->edit_history)<div class="cell-caption">{{ __('ui.imports_historical_values') }}</div>@endif
@endif
