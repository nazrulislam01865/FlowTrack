<div class="flowtrack-livewire-screens" data-livewire-workspace-shell>
    @foreach($screens as $screen)
        <livewire:flow-track.screen
            :name="$screen"
            :active="$screen === $activePage"
            :key="'flowtrack-screen-'.$screen"
        />
    @endforeach
</div>
