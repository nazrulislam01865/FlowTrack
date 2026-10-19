@php
    $placeholderColumns = min(6, max(3, (int) $columns));
@endphp

<div class="ft-progressive-board-placeholder" style="--ft-placeholder-columns: {{ $placeholderColumns }}" role="status" aria-live="polite" aria-busy="true">
    @for($column = 0; $column < $placeholderColumns; $column++)
        <section>
            <div class="ft-progressive-placeholder-title"></div>
            @for($card = 0; $card < 3; $card++)
                <div class="ft-progressive-placeholder-card">
                    <span></span><span></span><span></span>
                </div>
            @endfor
        </section>
    @endfor
</div>
