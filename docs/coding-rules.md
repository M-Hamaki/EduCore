# Coding Rules

Purpose: short checklist for future edits. Root `AGENTS.md` remains authoritative.

- For admin UI work, `admin/ui_preview.php` is the canonical visual and structural reference.
- Centralized project styles and existing component classes take precedence over generic frontend-design guidance.
- Free-list pages use `admin-filter-bar` and `admin-list-surface`; only explicit functional tools may use card-wrapped tables and inline card-header filters.

## Scope Discipline

- Do not modify unrelated files.
- Do not modify migrations unless a schema change is explicitly requested.
- Do not delete existing files.
- Prefer existing patterns over new abstractions.
- Mark unknown implementation details as `Not confirmed yet`.

## Architecture Change Gate

Use [`docs/ai-change-checklist.md`](ai-change-checklist.md) for the full workflow; this section is only the minimum gate.

- Search existing code, callers, docs, and tests before creating a helper, service, validator, or parallel abstraction.
- Keep module ownership explicit and dependencies flowing Presentation → Application → Domain/contracts; Infrastructure implements contracts, and modules do not reach into each other's internals.
- Change schema only through versioned migrations; runtime DDL in requests or application startup is forbidden.
- Verify whether each affected path is a required public entrypoint or an internal file, and keep internal boundaries deny-by-default without blocking confirmed endpoints.
- Define focused tests and a practical rollback before implementation; record an ADR when boundaries, shared contracts, public surface, data ownership, or a lasting exception changes.
- Run `composer architecture-audit` for PHP, web-boundary, audit-tool, or baseline changes.
- Protect pre-existing dirty worktree changes and never stage or commit unrelated/user-owned files.
- Treat the architecture baseline as a ratchet: do not expand it to pass a regression without a separate reviewed architectural decision, and remove entries when their violations are fixed.

## Required Auth Pattern

Protected admin pages must validate before POST/GET/AJAX work:

```php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
```

Use the corresponding role for teacher/student/specialist pages.

## Security

- Read secrets with `env('KEY', 'default')`; never hardcode secrets.
- Use CSRF tokens from `includes/session_config.php`.
- Verify CSRF with `hash_equals()`.
- Escape HTML output with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
- Do not enable `ini_set('display_errors', 1)` in production files.
- Use `error_log()` for server-side logging.

## State-Changing Requests

- Use PRG after state-changing POST operations.
- Store feedback in session messages before redirect.
- Preserve tab state with `active_tab` and `Utilities::buildQueryString()` where tabs exist.
- Log CRUD/status/settings/import/export operations with `ActivityLog`.
- Use `UndoManager` where undo behavior is expected.
- **Adding a new field to a student/staff form** requires updating 5 points: DB column → save handler → `get_*_activity_snapshot()` SELECT → `build_*_activity_details()` `$tracked` array → `ActivityLog::getDetailKeyLabel()` Arabic label. See `AGENTS.md` → "Activity Log — Field Tracking System" for the full workflow.
- Each entity page's activity log shows ONLY that page's form operations (e.g. `students.php` logs `target_type='student'` only; `staff.php` logs `target_type='staff'` only — NOT account/financial types from sibling pages).
- `build_*_activity_details()` must return `null` when nothing changed; callers must skip logging in that case.
- Use `formatDetailsHtml($details, 'diff_table')` for entity-page log tabs (renders الحقل/قبل/بعد).

## Assessment Engine Rules

- New grading/reporting work should use `classes/AssessmentEngine.php` and the `assessment_*`, `report_windows`, and `published_reports` tables.
- Do not add new behavior to legacy `grade_columns` / `student_grades` flows unless explicitly restoring archived functionality.
- Teacher mark entry must stay scoped to the teacher's assigned subjects, grades, and classes.
- Mark input must reject letters except allowed grading absence values such as `غ` / `abs` and configured excused absence values.
- Do not connect grading absence statuses to the attendance module; they are separate workflows.
- Published student reports should keep the old visual spirit while reading from `published_reports` data.
- Report snapshots must be scoped to active enrolled students for the report academic year.
- Mark/review views should use current `student_enrollments` class scope and synchronize `class_id_at_entry` when grades are touched after a class transfer.
- Teacher-facing report publishing/readiness counts must be limited to the teacher's allowed class/scope.
- Student-facing links to grade reports should target `student/reports/published_reports.php`, not legacy report launchers.
- Assessment schema changes must keep a new follow-up migration compatible with already-created partial tables via guarded `ALTER TABLE` columns; current compatibility files are `database/migrations/20260629_assessment_engine_compatibility.php` and `database/migrations/20260630_assessment_published_reports_compatibility.php`.
- Calendar work should use `academic_months` as the parent of `academic_weeks`; keep `month_label` only as a compatibility/display fallback.

