@extends('layouts.app')
@section('title', __('ui.forgot_password'))
@section('content')
<div class="auth-panel"><p class="eyebrow">ARGE-ABI / myABI</p><h1>{{ __('ui.forgot_password') }}</h1><p class="lede">{{ __('ui.forgot_description') }}</p><section class="surface"><form method="post" action="{{ route('password.email') }}">@csrf<label class="field"><span>{{ __('ui.email') }}</span><input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label><button class="button primary">{{ __('ui.send_reset') }} →</button></form><p class="section"><a href="{{ route('login') }}">← {{ __('ui.login') }}</a></p></section></div>
@endsection
