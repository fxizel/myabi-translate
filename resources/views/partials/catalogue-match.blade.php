@if($match && $match['origin'] === $matchOrigin)
@php($matchVisible = $match['attribute'] === $term->display_attribute && ($matchOrigin !== 'proposal' || (int) $match['proposal_id'] === (int) $term->pending_id))
<div class="catalogue-match @unless($matchVisible) catalogue-match-excerpt @endunless">
    @unless($matchVisible)
    <span class="cell-caption">{{ \Illuminate\Support\Facades\Lang::has('catalogue.attributes.'.$match['attribute']) ? __('catalogue.attributes.'.$match['attribute']) : $match['attribute'] }} · {{ strtoupper($match['language']) }}</span>
    <span class="catalogue-match-text">{{ app(\App\Services\CatalogueSearch\Profile::class)->highlight($match['text'], $match['excerpt_ranges']) }}</span>
    @endunless
    <span class="catalogue-match-origin">@if($match['approximate'])<span class="catalogue-match-badge">≈ {{ __('search.badge') }}</span> @endif{{ __('search.'.$matchOrigin, ['language' => strtoupper($match['language'])]) }}</span>
    @unless($matchVisible)
    <a class="text-button" href="{{ url('terms/'.$term->id) }}?{{ http_build_query(['language' => $language, ...($match['proposal_id'] ? ['match_proposal' => $match['proposal_id']] : [])]) }}#{{ $match['proposal_id'] ? 'proposal-'.$match['proposal_id'] : 'translation-'.array_search($match['attribute'], array_keys($display), true) }}">{{ __('search.see_match') }} →</a>
    @endunless
</div>
@endif