## UI Standards

- Button styles are controlled by `assets/css/buttons.css`.
- Admin UI unification lives in `assets/css/admin-unified.css`, loaded after `buttons.css`.
- Use opt-in `admin-*` classes for migrated pages instead of broad CSS overrides.
- Do not add custom button CSS in page `<style>` blocks.
- Do not use SweetAlert or browser `confirm()`.
- Use Bootstrap 5 modals for confirmation flows.
- Table action buttons must be icon-only `btn-sm` buttons with tooltips.
- Admin/teacher pages should use shared header/footer includes.
- DataTables use `table table-hover table-striped`.
- Unified admin tables should show subtle vertical separators between columns via `admin-data-table`.
- Stat cards should use meaningful varied `--card-gradient` colors, not a uniform or pale single-color treatment across all cards.
- Free list pages should use `admin-filter-bar` above the table and `admin-list-surface` / `admin-table-wrap` around the table; do not wrap these list tables in `card shadow` or `table-card` unless the page intentionally uses a card workflow.
- Free list filter buttons such as reset, table settings, and search should use `btn btn-light btn-sm` to match the student list reference.
- Do not add tabs to pages that do not already use tabs unless explicitly requested.
- Import buttons use `btn-header-premium btn-import-soft`; Excel export buttons use `btn-header-premium btn-export-soft` and the label `تصدير Excel`.
- Print buttons use the label `طباعة` with `btn-print-soft`; PDF export buttons use the label `PDF` with `btn-pdf-soft`.
- During UI unification, do not change field `name`, element `id`, form `action`, hidden inputs, CSRF fields, POST handlers, or SQL queries unless fixing a separate confirmed bug.
- Unified modals should use `admin-modal` plus one type class: `admin-modal-create`, `admin-modal-edit`, `admin-modal-delete`, `admin-modal-warning`, or `admin-modal-view`.

## Frontend Behavior

- Use `assets/js/main.js` for global CSRF behavior.
- Use `assets/js/admin_table_actions.js` for table exports and column settings.
- Column settings should apply immediately and persist to `localStorage`.
- DataTables action-return state is owned only by `assets/js/datatable-state.js`: it restores page, length, search, and ordering once after a same-page `POST` or a full-page row-action journey, then deletes all linked aliases. Normal navigation and manual refresh start from defaults.
- Standard semantic row-action links (`btn-action-pills` or `action` plus an entity ID) are captured centrally for up to 30 minutes; use `data-datatable-return="true"` only for a nonstandard documented action and `false` to opt out an exceptional link.
- Row actions must expose a stable entity identity. The shared asset can resolve an approved ID query parameter, while server-side presenters should also emit `data-{entity}-id`; modal forms outside the row use `data-datatable-return-table` plus `data-datatable-return-row-field`.
- After returning, the shared asset restores, conditionally scrolls to, focuses, announces, and temporarily highlights the row; if the row no longer matches, it retains the table page without guessing.
- Direct DataTables pages must load the shared state asset before initialization; use a stable table `id` or `data-table-state-key`, and use `data-datatable-return-state="false"` only with a documented tested exception.
- Do not add page-local `stateSaveCallback` / `stateLoadCallback`; run `composer datatable-state-audit` after DataTables or asset-loading changes so both the PHP inventory and JavaScript behavior test execute.
- Do not add a reset-display button for the one-shot bridge; there is no persistent table state to clear.
- AJAX row mutations are explicit progressive enhancements through `data-datatable-ajax`; keep the native PRG fallback and reuse the same backend write/audit/CSRF path. Do not opt in uploads, imports, batch, finance, or external-effect flows without a dedicated contract and tests.
- Use `table.ajax.reload(callback, false)` after AJAX mutations, restore the row by stable identity, and update affected summary elements; reset paging when a filter changes the result set.
- After AJAX data changes, update affected UI dynamically instead of using `window.location.reload()` unless the full page structure truly changes.

## Data Validation

- National ID fields: digits only, exactly 14 digits.
- Mobile fields: digits only, exactly 11 digits.
- Landline fields: digits only, no fixed length confirmed.
- `أخرى` / `other` select options must reveal and persist a custom text input.

## File Uploads

- Follow `docs/file-upload-standard.md` and classify every new upload path in `tools/upload_policy_manifest.json`.
- Use `FileUploadGuard`, random stored names, relative database paths, and `APP_URL` only for external absolute links.
- Keep sensitive attachments private and preserve file/database rollback order for create, replace, and delete.
- Run `composer upload-policy-audit`; do not weaken markers or inventory merely to pass the gate.

## LeanCTX Workflow

- Reuse `docs/project-memory.md` and `docs/architecture.md` before scanning.
- Use targeted LeanCTX reads/searches.
- Avoid full repository rescans unless the task truly requires them.
