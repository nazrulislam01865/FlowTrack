<div class="ft-progressive-card-list" role="status" aria-live="polite" aria-busy="true">
    @for($card = 0; $card < max(2, (int) $cards); $card++)
        <section>
            <span></span><span></span><span></span>
            <div><i></i><i></i></div>
        </section>
    @endfor
</div>
