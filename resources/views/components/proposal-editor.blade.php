@props(['id', 'label', 'value' => '', 'source' => '', 'restored' => false, 'compact' => false, 'hint' => null])
@if($compact)<label for="{{ $id }}" class="sr-only">{{ $label }}</label>@else<label for="{{ $id }}" class="field"><span>{{ $label }}</span>@endif
<textarea class="input" id="{{ $id }}" name="value" @if($compact) rows="2" @endif required data-editor data-restored="{{ $restored ? '1' : '0' }}" data-source="{{ $source }}" data-empty-error="{{ __('ui.empty_error') }}" data-token-error="{{ __('ui.token_error') }}" placeholder="{{ __('ui.editor_placeholder') }}">{{ $value }}</textarea>
@unless($compact)</label>@endunless
<div class="edit-meta"><span data-draft-state @unless($restored) hidden @endunless class="draft-state">{{ __('ui.unsent_draft') }}</span><span><span data-character-count>0</span> {{ __('ui.characters') }}</span></div>
@if($hint)<p class="field-hint section">{{ $hint }}</p>@endif
<p class="client-error" data-editor-error role="alert"></p>
