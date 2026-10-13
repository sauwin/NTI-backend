# NTI Backend

REST API for the **NTI platform** — a system for managing student and company applications, programme calls, evaluations and mentorships. It handles authentication, role-based permissions, application workflow, document storage, notifications, exports and audit logging.

The API is consumed by the **FE_NTI** Vue frontend.

## About

NTI Backend is a Laravel application that exposes a JSON API under `/api`. Authentication uses Laravel Sanctum tokens. Business logic lives in service classes, access rules in policies and middleware, and long-running work (emails, bulk notifications, scheduled evaluations) runs through Redis queues.

## Features

- Registration with email verification, login, logout, password reset (rate limited)
- Student registration restricted to allowed email domains
- 8 roles with policies and middleware (`is_active`, `admin`, `super_admin`)
- **Programme calls** (A and B) with statuses `draft`, `open`, `closed`, `archived`; expired calls are closed automatically
- **Applications** with drafts, submission, revision requests, status history and per-user application limit
- **Programme B tasks** published by companies, linked to calls
- Teams with invitations
- Document upload with validation, optional ClamAV antivirus scan (off by default), download and preview
- Evaluations with configurable criteria, evaluator assignment, scheduled start and final verdict
- Mentorships, consultations and milestones with deadline reminders
- Organisations (companies), member approval and partner status
- News articles and FAQ with translations (EN / SK)
- In-app notifications, email notifications and admin bulk notifications
- GDPR: consent records, data export and account anonymisation
- Audit log of important actions
- Excel exports (applications, users, calls, companies, notifications, dashboard stats)
- Reporting dashboard statistics
- Generated sitemap
- OpenAPI documentation via Scramble
- Automated tests (Pest) and CI with GitHub Actions

## Roles

| Role           | Slug             | Description                           |
| -------------- | ---------------- | ------------------------------------- |
| Student        | `student`        | Applies to programmes                 |
| Company        | `company`        | Posts tasks for Programme B           |
| Internal       | `internal`       | Temporary role assigned by an admin   |
| Mentor         | `mentor`         | Mentors accepted projects             |
| Evaluator      | `evaluator`      | Commission member evaluating requests |
| Content Editor | `content_editor` | Manages public content                |
| NTI Admin      | `nti_admin`      | Operational programme administrator   |
| Super Admin    | `super_admin`    | Full access, manages admin accounts   |

## Application Statuses

```text
draft → submitted → formally_verified → under_evaluation → approved → onboarding → active → closed
                         ↘ pending_revision (revision requested)        ↘ rejected / suspended
```

Every status change is written to `application_status_history` and to the audit log.

## Tech Stack

| Layer             | Technology                                   |
| ----------------- | -------------------------------------------- |
| Language          | PHP 8.3+ (Docker image uses 8.4)             |
| Framework         | Laravel 13                                   |
| Authentication    | Laravel Sanctum                              |
| Database          | MySQL 8 (SQLite in-memory for tests)        |
| Cache / queue     | Redis (Predis)                               |
| Excel exports     | maatwebsite/excel                            |
| Sitemap           | spatie/laravel-sitemap                       |
| File scanning     | sunspikes/clamav-validator                   |
| API docs          | dedoc/scramble                               |
| Testing           | Pest                                         |
| Code style        | Laravel Pint                                 |
| Containerisation  | Docker Compose, Nginx, Mailpit (dev)         |
| CI                | GitHub Actions                               |

## Project Structure

