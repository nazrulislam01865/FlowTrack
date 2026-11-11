@if($kind === 'distributionpanel')
<section class="ft-mgmt-panel" style="min-height:{{ $height }}px" role="alert">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-{{ str_replace('panel', '', $kind) }}-content">

    <div class="ft-mgmt-panel-head"><div><h2>{{ $heading }}</h2></div></div>
    <div class="ft-mgmt-panel-body">
        <p>Unable to load this dashboard section.</p>
        <button type="button" class="ft-mgmt-btn" wire:click="$refresh">Retry</button>
    </div>
    </div>
</section>
@else
<article class="ft-mgmt-panel" style="min-height:{{ $height }}px" role="alert">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-{{ str_replace('panel', '', $kind) }}-content">
    <div class="ft-mgmt-panel-head"><div><h2>{{ $heading }}</h2></div></div>
    <div class="ft-mgmt-panel-body">
        <p>Unable to load this dashboard section.</p>
        <button type="button" class="ft-mgmt-btn" wire:click="$refresh">Retry</button>
    </div>
    </div>
</article>
@endif
