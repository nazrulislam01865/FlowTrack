# Refresh / renderer / loader stability repair

- Hard-refresh startup is atomic: the app, drawer, overlay and modal sources stay hidden until the exact requested route is fully mounted.
- Create/edit forms may still reuse existing modal-building logic internally, but that temporary modal can never flash on screen during a reload.
- The authenticated startup loader now uses the same circular percentage design as page navigation.
- Inline data refresh indicators use a circular spinner instead of a progress bar.
- Once the final Job Details renderer is loaded, legacy Job Details stages are blocked from repainting over it.
- Job panel refreshes route back through the final Job renderer when available.
- No business logic, permissions, route URLs, or form payloads were changed.
