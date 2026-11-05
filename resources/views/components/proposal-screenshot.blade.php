@props(['id', 'proposal' => null, 'copied' => false])
<fieldset class="screenshot-editor section" data-screenshot-editor data-error-invalid="{{ __('screenshots.invalid') }}" data-error-size="{{ __('screenshots.too_large') }}" data-error-multiple="{{ __('screenshots.multiple') }}" data-error-network="{{ __('screenshots.network_failed') }}" data-saved="{{ __('screenshots.saved') }}">
    <legend>{{ __('screenshots.optional') }}</legend>
    <p class="field-hint" id="{{ $id }}-hint">{{ __('screenshots.hint') }} {{ __('screenshots.sensitive') }}</p>
    <label class="field section" for="{{ $id }}-action"><span>{{ __('screenshots.title') }}</span><select name="screenshot_action" class="input" id="{{ $id }}-action" data-screenshot-action><option value="keep">{{ __('screenshots.keep') }}</option><option value="replace">{{ __('screenshots.replace') }}</option><option value="remove">{{ __('screenshots.remove') }}</option></select></label>
    <label class="field section" for="{{ $id }}-file"><span>{{ __('screenshots.choose') }}</span><input class="input" id="{{ $id }}-file" name="screenshot" type="file" accept="image/png,image/jpeg" aria-describedby="{{ $id }}-hint" data-screenshot-file></label>
    <div class="screenshot-paste section" tabindex="0" role="group" aria-label="{{ __('screenshots.paste') }}" data-screenshot-paste>{{ __('screenshots.paste') }}</div>
    @if($proposal?->screenshot_path)
        <div data-screenshot-existing class="section">@include('partials.proposal-screenshot', ['proposal' => $proposal])</div>
        @if($copied)<p class="field-hint">{{ __('screenshots.copied') }}</p>@endif
    @endif
    <img class="screenshot-preview section" data-screenshot-preview hidden alt="{{ __('screenshots.preview') }}">
    <p class="client-error" data-screenshot-error role="alert" tabindex="-1"></p>
    <p class="field-hint">{{ __('screenshots.reselect') }}</p>
    <p data-proposal-result role="status" aria-live="polite"></p>
</fieldset>
