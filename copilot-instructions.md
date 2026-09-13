# Copilot Instructions for AMS

This file configures GitHub Copilot's behavior for the Attendance Management System (AMS) project.

## Project Context

- **Stack**: Laravel 13 (PHP 8.4), API-only backend
- **Frontend**: Separate Vue.js repository
- **Auth**: Sanctum personal access tokens (bearer)
- **Architecture**: Multi-tenant with organization scoping
- **API**: RESTful API versioned at `routes/api/v1.php`

## Core Guidelines

### 1. OpenAPI Documentation Synchronization

**Critical Rule**: The `openapi.yaml` file is the source of truth for API contracts and **must be updated whenever**:

- New endpoints are created (GET, POST, PUT, DELETE, PATCH)
- Endpoint paths, parameters, or request/response schemas change
- Status codes or error responses are added/modified
- Authentication or authorization requirements change
- Request or response body structures are modified

See `.ai/rules/api.md` for detailed guidance.

### 2. Follow Existing Code Conventions

- Check sibling files before implementing new patterns
- Use descriptive variable and method names (e.g., `isRegisteredForDiscounts` not `discount()`)
- Reuse existing components and traits
- Follow the Laravel patterns defined in `.github/skills/laravel-best-practices/SKILL.md`

### 3. Testing Requirements

- Write feature tests by default; use `--unit` only for isolated logic
- Use factories from `database/factories/` when creating test models
- Run tests with `docker compose exec app php artisan test --compact`
- All new code must have corresponding tests

### 4. Code Quality

- Run Pint formatter before finalizing: `vendor/bin/pint --format agent`
- Follow Tailwind conventions for UI styling (see `.github/skills/tailwindcss-development/`)
- Use PHP 8 features: constructor property promotion, explicit return types, type hints

### 5. Database & Models

- Use multi-tenancy trait `App\Models\Concerns\BelongsToOrganization` for org-scoped models
- Always define complete `Fillable` attributes (including morph columns)
- Use attribute-based model configuration: `#[Fillable(...)]`, `#[Hidden(...)]`
- Global scopes and query builders properly respect organization boundaries

### 6. Docker Environment

- Always run Laravel commands via: `docker compose exec app <cmd>`
- Run npm/Vite commands on host (Node not installed in app container)
- Reference `.vscode/settings.json` for Laravel Artisan extension configuration

## File Organization

- **API Routes**: `routes/api.php` → `routes/api/v1.php`
- **Controllers**: `app/Http/Controllers/Api/V1/`
- **Policies**: `app/Policies/`
- **Models**: `app/Models/` (with `Concerns/` subdirectory for traits)
- **Tests**: `tests/Feature/` and `tests/Unit/`
- **Migrations**: `database/migrations/`
- **Factories**: `database/factories/`

## Review Checklist

Before marking a task complete:

- ✅ OpenAPI documentation updated (if API changes)
- ✅ Tests passing: `docker compose exec app php artisan test --compact`
- ✅ Code formatted: `vendor/bin/pint --format agent`
- ✅ Existing tests still passing (ask user to run full suite if needed)
- ✅ Database scoping and authorization checks in place
- ✅ Descriptive commit messages with clear intent