```text
BE_NTI/
├── app/
│   ├── Console/Commands/    # calls:closeExpired, nti:deadlineReminders, app:initializeSuperAdmin
│   ├── Exports/             # Excel export classes
│   ├── Http/
│   │   ├── Controllers/     # API controllers (+ Admin/)
│   │   ├── Middleware/      # IsActive, IsAdmin, IsSuperAdmin, HSTS
│   │   ├── Requests/        # form request validation
│   │   └── Resources/       # API resources
│   ├── Jobs/                # SendBulkNotification, StartScheduledEvaluations
│   ├── Mail/                # transactional emails
│   ├── Models/              # Eloquent models
│   ├── Notifications/
│   ├── Policies/            # authorisation rules per model
│   ├── Providers/
│   └── Services/            # Application, Workflow, Call, Task, Audit, ArticleImage
├── config/                  # Laravel config (sanctum, cors, scramble, …)
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── nginx/                   # dev and production configs
├── routes/
│   ├── api.php              # all API routes
│   ├── console.php          # scheduled tasks
│   └── web.php              # health check and sitemap.xml
├── tests/
│   ├── Feature/             # workflow, authorisation, audit, evaluation, …
│   └── Unit/
├── .github/workflows/ci.yml
├── Dockerfile
├── docker-compose.yaml      # app, mysql, redis
├── docker-compose.dev.yaml  # ports, mailpit, nginx
└── docker-compose.prod.yaml
```

## Database

The schema is created by migrations in `database/migrations/`. Main tables:

| Area            | Tables                                                                                          |
| --------------- | ----------------------------------------------------------------------------------------------- |
| Users and roles | `users`, `roles`, `user_roles`, `organizations`, `student_profiles`, `student_skills`, `mentor_profiles` |
| Programmes      | `calls`, `call_evaluators`, `tasks`, `task_documents`, `drafts`                                 |
| Applications    | `applications`, `application_status_history`, `application_revision_requests`, `application_documents`, `teams`, `team_members` |
| Evaluation      | `evaluation_criteria`, `evaluations`, `evaluation_criteria_scores`                              |
| Mentoring       | `mentorships`, `consultations`, `milestones`, `milestone_documents`                             |
| Content         | `news_articles` (+ translations, images), `faq_items` (+ translations), `pages` (+ translations) |
| System          | `documents`, `audit`, `notification_log`, `notification_templates`, `bulk_notification_campaigns`, `gdpr_consents`, `password_reset_tokens`, `personal_access_tokens`, jobs, cache, sessions |

## Getting Started

### Prerequisites

- PHP 8.3+ with `pdo_mysql`, `zip`, `mbstring`, `xml`
- Composer 2
- MySQL 8.0+
- Redis
- Docker (optional)

### Setup

```bash
git clone https://github.com/your-username/BE_NTI.git
cd BE_NTI
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The API runs on `http://localhost:8000/api`. Start the queue worker in a second terminal (required for emails and background jobs):

```bash
php artisan queue:work
```

### Docker

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Services: `app`, `mysql`, `redis`; the dev override adds Mailpit (web UI on `http://localhost:8025`) and Nginx. The compose file uses its own database credentials (`laravel` / `secret`), so no local MySQL is needed.

## Configuration

Key variables in `.env`:

| Variable                      | Description                                                    |
| ----------------------------- | -------------------------------------------------------------- |
| `APP_URL`                     | URL of the API (`http://localhost:8000`)                       |
| `FRONTEND_URL`                | URL of the frontend, used in emails                            |
| `CORS_ALLOWED_ORIGINS`        | Allowed frontend origins, comma-separated                      |
| `SANCTUM_STATEFUL_DOMAINS`    | Frontend domains for Sanctum, comma-separated                  |
| `DB_*`                        | MySQL connection                                               |
| `REDIS_*`                     | Redis connection (cache, session and queue use Redis)          |
| `MAIL_*`                      | SMTP settings (see below)                                      |
| `STUDENT_ALLOWED_DOMAINS`     | Email domains allowed for student registration                 |
| `MAX_ACTIVE_APPLICATIONS`     | Active applications per person, `0` = unlimited                |
| `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` | Credentials for the initial super admin       |
| `CLAMAV_SKIP_VALIDATION`      | `true` (default) skips the antivirus scan; set `false` in production with ClamAV running |
| `CLAMAV_REMOTE_HOST` / `CLAMAV_REMOTE_PORT` | ClamAV daemon address (default `127.0.0.1:3310`) |

### Mail

