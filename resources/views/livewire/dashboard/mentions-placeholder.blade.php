<section class="ft-mgmt-panel ft-mgmt-mentions-panel ft-mgmt-dashboard-skeleton" id="mentions-for-you" data-dashboard-skeleton-for="tagged-comments" role="status" aria-busy="true" aria-label="Loading Mentions for you">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-tagged-comments-content">
    <div class="ft-mgmt-mentions-head" wire:intersect.once="loadDashboardPanel">
        <div class="ft-mgmt-mentions-heading"><div class="ft-mgmt-mentions-title-line"><h2>Mentions for you</h2></div></div>
        <div class="ft-mgmt-skeleton-line ft-mgmt-skeleton-mention-action" aria-hidden="true"></div>
    </div>
    <div class="ft-mgmt-skeleton-mention-tabs" aria-hidden="true">
        @for($i = 0; $i < 4; $i++)<span class="ft-mgmt-skeleton-line"></span>@endfor
    </div>
    <div class="ft-mgmt-skeleton-body ft-mgmt-skeleton-mention-list" aria-hidden="true">
        @for($i = 0; $i < 4; $i++)
            <div class="ft-mgmt-skeleton-feed-row">
                <span class="ft-mgmt-skeleton-circle"></span>
                <div class="ft-mgmt-skeleton-feed-copy"><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
                <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-time"></span>
            </div>
        @endfor
    </div>
    <span class="sr-only">Loading mentions</span>
    </div>
</section>
