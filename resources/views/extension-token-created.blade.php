@extends('layouts.app')
@section('title', __('extension.title'))
@section('content')
@include('partials.heading', ['heading' => __('extension.created')])
<section class="surface block">
    <p class="notice section">{{ __('extension.copy_once') }}</p>
    <label class="field section"><span>{{ __('extension.token') }}</span><textarea class="input" readonly autocomplete="off" rows="3">{{ $plainTextToken }}</textarea></label>
    <div class="form-actions"><a class="button primary" href="{{ route('profile.edit') }}">{{ __('extension.back_profile') }}</a></div>
</section>
@endsection
