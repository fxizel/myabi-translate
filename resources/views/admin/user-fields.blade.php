@php
    $formId = $record ? 'update-user-'.$record->id : 'create-user';
    $restoreInput = old('_admin_form') === $formId;
    $selectedRoles = $restoreInput ? old('roles', []) : ($record?->roles ?? ['reader' => ['de', 'fr', 'it', 'en']]);
@endphp
<div class="form-grid">
    <label class="field"><span>{{ __('ui.first_name') }}</span><input class="input" name="first_name" value="{{ $restoreInput ? old('first_name') : ($record?->first_name ?? '') }}" required maxlength="120" autocomplete="given-name"></label>
    <label class="field"><span>{{ __('ui.last_name') }}</span><input class="input" name="last_name" value="{{ $restoreInput ? old('last_name') : ($record?->last_name ?? '') }}" required maxlength="120" autocomplete="family-name"></label>
    <label class="field"><span>{{ __('ui.email') }}</span><input class="input" type="email" name="email" value="{{ $restoreInput ? old('email') : ($record?->email ?? '') }}" required maxlength="255"></label>
    <label class="field"><span>{{ __('ui.organization') }}</span><select class="input" name="organization_id" required>
        @foreach($organisations->where('active', true) as $organization)
            <option value="{{ $organization->id }}" @selected(($restoreInput ? old('organization_id') : $record?->organization_id) == $organization->id)>{{ $organization->name }}</option>
        @endforeach
    </select></label>
    <label class="field"><span>{{ __('ui.interface_language') }}</span><select class="input" name="locale">
        @foreach(['de', 'fr', 'it'] as $locale)
            <option value="{{ $locale }}" @selected(($restoreInput ? old('locale', 'de') : ($record?->locale ?? 'de')) === $locale)>{{ __('ui.'.$locale) }}</option>
        @endforeach
    </select></label>
    <div class="field"><span>{{ __('ui.user_access') }}</span><input type="hidden" name="active" value="0"><label class="check-label"><input type="checkbox" name="active" value="1" @checked($restoreInput ? old('active') : ($record?->active ?? true))>{{ __('ui.active') }}</label></div>
</div>
<div class="table-wrap section"><table class="mini-table">
    <caption>{{ __('ui.roles') }} · {{ __('ui.permissions_languages') }}</caption>
    <thead><tr><th>{{ __('ui.roles') }}</th>@foreach(['de', 'fr', 'it', 'en'] as $lang)<th>{{ __('ui.'.$lang) }}</th>@endforeach</tr></thead>
    <tbody>@foreach($roles as $role)<tr><th>{{ __('ui.'.$role) }}</th>@foreach(['de', 'fr', 'it', 'en'] as $lang)<td><input type="checkbox" name="roles[{{ $role }}][]" value="{{ $lang }}" @checked(in_array($lang, (array) ($selectedRoles[$role] ?? []), true)) aria-label="{{ __('ui.'.$role) }} · {{ __('ui.'.$lang) }}"></td>@endforeach</tr>@endforeach</tbody>
</table></div>
