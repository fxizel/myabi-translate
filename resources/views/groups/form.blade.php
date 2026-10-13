@extends('layouts.app')
@section('title', __($group->exists ? 'campaigns.edit_group' : 'campaigns.new_group'))
@section('content')
@include('partials.heading', ['heading' => __($group->exists ? 'campaigns.edit_group' : 'campaigns.new_group')])
<form class="surface block form-stack content-narrow" method="post" action="{{ $group->exists ? route('groups.update', $group) : route('groups.store') }}">@csrf @if($group->exists) @method('PUT') @endif
<label class="field"><span>{{ __('campaigns.title') }} *</span><input class="input" name="title" required maxlength="200" value="{{ old('title', $group->title) }}"></label>
<label class="field"><span>{{ __('campaigns.description') }}</span><textarea class="input" name="description" maxlength="10000">{{ old('description', $group->description) }}</textarea></label>
<fieldset class="group-palette"><legend>{{ __('campaigns.color') }} *</legend><div class="check-group">@foreach($colors as $color)<label class="check-label"><input type="radio" name="color" value="{{ $color }}" required @checked(old('color', $group->color)===$color)><span class="group-badge group-color-{{ $loop->index }}">{{ __('campaigns.colors.'.$loop->index) }}</span></label>@endforeach</div></fieldset>
<div class="form-actions"><a class="button quiet" href="{{ $group->exists ? route('groups.show', $group) : route('groups.index') }}">{{ __('ui.cancel') }}</a><button class="button primary">{{ __('ui.save') }}</button></div>
</form>
@endsection
