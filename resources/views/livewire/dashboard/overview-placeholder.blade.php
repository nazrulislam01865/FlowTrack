<div class="ft-mgmt-dashboard-overview ft-mgmt-skeleton-overview" data-dashboard-skeleton-for="overview" aria-busy="true" role="status" aria-label="Loading Orders by workflow stage">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-overview-content">
    <section class="ft-order-workflow-stage-overview" wire:init="loadDashboardPanel" aria-hidden="true">
        <div class="ft-order-workflow-stage-strip">
            @for($i = 0; $i < 7; $i++)
                <div class="ft-mgmt-skeleton-stage">
                    <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-kicker"></span>
                    <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-stage-title"></span>
                    <div class="ft-mgmt-skeleton-stage-count">
                        <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-short"></span>
                        <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-number"></span>
                    </div>
                </div>
            @endfor
        </div>
    </section>
    <span class="sr-only">Loading workflow stages</span>
    </div>
</div>
