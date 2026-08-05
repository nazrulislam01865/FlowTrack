<div id="appScreen" class="app hidden">
  <aside id="sidebar" class="sidebar">
    <div class="brand"><div class="brand-mark {{ !empty($flowtrackBranding['logoUrl']) ? 'has-brand-image' : '' }}" data-flowtrack-brand-mark>@if(!empty($flowtrackBranding['logoUrl']))<img class="flowtrack-brand-image" src="{{ $flowtrackBranding['logoUrl'] }}" alt="FlowTrack logo">@else FT @endif</div><span>FlowTrack</span></div>
    <div class="sidebar-section" data-i18n="workspace">Workspace</div>
    <a class="nav-btn active" href="{{ route('flowtrack.view.dashboard') }}" data-page="dashboard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg><span data-i18n="dashboard">Dashboard</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.mywork') }}" data-page="mywork"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><span data-i18n="myWork">My Work</span><span class="nav-badge" id="myWorkBadge">9</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.jobs.index') }}" data-page="jobs"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3"/><path d="M4 12h16"/></svg><span data-i18n="jobs">Jobs</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.clients.index') }}" data-page="clients"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2"/><path d="M16 3.5a4 4 0 0 1 0 7.5"/><path d="M17 14a6 6 0 0 1 5 6"/></svg><span data-i18n="clients">Clients</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.board') }}" data-page="board"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="5" height="16"/><rect x="10" y="4" width="5" height="10"/><rect x="17" y="4" width="4" height="13"/></svg><span data-i18n="board">Board</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.documents') }}" data-page="documents"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg><span data-i18n="documents">Documents</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.reports') }}" data-page="reports"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg><span data-i18n="reports">Reports</span></a>
    <div class="sidebar-section" data-i18n="administration">Administration</div>
    <a class="nav-btn" href="{{ route('flowtrack.view.notifications') }}" data-page="notifications"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><span data-i18n="notifications">Notifications</span><span class="nav-badge" id="notificationBadge">6</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.workflows.index') }}" data-page="workflowsetup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h5v5H5zM14 5h5v5h-5zM14 14h5v5h-5z"/><path d="M10 7.5h4M16.5 10v4M5 16.5h9"/><circle cx="5" cy="16.5" r="2"/></svg><span>Workflow Setup</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.task-packs.index') }}" data-page="taskpacksetup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v5H4zM4 14h16v5H4z"/><path d="M8 5v5M16 14v5"/></svg><span>Task Pack Setup</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.master-data') }}" data-page="masterdata"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v4H4zM4 11h16v4H4zM4 17h16v3H4z"/><path d="M8 5v15M16 5v15"/></svg><span>Master Data</span></a>
    <a class="nav-btn" href="{{ route('flowtrack.view.administration') }}" data-page="setup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 5 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.4 5a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.13.37.33.71.6 1 .3.3.68.43 1.1.4h.1v4h-.1a1.7 1.7 0 0 0-1.7.6z"/></svg><span data-i18n="setup">Setup</span></a>
    <div class="sidebar-footer"><div class="user-mini"><div class="avatar dark">{{ auth()->user()?->initials ?: 'FT' }}</div><div><div class="user-mini-name">{{ auth()->user()?->name ?: 'FlowTrack User' }}</div><div class="current-user-role">Workspace member</div></div></div><form id="logoutForm" method="POST" action="{{ route('flowtrack.logout') }}">@csrf<button id="logoutBtn" class="nav-btn logout-btn" type="submit" aria-label="Log out" data-no-button-loader><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg><span>Log out</span></button></form></div>
  </aside>
  <div id="sidebarShade" class="mobile-sidebar-shade"></div>
  <main class="main">
    <header class="topbar">
      <button id="mobileMenu" class="mobile-menu">☰</button>
      <div class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input id="globalSearch" placeholder="Search jobs, clients, tasks, documents…"></div>
      <div class="top-actions">
        <button id="languageBtn" class="lang-btn">中文</button>
        <button class="icon-btn" data-page-jump="notifications" aria-label="Notifications"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><span class="dot"></span></button>
        <button class="icon-btn" data-page-jump="profile" aria-label="Profile"><span class="avatar topbar-avatar">{{ auth()->user()?->initials ?: 'FT' }}</span></button>
      </div>
    </header>
    <div class="content">
      @include('flowtrack.pages.screen')
    </div>
  </main>
  <nav class="mobile-bottom">
    <a class="active" href="{{ route('flowtrack.view.dashboard') }}" data-page="dashboard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Home</a>
    <a href="{{ route('flowtrack.view.mywork') }}" data-page="mywork"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>My Work</a>
    <a href="{{ route('flowtrack.view.jobs.index') }}" data-page="jobs"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3"/></svg>Jobs</a>
    <a href="{{ route('flowtrack.view.board') }}" data-page="board"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="5" height="16"/><rect x="10" y="4" width="5" height="10"/><rect x="17" y="4" width="4" height="13"/></svg>Board</a>
    <a href="{{ route('flowtrack.view.documents') }}" data-page="documents"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6v20h12V8z"/><path d="M14 2v6h6"/></svg>Files</a>
  </nav>
</div>
