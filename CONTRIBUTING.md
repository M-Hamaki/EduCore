# Contributing to EduCore

Thank you for helping improve an Arabic-first, self-hosted school management platform.

## Development setup

1. Install PHP 8.0+ with PDO, JSON, cURL, fileinfo, OpenSSL, mbstring, and DOM extensions.
2. Install MySQL or MariaDB and create an isolated development database.
3. Clone the repository and install dependencies:

   ```bash
   git clone https://github.com/M-Hamaki/EduCore.git
   cd EduCore
   composer install
   cp .env.example .env
   ```

4. Configure `.env` for the local database and application URL. Never commit `.env` or real credentials.
5. Apply the documented migrations using the project migration runner. Do not run write-enabled tests against a production-like database.

## Branches and pull requests

- Create a focused branch from `main`, for example `feature/short-description` or `fix/short-description`.
- Keep one coherent concern per pull request.
- Preserve existing URLs, form names, session keys, permissions, SQL contracts, and compatibility entrypoints unless the change explicitly documents a migration.
- Explain data-safety, rollback, security, and deployment effects in the pull request description.
- Do not include production uploads, backups, database dumps, environment files, generated dependencies, or personal data.

## Coding standards

- Read `AGENTS.md` and the relevant documents in `docs/` before changing code.
- Follow the existing modular-monolith boundaries: presentation → application → domain/contracts; infrastructure implements contracts.
- Reuse existing services, validators, repositories, audit services, and UI primitives before adding new ones.
- Use centralized CSRF, authentication, authorization, upload, audit, draft, undo, and modal patterns.
- Escape user-controlled output and validate real file MIME types and sizes through the shared upload guard.
- Add focused contract tests for protected workflows and update architecture documentation when boundaries or public contracts change.

## Validation

Run the checks relevant to the change, and include their results in the pull request:

```bash
composer validate --strict
composer quality
composer security-audit
php tests/microsoft_sso_environment_test.php
php tests/super_admin_hierarchy_contract_test.php
git diff --check
```

Some integration tests require an explicitly isolated database. Follow their guards and documentation; never bypass them to make a check pass.

## Security requirements

Use synthetic data only. Do not report vulnerabilities publicly; follow [SECURITY.md](SECURITY.md). Rotate any credential that is accidentally exposed and notify the maintainer privately.

## Bug reports and feature proposals

Use a minimal reproduction, expected behavior, actual behavior, environment details, and test data that contains no personal information. For feature proposals, describe affected roles, permissions, routes, data writes, audit/undo behavior, and rollback.
