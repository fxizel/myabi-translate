@php
    $current = data_get($term->validated, $attribute.'.'.$language);
    $translationStatus = match (true) {
        (bool) data_get($term->review_needed, $attribute.'.'.$language) => 'review',
        $current !== null => 'validated',
        $openAttributes->get($attribute) === 'pending' => 'pending',
        default => 'missing',
    };
    $restoredInput = $draftAttribute === $attribute;
    $restoredValue = $restoredInput ? old('value', '') : data_get($restoreRevision ?? null, 'validated.'.$attribute.'.'.$language, '');
    $placeholderCounts = \App\Domain\Devconf\TranslationValidator::placeholders($values['reference'] ?? '');
@endphp
<section class="surface block" id="translation-{{ $index }}">
    <div class="panel-label">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$attribute) ? __('catalogue.attributes.'.$attribute) : $attribute }} <span>{{ $language }}</span></div>
    <p class="source-text">{{ $values['reference'] ?? '' }}</p>
    <hr class="divider">
    <div class="panel-label">{{ __('ui.current_value') }} @include('partials.status', ['status' => $translationStatus])</div>
    <p class="translation-text">{{ $current ?? __('ui.no_translation') }}</p>
    @if($openAttributes->get($attribute) === 'arbitration')
        <div class="panel-label section">{{ __('ui.proposal') }} @include('partials.status', ['status' => 'arbitration'])</div>
    @endif

    @if($translationStatus === 'review' && auth()->user()->canValidate($language))
        <form action="{{ url('terms/'.$term->id.'/confirm') }}" method="post" class="section">
            @csrf
            <input type="hidden" name="term_id" value="{{ $term->id }}">
            <input type="hidden" name="attribute" value="{{ $attribute }}">
            <input type="hidden" name="language" value="{{ $language }}">
            <input type="hidden" name="lock_version" value="{{ $term->lock_version ?? 1 }}">
            <button class="button">{{ __('ui.confirm_value') }}</button>
        </form>
    @endif

    @if($placeholderCounts)
        <hr class="divider">
        <div class="panel-label">{{ __('ui.tokens') }}</div>
        <div class="tokens">
            @foreach($placeholderCounts as $token => $count)
                <span class="token">{{ $token }} ×{{ $count }}</span>
            @endforeach
        </div>
    @endif

    @if(auth()->user()->canTranslate($language) && !$term->obsolete)
        <hr class="divider">
        <form action="{{ url('terms/'.$term->id.'/proposals') }}" method="post" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="term_id" value="{{ $term->id }}">
            <input type="hidden" name="attribute" value="{{ $attribute }}">
            <input type="hidden" name="language" value="{{ $language }}">
            <input type="hidden" name="lock_version" value="{{ $term->lock_version ?? 1 }}">
            @if($restoreRevision ?? null)
                <input type="hidden" name="restored_revision_id" value="{{ $restoreRevision->id }}">
            @endif
            <x-proposal-editor :id="'new-proposal-'.$index" :label="__('ui.new_proposal')" :value="$restoredValue" :restored="$restoredInput || (($restoreRevision ?? null) && $restoredValue !== '')" :source="$values['reference'] ?? ''" :hint="__('ui.proposal_hint').' '.__('ui.keyboard_hint')" />
            <x-proposal-screenshot :id="'new-image-'.$index" />
            <div class="form-actions"><button class="button primary">{{ __('ui.propose') }} →</button></div>
        </form>
    @endif

    <details class="section">
        <summary>{{ __('ui.imported_values') }}</summary>
        <div class="table-wrap">
            <table class="mini-table"><tbody>
                @foreach($values['translations'] ?? [] as $lang => $value)
                    <tr><th>{{ $lang }}</th><td class="preserve">{{ $value }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    </details>
</section>
