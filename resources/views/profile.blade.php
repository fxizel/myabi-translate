@extends('layouts.app')
@section('title', __('ui.profile'))
@section('content')
@include('partials.heading', ['heading'=>__('ui.profile'), 'description'=>$user->name.' · '.$user->organization?->name])
<div class="detail-grid"><section class="surface block"><h2>{{ __('ui.preferences') }}</h2><dl class="meta"><dt>{{ __('ui.email') }}</dt><dd>{{ $user->email }}</dd><dt>{{ __('ui.organization') }}</dt><dd>{{ $user->organization?->name }}</dd><dt>{{ __('ui.roles') }}</dt><dd>@foreach($user->roles as $role=>$languages)<span class="badge">{{ __('ui.'.$role) }} · {{ implode(', ',array_map('strtoupper',$languages)) }}</span> @endforeach</dd><dt>{{ __('ui.created_at') }}</dt><dd>{{ $user->created_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</dd><dt>{{ __('ui.updated_at') }}</dt><dd>{{ $user->updated_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</dd></dl>@include('partials.trace',['entity'=>$user])<hr class="divider"><form method="post" action="{{ route('profile.update') }}">@csrf @method('PATCH')<label class="field"><span>{{ __('ui.interface_language') }}</span><select class="input" name="locale">@foreach(['de','fr','it'] as $locale)<option value="{{ $locale }}" @selected($user->locale===$locale)>{{ __('ui.'.$locale) }}</option>@endforeach</select></label><input type="hidden" name="notifications_enabled" value="0"><label class="check-label section"><input type="checkbox" name="notifications_enabled" value="1" @checked($user->notifications_enabled)>{{ __('ui.daily_notifications') }}</label><div class="form-actions"><button class="button primary">{{ __('ui.save') }}</button></div></form></section>
<div class="form-stack">
    <section class="surface block" aria-labelledby="password-heading">
        <h2 id="password-heading">{{ __('ui.change_password') }}</h2>
        <p id="password-help" class="field-hint section">{{ __('ui.change_password_help', ['min' => config('auth.password_min_length')]) }}</p>
        <form class="section form-stack" method="post" action="{{ route('profile.password.update') }}">
            @csrf
            @method('PATCH')
            <label class="field" for="current-password">
                <span>{{ __('ui.current_password') }}</span>
                <input id="current-password" class="input" type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label class="field" for="new-password">
                <span>{{ __('ui.new_password') }}</span>
                <input id="new-password" class="input" type="password" name="password" autocomplete="new-password" minlength="{{ config('auth.password_min_length') }}" aria-describedby="password-help" required>
            </label>
            <label class="field" for="password-confirmation">
                <span>{{ __('ui.password_confirmation') }}</span>
                <input id="password-confirmation" class="input" type="password" name="password_confirmation" autocomplete="new-password" minlength="{{ config('auth.password_min_length') }}" required>
            </label>
            <div class="form-actions"><button class="button primary" type="submit">{{ __('ui.change_password') }}</button></div>
        </form>
    </section>
<section class="surface block"><h2>{{ __('ui.two_factor') }}</h2>
@if(config('fortify.mfa_enabled', false))
@if($user->needsTwoFactor())<p class="notice section">{{ __('ui.two_factor_required') }}</p>@endif
@if($user->two_factor_confirmed_at)<p class="notice success section">{{ __('ui.two_factor_enabled') }}</p>@if(!$user->needsTwoFactor())<form method="post" action="{{ route('profile.two-factor.disable') }}" data-confirm="{{ __('ui.confirm_action') }}">@csrf @method('DELETE')<label class="field"><span>{{ __('ui.password') }}</span><input class="input" type="password" name="password" required autocomplete="current-password"></label><div class="form-actions"><button class="button danger">{{ __('ui.two_factor_disable') }}</button></div></form>@endif
@elseif($qrCode)<div class="section"><p>{{ __('ui.two_factor_setup') }}</p><div class="section">{!! $qrCode !!}</div><label class="field section"><span>{{ __('ui.secret_key') }}</span><input class="input" readonly value="{{ decrypt($user->two_factor_secret) }}" autocomplete="off"></label></div><form class="section" method="post" action="{{ route('profile.two-factor.confirm') }}">@csrf<label class="field"><span>{{ __('ui.authentication_code') }}</span><input class="input" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><div class="form-actions"><button class="button primary">{{ __('ui.two_factor_confirm') }}</button></div></form>
@else<form class="section" method="post" action="{{ route('profile.two-factor.enable') }}">@csrf<label class="field"><span>{{ __('ui.password') }}</span><input class="input" type="password" name="password" required autocomplete="current-password"></label><div class="form-actions"><button class="button primary">{{ __('ui.two_factor_enable') }}</button></div></form>@endif
@if($recoveryCodes)<hr class="divider"><h3>{{ __('ui.recovery_codes') }}</h3><p class="field-hint section">{{ __('ui.recovery_help') }}</p><pre>{{ implode("\n",$recoveryCodes) }}</pre>@endif
@else
<p class="notice section">{{ __('ui.two_factor_server_disabled') }}</p>
@endif
</section></div></div>
<section class="surface block section"><h2>{{ __('ui.my_actions') }}</h2><div class="table-wrap"><table><thead><tr><th>{{ __('ui.date') }}</th><th>{{ __('ui.event') }}</th><th>{{ __('ui.entity') }}</th></tr></thead><tbody>@forelse($actions as $event)<tr><td>{{ $event->created_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</td><td>@include('partials.event-label', ['value'=>$event->action])</td><td>@include('partials.event-label', ['value'=>$event->entity_type, 'group'=>'entities']) #{{ $event->entity_id }}</td></tr>@empty<tr><td colspan="3">{{ __('ui.no_history') }}</td></tr>@endforelse</tbody></table></div>@include('partials.pagination',['items'=>$actions])</section>
@endsection
