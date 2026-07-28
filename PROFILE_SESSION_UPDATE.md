# FlowTrack Session and Profile Update

## Authentication screen behavior

- Guests see the login screen.
- Authenticated requests show a neutral workspace loader while Laravel profile/bootstrap data is fetched.
- `/login` redirects authenticated users directly to `/dashboard` on the server.
- `/dashboard`, `/flowtrack`, and `/flowtrack/dashboard` redirect guests to `/login`.
- The login form has an optional **Keep me signed in** control instead of always creating a persistent remember cookie.
- Logout invalidates the Laravel session and regenerates the CSRF token.

## Dynamic profile

The exact-template profile now uses the authenticated `users` row and active workspace membership. It supports:

- Full name and generated initials
- Email
- Phone
- Preferred language
- Default landing page
- Default task view
- Notification preferences
- Password change with current-password verification
- JPG, PNG, and WebP profile photo upload up to 2 MB
- Profile photo removal

Run `php artisan migrate` to add the new profile preference columns.
