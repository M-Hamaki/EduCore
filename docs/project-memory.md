# Project Memory

This file is a compact, public memory for future contributors and AI-assisted development sessions. It records confirmed project facts without deployment-specific paths, domains, accounts, contact details, or production data.

## Mission

EduCore is a pragmatic modular monolith for school administration and teaching workflows. It serves administrators, teachers, students, specialists, supervisors, external teachers, and operational staff while preserving compatible role entrypoints during incremental refactoring.

## Stack and boundaries

- PHP 8.0+ with PDO and MySQL/MariaDB.
- Bootstrap RTL, Font Awesome, DataTables, Composer packages, and vanilla/jQuery JavaScript where already established.
- Presentation entrypoints validate requests and delegate to application services.
- Application services apply domain policies through contracts and infrastructure adapters.
- Domain code must not depend on HTTP, PDO, rendering, or another module's private internals.
- Database schema changes belong in `database/migrations/`.
- `src/`, `classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, and `storage/` are internal and must not become new public web roots.

## Confirmed security baseline

- Secrets and deployment configuration are loaded from `.env`; `.env.example` contains placeholders only.
- Authentication, authorization, sessions, CSRF, SSO token validation, upload validation, private storage, audit, and undo are protected workflows.
- Student and staff attachments use private storage and authorized download controllers.
- Uploads are checked by the shared guard for dangerous names, real MIME type, byte limits, unpredictable storage names, authorization, and file/database rollback.
- State-changing operations use the shared audit architecture and must remain transactionally consistent where possible.
- Tests that write data require an explicitly isolated test database and must reject production-like database names.

## Public configuration model

- `APP_URL` is the authoritative deployment URL; `SITE_URL` is a compatibility alias.
- `ORGANIZATION_NAME`, `SUPPORT_EMAIL`, `SUPPORT_PHONE`, and optional integration URLs identify a deployment without changing the source tree.
- Microsoft SSO and Teams use configured redirect URIs, client IDs, tenants, and application ID URIs. Generic development examples are documented in `.env.example`.
- `INITIAL_SUPER_ADMIN_USERNAME` is optional; the bootstrap migration is a no-op when it is empty.

## Verification

Before a meaningful change is complete, run the focused tests and the relevant quality gates:

```bash
composer validate --strict
composer quality
composer security-audit
git diff --check
```

Review security, role boundaries, public contracts, data effects, rollback, and documentation alongside test output. Do not treat a passing heuristic audit as proof that behavior is secure.

## Known constraints

- Existing legacy pages remain compatible HTTP entrypoints while responsibilities are extracted incrementally.
- Some large legacy pages and older documentation require staged cleanup; do not expand an audit baseline merely to hide a finding.
- Deployment-specific privacy notices, terms, Teams manifests, domains, and support contacts must be customized through configuration or local ignored files.

## Local-only information

Machine paths, local PHP executables, database names, acceptance environments, private storage inventories, and operator notes belong in the ignored `docs/project-memory.local.md` file or in the operator's private documentation. They must not be committed to the public repository.
