@if($proposal->screenshot_path)
<a class="screenshot-link" href="{{ route('proposals.screenshot', ['proposal' => $proposal->id, 'version' => $proposal->lock_version]) }}" data-screenshot-open aria-label="{{ __('screenshots.open', ['id' => $proposal->id]) }}"><img loading="lazy" decoding="async" width="160" height="120" src="{{ route('proposals.screenshot.thumbnail', ['proposal' => $proposal->id, 'version' => $proposal->lock_version]) }}" alt="{{ __('screenshots.open', ['id' => $proposal->id]) }}"></a>
@endif
