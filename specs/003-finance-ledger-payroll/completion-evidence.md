# Completion Evidence — 003-finance-ledger-payroll

**Branch**: `feature/003-finance-ledger-payroll`

**Worktree**: `C:\tmp\EduCore-finance`

**Last updated**: 2026-07-28

**Rollout status**: after explicit user authorization, the Finance-only delta was merged non-destructively into dirty local `main`, `.env` was set to `FINANCE_LEDGER_MODE=display`, and the 11 Finance migrations were applied to `educore`. No push was performed.

Only tasks with implementation and current evidence are marked `[x]`. All implementation, quality, rollback, and staged-diff gates are accepted.

## Acceptance evidence

| Area | Evidence | Result |
|---|---|---|
| Full Finance suite | `php tests/run_finance_suite.php --database=educore_finance_test` after isolated reset/migrations | `FINANCE_TEST_FILES=78 FAILED=0` |
| Test DB refusal | Suite and required integration tests reject missing/non-`*_test` database names | No accepted skips |
| Full quality gate | `composer quality` in the integrated local `main` worktree | Exit code `0` |
| PHP syntax | Live-main Composer lint in final `composer quality` after authenticated runtime fixes | `Checked 1078 PHP files; 0 failure(s)` |
| Architecture | `composer architecture-audit` | `ARCHITECTURE_AUDIT_REGRESSIONS=0` |
| Write/audit coverage | `composer audit-write-coverage` | `AUDIT_REVIEW_REQUIRED=0` |
| Upload policy | `composer upload-policy-audit` | `UPLOAD_POLICY_ERRORS=0` |
| Finance pages | role-footer + auth/CSRF contracts | 30 pages pass |
| Legacy finance adapters | compatibility contract + fee/collection/staff integration tests | 9 entrypoints preserve their public contract; Finance writes delegate to Finance services |
| Transport ownership | `finance_transport_assignment_archive_integration_test.php` | assignment writes use the Transport service; unassignment archives; failed bulk updates roll back |
| Posted discounts | `finance_posted_discount_ledger_integration_test.php` | post-charge discount creates one credit adjustment, one sub-ledger transaction, and one balanced GL journal |
| Server-side paging | `finance_server_side_pagination_integration_test.php` | 8 high-volume views pass |
| Period lifecycle | `finance_period_lifecycle_integration_test.php` | close/reopen maker-checker and closed-period refusal pass |
| Manual journal | `finance_manual_journal_integration_test.php` | balance, control-account refusal, maker-checker, linked reversal pass |
| Sensitive approvals | `finance_sensitive_approval_coverage_contract_test.php` | 20 operations covered |
| Prior-year debt | `finance_prior_year_debt_migration_test.php` | opening-balance migration pass |
| Rollback | `finance_rollback_drill_integration_test.php` | linked sub-ledger/GL reversal returns to zero |
| DataTables endpoint | `admin/finance_datatable.php` | authenticated CSRF-protected database paging |
| Documentation | architecture, database, project memory, ADR, spec/plan/quickstart | synchronized |
| Recovery backup | `C:\tmp\EduCore-rollout-backup-20260728_081336` | 33,186,068-byte SQL backup restored to `educore_rollout_restore_test`; 216/216 tables; SHA-256 verified |
| Default Finance configuration | `20260728_finance_default_configuration.php` + focused integration test | 13 accounts, `MAIN` cashbox, one open period, 19 mappings, four control accounts; idempotent |
| Live schema/config | read-only verification against `educore` after migration | migration 1/1, accounts 13, cashbox 1, open periods 1, active mappings 19, controls 4, resolved operations 19 |
| Runtime DataTables bootstrap | Apache evidence + `finance_server_side_pagination_integration_test.php` | endpoint now authenticates, initializes PDO, then constructs the Finance service factory; all eight paged views pass |
| Finance sidebar discoverability | `finance_sidebar_navigation_contract_test.php` | 29 operational pages exposed in five labeled groups; every target exists |
| Authenticated browser smoke | Chrome admin session + Apache access/error logs | 37 authenticated routes rendered without PHP fatal/warning output; four common non-Finance pages, all Finance groups, legacy compatibility pages, and official statements checked |
| Runtime DataTables | Receipts, payroll runs/items, staff ledger, vouchers, journal, and audit log | all post-fix `finance_datatable.php` requests returned HTTP 200; no DataTables alert remained |
| Compatibility privacy | `FinanceLegacyAdapter::bridgeNotice()` + focused contract | internal `off`/`shadow`/`display`/`execute` mode is never rendered in user-facing pages |
| Browser console | post-fix Chrome log inspection | no new error/warning after removing the conflicting academic-year Tooltip and legacy jQuery tooltip call |
| Student statement photo | authenticated `statements.php` smoke | authorized `profile_attachment.php` controller used; no direct `uploads/private` URL; compatibility notice absent |

## Implemented surfaces

- 30 authenticated Finance admin PHP surfaces, including dashboard, fee plans, student accounts/ledger/debts, receipts/refunds, discounts/awards, buses, staff contracts/ledger/advances, payroll runs/items/payments/payslips, cashboxes, vouchers, journal, budgets, reports, archive, import/export, approvals, periods, export controllers, and the paging endpoint.
- Generic signed student/staff sub-ledger plus balanced GL and control-account reconciliation.
- Versioned fee plans and student contracts; discounts; collection/allocation/unapplied credit/refunds; payroll/advances; vouchers/budgets; archive/import/export/reporting.
- Mandatory maker-checker workflow for receipt reversal, refunds, write-offs, manual journals, imports, payroll operations, period close/reopen, manual discounts, and voucher posting/reversal.
- Additive dated migrations and isolated data-migration/reconciliation/rollback tooling.

## Runtime rollout verification

The authorized local rollout is installed, migrated, and authenticated-browser tested. Runtime findings were fixed and rechecked: server-side Finance tables return HTTP 200, no compatibility-mode notice is exposed, no new browser console error remains, and representative non-Finance workflows continue to render.

No remaining item is recorded as complete merely because a file exists or a test was skipped.
