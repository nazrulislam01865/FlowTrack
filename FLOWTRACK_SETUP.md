# FlowTrack Setup and Architecture

FlowTrack is a Laravel 13 + Vue 3 + Inertia order-flow management application. It converts the supplied static prototype into a database-backed multi-user workspace where access is controlled by workspace role and job assignment.

## Main workflow

A job moves through a configurable workflow such as:

1. Request Received
2. Quotation in Progress
3. Quotation Submitted
4. Negotiation
5. Confirmed Order
6. Artwork
7. Swatch / Sample
8. Production
9. Shipment
10. Invoice & Payment
11. Completed

Each phase can define:

- whether a new job may start there;
- whether it can be skipped;
- whether approval is required;
- entry and exit conditions;
- the required document category;
- the task pack that is created automatically when the phase starts.

Starting from a middle phase requires a reason. The backend records earlier phases as skipped and preserves the reason in phase history. Moving ahead across phases is rejected when a non-skippable phase would be bypassed.
Forward movement is also blocked until required phase tasks are complete, the required document category is uploaded, and any approval gate is explicitly approved.

## Clean architecture

```text
app/
├── Http/
│   ├── Controllers/FlowTrack/       # HTTP/Inertia adapters only
│   ├── Middleware/                  # Permission boundary
│   └── Requests/FlowTrack/          # Input validation
└── Modules/OrderFlow/
    ├── Application/
    │   ├── Actions/                 # Use cases: create job, move phase, update task, upload document
    │   ├── DTOs/                    # Immutable application input
    │   └── Queries/                 # Page/select-list application queries
    ├── Business/
    │   ├── Enums/                   # Stable business values
    │   └── Services/                # Workflow engine, task-pack activation, access, codes, audit log
    └── Data/
        ├── Models/                  # Eloquent persistence models
        └── Repositories/            # Database reads and visibility-aware queries
```

Frontend structure:

```text
resources/js/
├── components/flowtrack/            # Reusable badges, progress, modal, drawer, shell, job/task UI
├── layouts/FlowTrackLayout.vue       # Responsive application shell
├── lib/flowtrack.ts                  # Shared formatting utilities
└── pages/flowtrack/                  # Feature pages grouped by module
```

## Database design highlights

- `flow_jobs` is used instead of `jobs` to avoid collision with Laravel's queue table.
- `workspace_memberships` connects a user to one workspace role and department.
- `flow_job_members` applies project-level access and capabilities.
- `workflow_templates` and `workflow_phases` store dynamic process definitions.
- `task_packs` and `task_pack_items` define reusable automatic work.
- `flow_job_phase_histories` preserves completed/current/skipped phase history.
- `flow_phase_approvals` stores the approver, decision note and timestamp for approval-gated phases.
- `flow_documents` and `flow_document_versions` preserve file version history.
- `flow_tasks`, checklist items, comments, parent/dependency links and start rules provide Jira-style execution.
- `flow_activities` provides an audit trail.
- `master_records` stores typed reusable records without duplicating CRUD code.

## Local requirements

- PHP 8.3 or newer
- Composer 2
- MySQL 8 or MariaDB 10.6+
- Node.js 20 or newer
- npm 10 or newer

## Create the MySQL database

```sql
CREATE DATABASE flowtrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'flowtrack_user'@'localhost' IDENTIFIED BY 'change_this_password';
GRANT ALL PRIVILEGES ON flowtrack.* TO 'flowtrack_user'@'localhost';
FLUSH PRIVILEGES;
```

Then set these values in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flowtrack
DB_USERNAME=flowtrack_user
DB_PASSWORD=change_this_password
```

## First installation

Run from the project directory:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
npm install
npm run build
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000`.

Demo login:

```text
Email: manager@flowtrack.cn
Password: demo123
```

## Development mode

After the first installation:

```bash
composer run dev
```

This starts the Laravel server, queue worker, log viewer and Vite development server through the starter kit's development command.

## Useful validation commands

```bash
php artisan route:list --path=flowtrack
php artisan migrate:status
php artisan test
vendor/bin/pint --test
npm run types:check
npm run lint:check
npm run build
```

