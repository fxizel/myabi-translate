@extends('layouts.app')
@section('title', __('ui.two_factor'))
@section('content')
<div class="auth-panel"><p class="eyebrow">ARGE-ABI / myABI</p><h1>{{ __('ui.two_factor') }}</h1><p class="lede">{{ __('ui.two_factor_description') }}</p><section class="surface"><form method="post" action="{{ route('two-factor.login.store') }}">@csrf<label class="field"><span>{{ __('ui.authentication_code') }}</span><input class="input" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus></label><details><summary>{{ __('ui.recovery_code') }}</summary><label class="field section"><span>{{ __('ui.recovery_code') }}</span><input class="input" name="recovery_code" autocomplete="off"></label></details><div class="form-actions"><button class="button primary">{{ __('ui.verify') }} →</button></div></form></section></div>
@endsection
