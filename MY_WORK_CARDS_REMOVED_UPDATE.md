# My Work Cards Removed Update

## Change
- Removed the My Work summary cards for every user type, including admins and regular users.
- My Work now opens directly with the existing toolbar, quick filters, and task board.
- Updated both the active dynamic script and the legacy Blade template fallback.

## Files changed
- public/flowtrack-dynamic.js
- resources/views/flowtrack-template.blade.php

## Verification
- node --check public/flowtrack-dynamic.js
- php -l resources/views/flowtrack-template.blade.php
