# Event Attendance Management System (EAMS) — API

Laravel API-only backend for the Event Attendance Management System. The frontend (Vue.js) is
maintained in a separate repository; this project only serves a versioned JSON API.

## Stack

- Laravel 13 / PHP 8.4, MySQL 8
- Auth: [Laravel Sanctum](https://laravel.com/docs/sanctum) personal access tokens (bearer), suitable
  for the web app, Android scanner clients, and dedicated QR scanner devices alike
- Docker Compose: `app` (PHP-FPM), `webserver` (nginx, `:8000`), `db` (MySQL, `:3306`),
  `phpmyadmin` (`:8080`)

## Getting Started

```bash
cp .env.example .env
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The API is then available at `http://localhost:8000/api/v1`. The seeder creates:

| Role | Email | Password |
| --- | --- | --- |
| Super Admin | `superadmin@example.com` | `password` |
| Org Admin | `admin@example.com` | `password` |
| Attendance Checker | `checker@example.com` | `password` |

Run the test suite with:

```bash
docker compose exec app php artisan test --compact
```

## Domain Model

- **Organizations** are the top-level tenant boundary — users, territories, attendees, and events
  are scoped to one organization (except Super Admins, who see everything).
- **Users** have one role: `super_admin`, `org_admin`, or `checker`. Checkers default to full access
  to all events in their organization, and can be restricted to specific events via
  `event_checker_access`.
- **Unions**/**Missions** model the two-level territory hierarchy used for attendance analytics.
- **Attendees** are a reusable master record per organization, with normalized-name based duplicate
  detection at creation time.
- **Events** contain **Event Sessions**; an attendee must have an **Event Registration** (with a
  unique, non-guessable `qr_token`) before attendance can be recorded for that event.
- **Attendance Records** are created by scanning a registration's QR code or via manual fallback,
  scoped to a specific session, with a configurable per-event check-in window (default 30 minutes
  before session start) and duplicate check-in protection.

## API Overview (v1)

All routes are prefixed with `/api/v1` and (except login) require an `Authorization: Bearer <token>`
header.

- `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`
- `GET|POST /organizations`, `GET|PUT|DELETE /organizations/{organization}` (Super Admin only)
- `GET|POST /users`, `GET|PUT|DELETE /users/{user}`, `PUT /users/{user}/event-access`
- `GET|POST /unions`, `GET|PUT|DELETE /unions/{union}`
- `GET|POST /missions`, `GET|PUT|DELETE /missions/{mission}`
- `GET|POST /attendees`, `GET|PUT|DELETE /attendees/{attendee}`, `GET /attendees/check-duplicates`
- `GET|POST /events`, `GET|PUT|DELETE /events/{event}`
- `GET|POST /events/{event}/sessions`, `GET|PUT|DELETE /events/{event}/sessions/{session}`
- `GET|POST /events/{event}/registrations`, `GET|DELETE /events/{event}/registrations/{registration}`
- `POST /attendance/scan`, `POST /attendance/manual`, `POST /attendance/{attendanceRecord}/check-out`
- `GET /events/{event}/sessions/{session}/attendance`

## Scope

This is Phase 1 of the system: core foundation (organizations, auth/roles, territories, attendees,
events/sessions/registrations, QR + manual attendance). Offline sync, reports/dashboards, bulk
QR-code PDF export, attendee ID cards, and the audit log are planned for later phases.

