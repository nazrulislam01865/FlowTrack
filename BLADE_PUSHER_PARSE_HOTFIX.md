# Blade Pusher Parse Hotfix

Fixed `resources/views/app.blade.php` so Laravel no longer compiles a multiline
`@json([...])` directive containing nested function calls into invalid PHP.

The configuration array is now built in an `@php` block and serialized with
`Illuminate\\Support\\Js::from(...)`.

Compiled Blade views were removed from this package. After replacing the project,
run:

```bash
php artisan optimize:clear
php artisan view:clear
```
