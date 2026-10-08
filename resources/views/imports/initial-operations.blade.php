<section class="surface block section"><h2>{{ __('ui.initial_operations') }}</h2>
@foreach($initialOperations ?? [] as $operation)
<article class="section" @if(in_array($operation->status,['queued','processing'])) data-refresh-progress @endif>
    <div class="section-heading"><h3>#{{ $operation->id }} · {{ __('ui.'.$operation->language) }} · {{ $operation->type==='initial_preview' ? __('ui.initial_preview') : __('ui.initial_validation') }}</h3>@include('partials.status',['status'=>$operation->status])</div>
    @if($operation->type==='initial_preview')
        <p>{{ __('ui.initial_preview_count',['count'=>number_format($operation->counts['examined'],0,'.','’')]) }} · {{ number_format($operation->counts['excluded'],0,'.','’') }} {{ __('ui.initial_excluded') }}</p>
        @if(in_array($operation->status,['queued','processing']))<progress aria-label="{{ __('ui.progress') }}"></progress>@endif
        @if($operation->status==='ready' && $operation->user_id===auth()->id())<div class="form-actions"><a class="button primary" href="{{ route('imports.validation-preview',['import'=>$import,'operation_id'=>$operation->id,'language'=>$operation->language]) }}">{{ __('ui.initial_view_preview') }} →</a></div>@endif
    @else
        <p>{{ __('ui.initial_progress_count',['done'=>number_format($operation->counts['validated'],0,'.','’'),'total'=>number_format($operation->preview['counts']['eligible'],0,'.','’')]) }} · {{ number_format($operation->counts['excluded'],0,'.','’') }} {{ __('ui.initial_excluded') }}</p>
        <progress aria-label="{{ __('ui.progress') }}" value="{{ $operation->counts['validated'] }}" max="{{ max(1,$operation->preview['counts']['eligible']) }}"></progress>
    @endif
    @if($operation->error)<p class="notice error section">{{ \App\Services\LocalizedMessage::display($operation->error) }}</p><form method="post" action="{{ route('imports.validation-retry',['import'=>$import,'operation'=>$operation]) }}" data-confirm="{{ __('ui.confirm_action') }}">@csrf<input type="hidden" name="confirmed" value="1"><button class="button">{{ __('ui.initial_resume') }}</button></form>@endif
    <details class="section"><summary>{{ __('ui.initial_excluded') }}</summary><dl class="meta">@foreach($operation->preview['counts']['reasons'] as $reason=>$count)<dt>{{ __('ui.initial_exclusion_'.$reason) }}</dt><dd>{{ number_format($count,0,'.','’') }}</dd>@endforeach</dl></details>
    @include('partials.trace',['entity'=>$operation])<hr class="divider">
</article>
@endforeach
@if(isset($initialOperations))@include('partials.pagination',['items'=>$initialOperations])@endif
</section>
