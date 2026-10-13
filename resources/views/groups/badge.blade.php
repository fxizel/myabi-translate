@if($group)
<a class="group-badge group-color-{{ array_search($group->color, \App\Http\Controllers\GroupController::COLORS, true) ?: 0 }}" href="{{ route('groups.show', $group) }}">{{ $group->title }}@if($group->archived_at) <small>· {{ __('campaigns.archived') }}</small>@endif</a>
@else<span class="empty-value">{{ __('campaigns.no_group') }}</span>@endif
