# Session middleware termination fix

## Problem

Authenticated requests completed their response and then failed with:

- `Call to private method App\\Http\\Middleware\\EnforceFlowTrackSession::terminate()`
- followed by `Cannot modify header information - headers already sent`

## Root cause

`EnforceFlowTrackSession` used a private response helper named `terminate()`. Laravel reserves a middleware method with that name for terminable middleware and invokes it after sending the response. The framework therefore attempted to call the private helper as a lifecycle hook.

## Fix

- Renamed the private helper from `terminate()` to `logoutResponse()`.
- Updated expired-session and replaced-session branches to call the renamed helper.
- Removed generated route/config/view/session caches and the included runtime log from the distributable archive so stale optimized files cannot retain the broken class behavior.

No database migration is required for this fix.
