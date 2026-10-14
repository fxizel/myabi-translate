<th scope="col" aria-sort="none" @if(isset($columnName)) data-column="{{ $columnName }}" @endif class="{{ $cellClass ?? '' }}">
    <button type="button" class="table-sort" data-sort-column="{{ $columnIndex }}" title="{{ __('tables.sort_asc') }}" disabled>{{ $label }} @if(isset($suffix))<small>{{ $suffix }}</small>@endif <span data-sort-indicator aria-hidden="true">↕</span></button>
</th>
