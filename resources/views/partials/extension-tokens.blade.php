@php($extensionTokens = $user->tokens()->latest()->paginate(10, ['id', 'name', 'expires_at', 'last_used_at', 'revoked_at', 'session_version', 'mfa_authenticated', 'mfa_confirmed_at', 'mfa_fingerprint'], 'tokens_page'))
<section class="surface block section" aria-labelledby="extension-tokens-heading">
    <h2 id="extension-tokens-heading">{{ __('extension.title') }}</h2>
    <p class="field-hint section">{{ __('extension.help') }}</p>
    <form class="section form-stack" method="post" action="{{ route('profile.extension-tokens.store') }}">
        @csrf
        <label class="field"><span>{{ __('extension.name') }}</span><input class="input" name="name" maxlength="100" required autocomplete="off"></label>
        <label class="field"><span>{{ __('extension.days') }}</span><input class="input" type="number" name="expires_in_days" min="1" max="{{ config('browser-extension.token_max_days') }}" value="{{ config('browser-extension.token_max_days') }}" required></label>
        @if(config('browser-extension.feedback_enabled'))<label class="check-label"><input type="checkbox" name="feedback" value="1">{{ __('extension.feedback') }}</label>@endif
        <div class="form-actions"><button class="button primary" type="submit">{{ __('extension.create') }}</button></div>
    </form>
    <div class="table-wrap section"><table><thead><tr><th>{{ __('extension.name') }}</th><th>{{ __('extension.expires') }}</th><th>{{ __('extension.last_used') }}</th><th>{{ __('extension.state') }}</th><th>{{ __('extension.action') }}</th></tr></thead><tbody>
    @forelse($extensionTokens as $extensionToken)
        <tr><td>{{ $extensionToken->name }}</td><td>{{ $extensionToken->expires_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $extensionToken->last_used_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') ?? '—' }}</td><td>{{ __('extension.'.($extensionToken->revoked_at || $extensionToken->expires_at?->isPast() || $extensionToken->session_version !== $user->session_version || !$extensionToken->hasCurrentMfaProof($user) ? 'inactive' : 'active')) }}</td><td>@if(!$extensionToken->revoked_at)<form method="post" action="{{ route('profile.extension-tokens.destroy', $extensionToken) }}">@csrf @method('DELETE')<button class="button danger" type="submit">{{ __('extension.revoke') }}</button></form>@endif</td></tr>
    @empty<tr><td colspan="5">{{ __('extension.none') }}</td></tr>@endforelse
    </tbody></table></div>
    @include('partials.pagination', ['items' => $extensionTokens])
</section>
