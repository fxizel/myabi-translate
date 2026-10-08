@if(count($filteredOperations ?? []))
<section class="surface block section" aria-labelledby="filtered-operations-title">
    <h2 id="filtered-operations-title">{{ __('ui.filtered_operations') }}</h2>
    @foreach($filteredOperations as $operation)
    @php($examined = $operation->counts['examined'] ?? 0)
    @php($total = $operation->preview['total'] ?? 0)
    <details class="section" @if($loop->first) open @endif @if(in_array($operation->status, ['queued', 'processing'])) data-refresh-progress @endif>
        <summary>
            <strong>#{{ $operation->id }} · {{ __('ui.'.$operation->language) }}</strong> @include('partials.status', ['status'=>$operation->status])
            <span class="cell-caption">{{ __('ui.filtered_progress_count', ['done'=>number_format($examined, 0, '.', '’'), 'total'=>number_format($total, 0, '.', '’')]) }} · {{ __('ui.filtered_result_count', ['validated'=>number_format($operation->counts['validated'] ?? 0, 0, '.', '’'), 'excluded'=>number_format($operation->counts['excluded'] ?? 0, 0, '.', '’')]) }}</span>
        </summary>
        <progress class="section" aria-label="{{ __('ui.progress') }}" value="{{ $examined }}" max="{{ max(1, $total) }}"></progress>
        @if($operation->status === 'queued')<p class="field-hint">{{ __('ui.filtered_waiting') }}</p>@endif
        @if($operation->status === 'processing')<p class="field-hint">{{ __('ui.processing_help') }}</p>@endif
        @if($operation->error)<p class="notice error section">{{ \App\Services\LocalizedMessage::display($operation->error) }}</p>@endif
        @if($operation->status === 'failed' && $operation->error !== 'ui.filtered_stale')
        <form method="post" action="{{ route('proposals.filtered-retry', ['operation'=>$operation]) }}" data-confirm="{{ __('ui.filtered_retry_confirm') }}">
            @csrf<input type="hidden" name="confirmed" value="1"><button class="button" type="submit">{{ __('ui.initial_resume') }}</button>
        </form>
        @endif
        <details class="section">
            <summary>{{ __('ui.initial_excluded') }}</summary>
            <dl class="meta">
                @foreach(['invalid', 'self', 'competing'] as $reason)
                <dt>{{ __('ui.filtered_exclusion_'.$reason) }}</dt><dd>{{ number_format($operation->counts['reasons'][$reason] ?? 0, 0, '.', '’') }}</dd>
                @endforeach
            </dl>
        </details>
        @include('partials.trace', ['entity'=>$operation])
    </details>
    @endforeach
</section>
@endif
