@props(['column', 'label', 'defaultDir' => 'asc'])
@php
    $currentSort = request('sort');
    $currentDir = strtolower((string) request('dir')) === 'desc' ? 'desc' : 'asc';
    $active = $currentSort === $column;
    // when active, clicking flips the direction; when inactive, start at the column's default
    $nextDir = $active ? ($currentDir === 'asc' ? 'desc' : 'asc') : $defaultDir;
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
@endphp
<th @class(['sortable-th--active' => $active])>
    <a href="{{ $url }}" class="sortable-th">
        {{ $label }}
        @if ($active)
            <i class="fa fa-caret-{{ $currentDir === 'asc' ? 'up' : 'down' }}"></i>
        @endif
    </a>
</th>
