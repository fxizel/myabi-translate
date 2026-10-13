@extends('layouts.app')
@section('title', __('campaigns.groups'))
@section('content')
<div class="page-heading"><div><h1>{{ __('campaigns.groups') }}</h1><p class="lede">{{ __('campaigns.groups_description') }}</p></div>@if($canManage ?? false)<a href="{{ route('groups.create') }}" class="button primary">{{ __('campaigns.new_group') }}</a>@endif</div>
<form class="toolbar" method="get"><label class="field search"><span>{{ __('ui.search') }}</span><input class="input" type="search" name="q" maxlength="200" value="{{ request('q') }}"></label><label class="check-label"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))>{{ __('campaigns.archives') }}</label><button class="button">{{ __('ui.filter') }}</button></form>
<div class="table-wrap"><table><thead><tr><th>{{ __('campaigns.title') }}</th><th>{{ __('campaigns.description') }}</th><th>{{ __('campaigns.term_count') }}</th></tr></thead><tbody>@forelse($groups as $group)<tr><td>@include('groups.badge', compact('group'))</td><td class="preserve">{{ $group->description ?: '—' }}</td><td>{{ number_format($group->terms_count, 0, '.', '’') }}</td></tr>@empty<tr><td colspan="3" class="empty-state">{{ __('campaigns.no_groups') }}</td></tr>@endforelse</tbody></table></div>
@include('partials.pagination', ['items' => $groups])
@endsection
