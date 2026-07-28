# FlowTrack Admin and Super Admin setup

## 1. Configure the Super Admin in `.env`

Add these values before running the seeder:

```dotenv
FLOWTRACK_WORKSPACE_NAME="FlowTrack Workspace"
FLOWTRACK_WORKSPACE_SLUG=flowtrack-demo
FLOWTRACK_WORKSPACE_TIMEZONE=Asia/Dhaka
FLOWTRACK_WORKSPACE_CURRENCY=USD

FLOWTRACK_SUPER_ADMIN_NAME="Your Super Admin Name"
FLOWTRACK_SUPER_ADMIN_EMAIL=superadmin@yourcompany.com
FLOWTRACK_SUPER_ADMIN_PASSWORD="ReplaceWithAUniqueStrongPassword123!"
```

The password must contain at least 12 characters. Keep it quoted when it contains `#`, `$`, spaces, or other special characters.

## 2. Apply to an existing database

```bash
php artisan migrate
php artisan db:seed --class="Database\\Seeders\\FlowTrack\\SuperAdminSeeder"
php artisan optimize:clear
```

The seeder is idempotent. Running it again updates the configured Super Admin name/password, restores the Super Admin membership, and repairs the unrestricted Admin/Super Admin matrices.

Do not run `migrate:fresh` on an existing database because it deletes all current data.

## 3. Fresh installation

After setting the `.env` values:

```bash
php artisan migrate:fresh --seed
```

## Administrator rules

- **Super Admin** has unrestricted access to every module, action, record scope, sensitive field, and administration operation.
- **Admin** also has unrestricted operational access.
- Only Super Admin can assign or modify the `SUPER_ADMIN` role/account.
- Admin and Super Admin can create and delete users.
- A signed-in administrator cannot delete their own account.
- Role matrix values are enforced by backend controllers, form requests, repositories, global search, reports, boards, downloads, and the exact-template API.
