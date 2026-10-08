@extends('layouts.app')
@section('title', __('ui.password_confirmation'))
@section('content')
<div class="auth-panel"><p class="eyebrow">{{ __('ui.security') }}</p><h1>{{ __('ui.password_confirmation') }}</h1><section class="surface section"><form method="post" action="{{ route('password.confirm.store') }}">@csrf<label class="field"><span>{{ __('ui.password') }}</span><input class="input" type="password" name="password" required autofocus autocomplete="current-password"></label><button class="button primary">{{ __('ui.verify') }}</button></form></section></div>
@endsection
