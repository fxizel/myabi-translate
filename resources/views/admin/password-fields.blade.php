<div class="form-grid section">
    <label class="field" for="{{ $passwordFormId }}-password">
        <span>{{ __('ui.new_password') }}</span>
        <input id="{{ $passwordFormId }}-password" class="input" type="password" name="password" autocomplete="new-password" minlength="{{ config('auth.password_min_length') }}" aria-describedby="{{ $passwordHelpId }}" @required($passwordRequired)>
    </label>
    <label class="field" for="{{ $passwordFormId }}-password-confirmation">
        <span>{{ __('ui.password_confirmation') }}</span>
        <input id="{{ $passwordFormId }}-password-confirmation" class="input" type="password" name="password_confirmation" autocomplete="new-password" minlength="{{ config('auth.password_min_length') }}" aria-describedby="{{ $passwordHelpId }}" @required($passwordRequired)>
    </label>
</div>
