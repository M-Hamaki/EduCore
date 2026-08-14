# UI Centralization Audit

## Purpose

Centralize recurring admin UI styling without changing page data, form contracts, or JavaScript behavior.

## Confirmed load order

1. Bootstrap RTL and DataTables vendor CSS
2. `assets/css/style.css`
3. `assets/css/premium-dashboard.css`
4. `assets/css/buttons.css`
5. `assets/css/admin-unified.css`

## Ownership model

| Area | Owner | Notes |
| --- | --- | --- |
| Application shell, sidebar, header, responsive layout | `style.css` | Shared foundation only. |
| Statistics cards, dashboard cards, dashboard visuals | `premium-dashboard.css` | Use semantic `stat-card` structure. |
| All buttons | `buttons.css` | Do not define button colors in other files. |
| Admin list surfaces, filters, tables, pagination, admin modals | `admin-unified.css` | Opt-in through `admin-*` classes. |

## Confirmed risks

1. Legacy broad selectors in older CSS can affect pages that have not migrated yet.
2. `premium-dashboard.css` contains visual rules that must remain scoped to migrated list/card surfaces.
3. Page-level CSS must be deleted only after the central replacement is active and the page is functionally tested; remaining local styles are page-specific layout/print rules.
4. Visual regression checks still depend on exercising authenticated workflows in the browser after major layout changes.

## Completed baseline cleanup

- Moved the admin header search-result and tab-weight styles out of `includes/admin_header.php` into `assets/css/admin-unified.css`.
- Scoped migrated DataTables rules to `admin-list-surface`.
- Scoped migrated card-header rules to `admin-card-surface`.
- Added the opt-in `admin-modal-premium` skin to `admin-unified.css` and applied it to every modal in `admin/ui_preview.php` without changing modal behavior.
- Standardized modal cancel/close controls centrally in `assets/css/buttons.css` to the gray `btn-secondary` appearance approved in `admin/ui_preview.php`; destructive red styling remains reserved for the actual delete/reject action.
- Consolidated all `btn-action-pills` visual ownership in `assets/css/buttons.css`; duplicate action-button rules were removed from `premium-dashboard.css` and `admin-unified.css`.
- Added a legacy-modal promotion pass in `assets/js/main.js` so remaining old markup receives the central modal skin, operation color, close-button treatment, and gray cancel action before draggable behavior initializes.
- Removed all page JavaScript that rewrote modal-header classes in `bus_staff.php`, `external_teachers.php`, `materials_center.php`, and `notifications.php`.
- Added the missing `toggleMaterialModal` required by the existing material visibility buttons and POST toggle handler.
- Migrated every static admin modal to `admin-modal admin-modal-premium` with an explicit operation type and removed legacy header/cancel classes from the PHP source.
- Removed all local `.stat-card*` and button-component definitions from admin pages; statistic cards now rely on `premium-dashboard.css`, and table actions rely on `buttons.css`.
- Removed the modal prototype `<style>` block from `admin/ui_preview.php`, so the permanent reference page now validates the actual central CSS.
- Added numeric stat-card compatibility and suffix/decimal preservation to `premium-dashboard.js`.
- Added `tools/audit_admin_ui.php`; the current baseline is `ADMIN_PHP_FILES=117` and `UI_AUDIT_ISSUES=0`.

## Migration gate for every page

1. Preserve form names, IDs, endpoints, hidden inputs, CSRF, queries, and existing behavior.
2. Add the approved `admin-*` classes and remove only equivalent local CSS.
3. Verify add, edit, delete, toggle, filters, search, pagination, table settings, import/export, and print when present.
4. Run PHP lint and `git diff --check`.
5. Record removed selectors and any remaining page-specific styles.

## Rollout order

1. Verify the UI preview against the approved reference, then remove only the now-equivalent local modal selectors.
2. Students: `students.php`, transfers, graduates, accounts, and dependent lists.
3. School structure: stages, grades, classes.
4. Subjects, assessments, and reports.
5. Staff, accounts, attendance, transport, and services.
6. Final dead CSS/JS sweep after each module is verified.

## Students baseline

`admin/students.php` is the first rollout page.

- The main list already uses `admin-filter-bar`, `admin-list-surface`, `admin-table-wrap`, and `admin-data-table`.
- The page has no local style block to migrate for its list surface.
- Its list and activity-log tabs are real application sections and must remain.
- Its delete, status, table-column settings, and Excel import modals now use the opt-in central premium modal skin; their existing form contracts and JavaScript remain unchanged.
- The remaining visual migration target is the profile-form and secondary list modals, which will be migrated in small verified batches.
- `admin/transferred_students.php` delegates to `students.php`, so it inherits the same centralized list and modal treatment without a duplicate implementation.
- `admin/graduate_students.php` already uses the centralized free-list structure and has no page-owned modal markup.
- `admin/student_accounts.php` now uses the centralized list structure and premium modal skin for services, credentials, and status actions.