## Production deployment outline

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm install --no-audit --no-fund
npm run build
php artisan down
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
php artisan up
```

Set production values before deployment:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public
```

Run a persistent queue worker through Supervisor or systemd for notifications and future background automation.

## Implemented access model

- Administrators and operations managers can access every job and administration screen.
- Sales managers can manage all jobs and clients but do not receive workflow/master-data administration by default.
- Members see jobs only when they are the owner, coordinator, assigned job member, or assignee of a task in that job.
- Job members have independent flags for task management, document upload and financial visibility.
- Controllers apply visibility-aware repositories and management checks before changing data.
- An active workspace-membership middleware blocks authenticated users who have not been assigned to a workspace.
- Public registration is disabled; administrators create members and assign their initial role and department.
- Assigned project teams can be changed from the Job drawer, while the owner and coordinator retain manager access.
- Administrators can create workspace roles and edit grouped permissions without changing source code.
- Global search returns only jobs, tasks, clients and documents visible to the signed-in member.

## Validation completed in the generated archive

- All PHP files were checked with `php -l`.
- Local `@/` frontend imports were checked and resolve to existing source files.
- TypeScript sections were syntax-transpiled with TypeScript 5.8.
- Full Composer/Pest and Vite builds still need to be run after dependency installation on your machine. The generation environment did not contain Composer and could not reach the npm registry before timeout.
- Pre-existing starter build assets were intentionally removed so the application cannot accidentally serve an outdated frontend. Run `npm run build` after installing dependencies.

## Automatic configuration codes

Master Data codes are generated inside Laravel by `ConfigurationCodeGenerator`. The browser only displays a read-only preview; the backend generates the final value again during creation. Existing codes stay immutable during updates.

Section prefixes are `CAT`, `PRD`, `SUP`, `PU`, `SHP`, `CUR`, `DOC`, `DEP`, `PRI`, and `TST`. Task Packs use `TPK`. The sequence considers both the highest numeric suffix and the number of existing/soft-deleted records, allowing the new system to continue safely after legacy character-only codes.

## Complete Task Pack editor

The exact-template Administration workspace now creates and edits a Task Pack and all of its task rows in one transaction. Each row supports title, description, order, required flag, default assignee, default department, priority, and due offset. If no fixed assignee is selected, Task Pack activation first tries an active member from the default department and then falls back to the Job coordinator.

## Role and permission matrix

The access-control implementation is separated into:

- `RoleMatrixCatalog` — stable module, action, scope and sensitive-field definitions.
- `RoleMatrixService` — initializes roles, persists matrix rows and synchronizes legacy Laravel permission slugs.
- `TemplateAccessController` — workspace-scoped JSON endpoints for roles, matrices and member assignments.
- `role_module_access` — one record per role/module containing its action list and record scope.
- `roles.sensitive_fields` — JSON list of fields visible to the role.

Run `php artisan migrate` to create the new role metadata and matrix storage. Existing roles are initialized from their current permission assignments the first time the workspace bootstrap is loaded.

## Admin and Super Admin control

Super Admin credentials are not hardcoded. Add the following to `.env`:

```dotenv
FLOWTRACK_SUPER_ADMIN_NAME="Your Super Admin Name"
FLOWTRACK_SUPER_ADMIN_EMAIL=superadmin@yourcompany.com
FLOWTRACK_SUPER_ADMIN_PASSWORD="ReplaceWithAUniqueStrongPassword123!"
```

Apply the new migration and seed the administrator records:

```bash
php artisan migrate
php artisan db:seed --class="Database\\Seeders\\FlowTrack\\SuperAdminSeeder"
php artisan optimize:clear
```

The seeder creates or repairs `SUPER_ADMIN` and `ADMIN`, grants every module/action with `all_records`, enables all sensitive fields, and creates or updates the configured Super Admin login. It is safe to rerun after changing the `.env` password.

Only Admin and Super Admin can create or delete users. Only Super Admin can assign or modify the Super Admin role/account. The permission matrix is enforced on both the exact-template JSON API and the older Inertia controllers/routes.
