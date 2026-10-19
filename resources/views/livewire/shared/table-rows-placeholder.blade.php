@php
    $placeholderColumns = max(2, (int) $columns);
    $placeholderRows = max(3, (int) $rows);
@endphp

<div class="ft-progressive-table-placeholder" role="status" aria-live="polite" aria-busy="true">
    @for($row = 0; $row < $placeholderRows; $row++)
        <div style="--ft-placeholder-columns: {{ $placeholderColumns }}">
            @for($column = 0; $column < $placeholderColumns; $column++)
                <span></span>
            @endfor
        </div>
    @endfor
</div>