## Completed school-structure modal migration

- `admin/stages.php`, `admin/grades.php`, and `admin/classes.php` already use the centralized free-list structure.
- Their add, edit, view/manage, delete, status, import, and table-settings modals now use `admin-modal admin-modal-premium` with the appropriate operation type.
- Dynamic status actions now switch only the central modal operation class, preserving their existing IDs, hidden fields, form actions, and submission behavior.

## Completed assessment-assignment modal migration

- `admin/assessment_subject_assignments.php` now uses the premium modal skin for subject assignment create, edit, status, delete, and details actions.
- `admin/assessment_teacher_assignments.php` now uses the same skin for teacher-assignment editing and table-column settings.
- The grouping and aggregation logic for subject-to-grade/class assignments was deliberately left unchanged; this batch is visual-only.

## Completed subjects-and-calendar migration

- `admin/subjects.php` and `admin/evaluation_types.php` now use the premium modal skin for their list actions; dynamic status changes preserve the central modal operation classes.
- `admin/assessment_calendar.php` uses the premium modal skin across term, month, week, copy, delete, and status actions.
- The local stage-list CSS and evaluation-type card CSS were removed after moving their equivalents to the central CSS layers.

## Completed staff-account and staff-list migration

- `admin/staff_accounts.php` now uses the premium modal skin for credential editing and account status actions.
- The list-action modals in `admin/staff.php` now use the premium modal skin for delete, import, column settings, and cell-content viewing.
- The detailed staff-profile form and its dedicated local styles remain intentionally outside this batch and require a separate migration gate.

## Incremental specialized-page cleanup

- `admin/leaves.php` now uses the premium delete modal and no longer redefines global stat-card styles locally.
- Its leave-policy and in-page editor remain a separate page-layout migration because they are functional work surfaces rather than a free-list table.
- `includes/widgets/hr_stat_cards.php` now emits sortable dashboard containers and stable card IDs, so HR statistic cards keep their drag order through the existing dashboard engine.
- `assets/js/dashboard_sortable.js` now also promotes standalone statistic-card rows into sortable dashboards automatically, excluding modal content and already-managed dashboards.
- `admin/bus_lists.php` now uses the premium table-settings modal; its existing KPI row is automatically covered by the shared sortable-stat-card behavior.
- `admin/buses.php` now uses the premium modal skin for table settings, capacity information, add/edit, delete, status, and import actions; its status and add/edit scripts change central modal operation classes instead of Bootstrap header colors.
- `admin/staff_attendance.php` now uses the premium delete modal and relies on centralized statistic-card styling.
- `admin/biometric_devices.php` now uses central operation classes for device, result, status, and delete modals without changing device actions.
- `admin/training_programs.php` now uses animated counters and central create/edit/delete modal classes while retaining its program-specific list layout.
- `admin/training_courses.php` now uses central create/edit classes for course, unit, and question forms, plus central view and delete modals. Its JavaScript no longer rewrites Bootstrap modal-header colors, so dragging and operation changes retain the approved modal geometry.
- `admin/academic_years.php` now uses the central edit modal treatment; its confirmation fields, validation, CSRF token, and warning content remain unchanged.
- `admin/assessment_permissions.php` now uses central create, edit, warning/success, and delete modal classes. The existing role/scope fields and activation logic remain intact.
- `admin/assessment_components.php` and `admin/assessment_component_week_rules.php` now use central create, edit, status, and delete modal classes; their dynamic status scripts no longer style close buttons locally.
- `admin/assessment_windows.php` now uses central modal operation classes for single and batch creation, editing, status changes, and deletion. Window scope fields and submission actions remain unchanged.
- `admin/assessment_schemes.php` now uses central create, edit, status, and delete treatments across plan creation, copying, template application, activation, and deletion.
- `admin/biometric_devices.php` now uses the premium modal skin for device editing, delete, result, unlink, and users actions; dynamic result states select central modal operation classes.

## Completed subjects and calendar modal migration

- `admin/subjects.php` and `admin/evaluation_types.php` now use the premium modal skin for their create, edit, delete, import, settings, and status workflows.
- `admin/assessment_calendar.php` now uses it for term, month, and week create/edit/copy/delete/status workflows while preserving the active-tab form targets.
- Student table-column preferences use existing localStorage behavior and must remain unchanged during visual migration.
