@extends('layouts.app')
@section('title', __('ui.administration'))
@section('content')
@include('partials.heading',['heading'=>__('ui.administration'),'description'=>__('ui.users').' · '.__('ui.organizations').' · '.__('ui.roles')])
<section class="surface block">
    <details @if(old('_admin_form') === 'create-user') open @endif>
        <summary>{{ __('ui.admin_create_user') }} +</summary>
        <form method="post" action="{{ route('admin.users.store') }}" class="section">
            @csrf
            <input type="hidden" name="_admin_form" value="create-user">
            @include('admin.user-fields', ['record' => null])
            <hr class="divider">
            <label class="check-label" for="activate-without-email">
                <input id="activate-without-email" type="checkbox" name="activate_without_email" value="1" aria-describedby="create-activation-help" @checked(old('_admin_form') === 'create-user' && old('activate_without_email'))>
                {{ __('ui.activate_without_email') }}
            </label>
            <p id="create-activation-help" class="field-hint section">{{ __('ui.create_manual_activation_help', ['min' => config('auth.password_min_length')]) }}</p>
            @include('admin.password-fields', ['passwordFormId' => 'create-user', 'passwordRequired' => false, 'passwordHelpId' => 'create-activation-help'])
            <div class="form-actions"><button class="button primary">{{ __('ui.admin_create_user') }}</button></div>
        </form>
    </details>
</section>
<section class="section"><h2>{{ __('ui.users') }}</h2><form method="get" class="toolbar"><label class="field search"><span class="sr-only">{{ __('ui.search') }}</span><input class="input" name="q" value="{{ request('q') }}" placeholder="{{ __('ui.name') }} / {{ __('ui.email') }}"></label><label class="field filter"><span class="sr-only">{{ __('ui.organization') }}</span><select class="input" name="organization_id"><option value="">{{ __('ui.all_organizations') }}</option>@foreach($organisations as $organization)<option value="{{ $organization->id }}" @selected(request('organization_id')==$organization->id)>{{ $organization->name }}</option>@endforeach</select></label><button class="button primary">{{ __('ui.filter') }}</button></form>
@foreach($users as $record)
    <details class="surface block section" @if(in_array(old('_admin_form'), ['update-user-'.$record->id, 'activate-user-'.$record->id], true)) open @endif>
        <summary>
            {{ $record->name }} <span class="muted">· {{ $record->organization?->code }} · {{ $record->email }}</span>
            @include('partials.status', ['status' => $record->active ? 'active' : 'inactive'])
            @if($record->invitation_token !== null && $record->invitation_accepted_at === null)<span class="badge badge-pending">{{ __('ui.invitation_pending') }}</span>@endif
        </summary>
        <form method="post" action="{{ route('admin.users.update', $record) }}" class="section">
            @csrf
            @method('PATCH')
            <input type="hidden" name="_admin_form" value="update-user-{{ $record->id }}">
            @include('admin.user-fields')
            <div class="form-actions"><button class="button primary">{{ __('ui.save') }}</button></div>
        </form>
        <hr class="divider">
        <details @if(old('_admin_form') === 'activate-user-'.$record->id) open @endif>
            <summary>{{ __('ui.activate_with_password') }}</summary>
            @if($record->organization_id === null || $record->organization?->active)
                <p id="activate-user-{{ $record->id }}-help" class="field-hint section">{{ __('ui.manual_activation_help', ['min' => config('auth.password_min_length')]) }}</p>
                <form method="post" action="{{ route('admin.users.activate', $record) }}" class="section" data-confirm="{{ __('ui.manual_activation_confirm') }}">
                    @csrf
                    <input type="hidden" name="_admin_form" value="activate-user-{{ $record->id }}">
                    @include('admin.password-fields', ['passwordFormId' => 'activate-user-'.$record->id, 'passwordRequired' => true, 'passwordHelpId' => 'activate-user-'.$record->id.'-help'])
                    <div class="form-actions"><button class="button primary">{{ __('ui.activate_with_password') }}</button></div>
                </form>
            @else
                <p class="notice section">{{ __('ui.manual_activation_unavailable') }}</p>
            @endif
        </details>
        <hr class="divider">
        <div class="row">
            @foreach(['revoke' => 'revoke_sessions', 'unlock' => 'unlock', 'invite' => 'resend_invitation'] as $action => $label)
                @if($action !== 'invite' || !$record->invitation_accepted_at)
                    <form method="post" action="{{ route('admin.users.'.$action, $record) }}" data-confirm="{{ __('ui.confirm_action') }}">@csrf<button class="button small">{{ __('ui.'.$label) }}</button></form>
                @endif
            @endforeach
        </div>
        <dl class="meta"><dt>{{ __('ui.created_at') }}</dt><dd>{{ $record->created_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</dd><dt>{{ __('ui.created_by') }}</dt><dd>{{ $record->creator?->name ?? '—' }}</dd><dt>{{ __('ui.updated_at') }}</dt><dd>{{ $record->updated_at?->copy()->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</dd><dt>{{ __('ui.updated_by') }}</dt><dd>{{ $record->updater?->name ?? '—' }}</dd></dl>
    </details>
@endforeach
@include('partials.pagination',['items'=>$users])</section>
<section class="surface block section"><h2>{{ __('ui.organizations') }}</h2><div class="stack-gap section">@foreach($organisations as $organization)<details><summary>{{ $organization->code }} · {{ $organization->name }}</summary><form class="section" method="post" action="{{ route('admin.organisations.update',$organization) }}">@csrf @method('PATCH')<div class="form-grid"><label class="field"><span>{{ __('ui.organization_code') }}</span><input class="input" name="code" value="{{ $organization->code }}" maxlength="40" required></label><label class="field"><span>{{ __('ui.name') }}</span><input class="input" name="name" value="{{ $organization->name }}" maxlength="255" required></label></div><input type="hidden" name="active" value="0"><label class="check-label section"><input type="checkbox" name="active" value="1" @checked($organization->active)>{{ __('ui.active') }}</label><div class="form-actions"><button class="button">{{ __('ui.save') }}</button></div></form>@include('partials.trace',['entity'=>$organization])</details>@endforeach</div><hr class="divider"><details><summary>{{ __('ui.create_organization') }} +</summary><form class="section" method="post" action="{{ route('admin.organisations.store') }}">@csrf<input type="hidden" name="active" value="1"><div class="form-grid"><label class="field"><span>{{ __('ui.organization_code') }}</span><input class="input" name="code" maxlength="40" required></label><label class="field"><span>{{ __('ui.name') }}</span><input class="input" name="name" maxlength="255" required></label></div><div class="form-actions"><button class="button primary">{{ __('ui.create_organization') }}</button></div></form></details></section>
@endsection
