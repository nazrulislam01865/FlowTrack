# FlowTrack Livewire frontend structure

FlowTrack uses Laravel Livewire 4 for route-level screen lifecycle while
preserving the exact Archive 6 Blade design, DOM IDs, classes, CSS selectors,
and backend API contracts.

## Components

```text
app/Livewire/FlowTrack/
├── WorkspaceShell.php
└── Screen.php

resources/views/livewire/flow-track/
├── workspace-shell.blade.php
├── screen.blade.php
└── screens/
    ├── dashboard.blade.php
    ├── mywork.blade.php
    ├── jobs.blade.php
    ├── clients.blade.php
    ├── board.blade.php
    ├── documents.blade.php
    ├── reports.blade.php
    ├── notifications.blade.php
    ├── workflowsetup.blade.php
    ├── taskpacksetup.blade.php
    ├── masterdata.blade.php
    ├── setup.blade.php
    └── profile.blade.php
```

`WorkspaceShell` owns the canonical screen registry. `Screen` resolves the
matching module view, and every view retains the previous `page-{name}` DOM
contract. `wire:ignore` protects dynamically rendered
forms, boards, drawers, and inline edits from being replaced during a Livewire
DOM morph.

Authentication, loading, workspace layout, overlays, runtime defaults and
asset tags are separate views under `resources/views/flowtrack/`. The root
`flowtrack-template.blade.php` is now a small composition entry rather than a
monolithic frontend file.

`public/flowtrack-livewire.js` synchronizes navigation state with Livewire. It
reinstalls its bridge after a lazy frontend module changes the active route
implementation.

The backend remains the only source of truth for authorization, workflow
transitions, assignments, documents, notifications, and persisted records.
