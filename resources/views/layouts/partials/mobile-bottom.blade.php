<nav class="mobile-bottom">
    <a href="{{ route('dashboard') }}" wire:navigate class="{{ request()->routeIs('dashboard')?'active':'' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Home</a>
    <a href="{{ route('my-work') }}" wire:navigate class="{{ request()->routeIs('my-work')?'active':'' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/></svg>My Tasks</a>
    <a href="{{ route('jobs.index') }}" wire:navigate class="{{ request()->routeIs('jobs.index')?'active':'' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v13H4z"/></svg>Order</a>
    @if(auth()->user()->canAccess('document_archive.view'))<a href="{{ route('documents.index') }}" wire:navigate class="{{ request()->routeIs('documents.index')?'active':'' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6v20h12V8z"/></svg>Archive</a>@endif
</nav>
