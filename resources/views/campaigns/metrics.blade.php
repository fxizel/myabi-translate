@php($metrics = $metrics ?? $campaign->metrics())
@if($metrics['total']===0)<p class="empty-value">{{ __('campaigns.no_terms') }}</p>
@else
<div class="campaign-progress"><label>{{ __('campaigns.work_progress') }} <strong>{{ number_format($metrics['work_progress'],1,',','') }} %</strong><progress max="100" value="{{ $metrics['work_progress'] }}">{{ $metrics['work_progress'] }} %</progress></label><label>{{ __('campaigns.confirmed_progress') }} <strong>{{ number_format($metrics['confirmed_progress'],1,',','') }} %</strong><progress max="100" value="{{ $metrics['confirmed_progress'] }}">{{ $metrics['confirmed_progress'] }} %</progress></label></div>
@endif
<p class="campaign-counts">{{ $metrics['total'] }} {{ __('campaigns.terms') }}@foreach(['to_process','to_arbitrate','proposal_sent','confirmed'] as $status)<span class="badge badge-{{ ['to_process'=>'todo','to_arbitrate'=>'review','proposal_sent'=>'pending','confirmed'=>'validated'][$status] }}">{{ $metrics[$status] }} {{ __('campaigns.treatment.'.$status) }}</span>@endforeach</p>
