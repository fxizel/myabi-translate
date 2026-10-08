@php
    $labelKey = 'audit.'.($group ?? 'events').'.'.$value;
    $label = \Illuminate\Support\Facades\Lang::hasForLocale($labelKey) ? __($labelKey) : $value;
@endphp
{{ is_string($label) ? $label : $value }}
