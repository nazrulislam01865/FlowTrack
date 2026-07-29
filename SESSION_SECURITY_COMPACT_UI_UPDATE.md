# Session Security and Compact UI Update

## Session rules

- Every authenticated login receives a unique active-session token.
- A user account can have only one active login at a time.
- A successful second login replaces the previous token.
- The previous browser checks the server every 10 seconds and is logged out with a red message.
- Every session has an absolute 30-minute lifetime. User activity does not extend it.
- Laravel login throttling applies both per account and per IP.

## Interface changes

- Removed the New Job action from the global header in both the template and Vue layouts.
- Reduced page, modal, drawer, card, and form spacing.
- Standardized primary, secondary, neutral, and destructive action-button heights.
- Kept page-level New Job actions where they are contextually useful.

## Deployment

```bash
php artisan migrate --force
php artisan optimize:clear
npm install
npm run build
php artisan optimize
```

Optional environment values:

```dotenv
SESSION_LIFETIME=30
FLOWTRACK_SESSION_MINUTES=30
```
