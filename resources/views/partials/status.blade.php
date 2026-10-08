@php($statusKey = strtolower(str_replace([' ', '-'], '_', $status ?? 'missing')))
<span class="badge badge-{{ preg_replace('/[^a-z_]/', '', $statusKey) }}">{{ __('ui.status_'.$statusKey) === 'ui.status_'.$statusKey ? $status : __('ui.status_'.$statusKey) }}</span>
