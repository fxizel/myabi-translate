@extends('layouts.app')
@section('title', __('ui.help'))
@section('content')
@include('partials.heading',['heading'=>__('ui.help_title'),'description'=>__('ui.help_description'),'eyebrow'=>'ARGE-ABI / myABI'])
<div class="content-narrow stack-gap">@foreach(['import'=>'imports','translate'=>'translations','validate'=>'validation','publish'=>'publications'] as $step=>$label)<section class="surface block"><p class="eyebrow">0{{ $loop->iteration }}</p><h2>{{ __('ui.'.$label) }}</h2><p class="lede">{{ __('ui.help_'.$step) }}</p></section>@endforeach<div class="notice section"><p>{{ __('ui.proposal_hint') }}</p><p>{{ __('ui.four_eyes') }}</p><p>{{ __('ui.immutable_help') }}</p></div></div>
@endsection