The app uses SMTP. For Gmail, create an app password at <https://myaccount.google.com/apppasswords>:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=16_digit_appcode
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=you@gmail.com
MAIL_FROM_NAME=NTI
```

With the Docker dev setup you can point `MAIL_HOST` to `mailpit` (port `1025`) instead.

### Production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

FRONTEND_URL=https://your-frontend.com
SANCTUM_STATEFUL_DOMAINS=your-frontend.com
CORS_ALLOWED_ORIGINS=https://your-frontend.com
```

Several origins or domains are comma-separated, for example `CORS_ALLOWED_ORIGINS=https://app.nti.sk,https://admin.nti.sk`. A production `migrate --seed` runs only `RoleSeeder`. The HSTS header is added automatically for HTTPS requests.

## Initial Accounts

- In **local** environments `migrate --seed` creates roles, demo users, articles, FAQ, calls and sample teams / applications.
- In **production** only roles are seeded. Create the super admin from `.env` values:

```bash
php artisan app:initializeSuperAdmin
```

- The super admin can then create NTI admins through `POST /api/admin/create-admin` (the new admin's password is sent in the request body, minimum 8 characters).

## Scheduled Tasks

| Task                          | Schedule     | Purpose                                      |
| ----------------------------- | ------------ | -------------------------------------------- |
| `nti:deadlineReminders`       | daily 09:00  | Email reminders about upcoming deadlines     |
| `calls:closeExpired`          | hourly       | Closes calls whose deadline has passed       |
| `StartScheduledEvaluations`   | hourly       | Starts evaluations scheduled for a call      |

Run the scheduler locally with `php artisan schedule:work`, or add a cron entry that runs `php artisan schedule:run` every minute on the server.

## API Overview

All routes are in `routes/api.php`. OpenAPI documentation is generated by Scramble (by default at `/docs/api`). It is open in the `local` environment; elsewhere only admin users can view it.

| Group              | Examples                                                                 | Access                  |
| ------------------ | ------------------------------------------------------------------------ | ----------------------- |
| Public             | `POST /auth/register`, `POST /auth/login`, `GET /articles`, `GET /faq-items`, `GET /partners`, `GET /calls/active` | Anyone |
| Authenticated      | `/applications`, `/teams`, `/documents`, `/evaluations`, `/mentorships`, `/milestones`, `/notifications`, `/gdpr/*`, `/profile` | Logged-in, active user |
| Organisation       | `/company/tasks`, `/company/members/*`, `/calls-with-tasks`              | Company members         |
| Admin              | `/admin/users`, `/admin/calls`, `/admin/applications`, `/admin/export/*`, `/admin/notifications/bulk`, `/admin/logs` | `nti_admin`, `super_admin` |
| Super admin        | `/admin/create-admin`, `/admin/users/{id}` (delete), `/admin/users/{id}/reset-password` | `super_admin` |

Sensitive endpoints (login, registration, password reset, uploads, GDPR export) are rate limited with `throttle` middleware.

## Authentication Flow

1. `POST /api/auth/register` creates the user and sends a verification email.
2. The link in the email calls `GET /api/email/continueRegistration/{id}/{hash}` and redirects to the frontend.
3. `POST /api/auth/login` returns a Sanctum token.
4. The client sends `Authorization: Bearer <token>` with each request.
5. `is_active` middleware blocks deactivated users; `admin` and `super_admin` middleware protect admin routes.
6. `POST /api/auth/logout` revokes the token.

## Testing

Tests use Pest with an in-memory SQLite database, so MySQL and Redis are not needed:

```bash
php artisan test
```

Run the code-style check with `composer lint:check` (Laravel Pint) or fix issues with `composer lint`.

The GitHub Actions workflow (`.github/workflows/ci.yml`) runs on pushes and pull requests to `master` and `develop`:

1. **Backend** — installs dependencies and runs the Pest suite
2. **Frontend** — checks out FE_NTI, runs type-check and build
3. **E2E** — starts this API and runs the frontend Playwright tests against it

## Related Repositories

- **FE_NTI** — Vue 3 frontend for this API

## License

Released under the MIT license (see `composer.json`).
