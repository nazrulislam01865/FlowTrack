<div class="ft-mgmt-dashboard-overview" data-dashboard-panel="overview">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-overview-content">
    <x-orders.workflow-stage-overview
        :stages="$orderStages"
        mode="navigate"
        :show-header="false"
        :navigation-query="$orderStageNavigationQuery"
    />



    </div>
</div>
