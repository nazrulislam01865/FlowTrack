# Exact-template dynamic implementation

The FlowTrack interface remains based on the supplied workflow template. Dynamic behavior is added by `public/flowtrack-dynamic.js` and Laravel JSON endpoints without redesigning the template.

## Access control implementation

- Roles & Policies
- Permission Matrix
- Record scopes
- Sensitive-field visibility
- User creation, editing, role assignment, activation and deletion
- Admin/Super Admin unrestricted access
- `.env`-seeded Super Admin

The intentionally excluded access-control prototype areas remain excluded:

- Client and Job Access
- Approval Authority
- Access Requests
- Access Simulator

Backend authorization remains authoritative even when a frontend control is hidden. Direct API requests are checked again by controllers, Form Requests, middleware and visibility-aware repositories.
