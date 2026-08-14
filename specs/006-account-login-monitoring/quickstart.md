# Quickstart Validation Guide: مراقبة الدخول وأمان الحسابات

هذا الدليل يثبت الميزة بعد تنفيذها. لا يمنح إذناً لتشغيل migration أو backfill أو retention على `educore` أو الإنتاج.

## 1. Prerequisites

- PHP 8.0+ متاح عبر الأمر `php`.
- قاعدة اختبار معزولة ينتهي اسمها حرفياً بـ`_test`، ولا تكون نسخة production-like مستخدمة من المستخدمين.
- علامة اختبار صريحة خاصة بالميزة، تقترح الخطة `AUTH_MONITORING_TEST_MARKER=authentication-monitoring`.
- schema baseline مستعاد إلى قاعدة جديدة، ثم migrations الجديدة مطبقة عليها فقط.
- هويات اختبار منفصلة: طالب نشط، طالب معطل بسبب، طالب منقول/متخرج، عامل نشط متعدد الأدوار، عامل معطل، super_admin، وحساب تجريبي.
- Microsoft/Teams fixtures أو stubs محلية؛ لا تستخدم client secret/token حقيقي في الاختبارات.
- feature flags تبدأ observe-only، وthrottle/session enforcement off حتى نجاح الاختبارات السابقة لها.

## 2. Documentation and contract review

راجع بالترتيب:

1. [spec.md](spec.md)
2. [research.md](research.md)
3. [data-model.md](data-model.md)
4. [authentication contract](contracts/authentication-observability.md)
5. [plan.md](plan.md)

Expected:

- لا `[NEEDS CLARIFICATION]`.
- كل route حالي موجود في route matrix.
- كل مورد جديد له owner وredaction/retention/undo policy.
- لا production write مصرح به.

## 3. Static verification

بعد التنفيذ، شغّل من جذر المشروع عبر LeanCTX:

```powershell
C:/xampp/php/php.exe -l login.php
C:/xampp/php/php.exe -l logout.php
C:/xampp/php/php.exe -l classes/MicrosoftSSO.php
C:/xampp/php/php.exe -l classes/AccountListDataTableQuery.php
C:/xampp/php/php.exe -l admin/student_accounts.php
C:/xampp/php/php.exe -l admin/staff_accounts.php
```

ثم lint لكل ملفات PHP الجديدة/المعدلة في manifest التغيير، وليس مسحاً غير محدود.

Expected: `No syntax errors detected` لكل ملف.

## 4. Focused contract tests

الأسماء النهائية تثبت أثناء التنفيذ، والخطة تتوقع:

```powershell
C:/xampp/php/php.exe tests/authentication_outcome_contract_test.php
C:/xampp/php/php.exe tests/authentication_route_coverage_test.php
C:/xampp/php/php.exe tests/authentication_throttle_policy_test.php
C:/xampp/php/php.exe tests/account_last_login_datatable_contract_test.php
C:/xampp/php/php.exe tests/credential_lifecycle_contract_test.php
```

Expected:

- كل مسار دخول يستدعي outcome service مرة واحدة.
- لا يبقى زوج `Utilities::logAction(login)` + `ActivityLog::logLogin` لنفس المسار بعد cutover.
- reason/method/outcome allow-lists كاملة.
- لا endpoint يثق في role/user type من request.
- DataTables index/order/filter contracts متسقة في الطلاب والعاملين.
- لا reveal/export متاح عندما legacy flag off.

## 5. Guarded schema integration

اضبط الاتصال على قاعدة `*_test` والعلامة فقط، ثم شغّل migration harness المعتمد في المشروع.

Validate:

- clean install بالترتيب.
- re-run/idempotency حيث يتطلب migration runner ذلك.
- كل FK/index/unique/check يعمل على MariaDB الفعلي.
- resource policies موجودة قبل أول insert.
- rollback code path لا يحتاج DROP.

Expected: لا يتصل الاختبار بـ`educore`; أي اسم لا ينتهي `_test` يفشل قبل DDL/DML.

## 6. Authentication outcome scenarios

### Manual password

| Scenario | Expected outcome |
|---|---|
| valid active student with stage | one `success/authenticated/password`, state observed, usable session |
| valid student missing stage | one `denied/stage_missing`, no usable session |
| valid inactive student | one `denied/inactive`, current approved message after credential proof |
| valid graduated/transferred student | terminal reason, no session |
| valid active staff | one success, role session preserved |
| valid multi-role staff | login success then separate role-selected event if selection required |
| wrong password | one failure, generic response, no account enumeration |
| unknown username | pseudonymous failure, no raw username, generic response |
| event/state persistence failure | generic failure, no partial session |

### Microsoft and Teams

| Scenario | Expected outcome |
|---|---|
| linked interactive Microsoft | one success with interactive method |
| linked Teams silent | one success with Teams method |
| Microsoft ID present but email mismatch | denied identity mismatch, no relink |
| unlinked silent account | denied/unlinked and existing portal fallback |
| invalid/expired token | failure/error without token in logs |
| replayed callback/idempotency key | no duplicate final event or state regression |
| link or audit failure | session cleared per ADR-076 |

For every scenario, assert one final canonical event, at most one general activity row for known user, no secret fields, and compatible redirect/JSON/message.

## 7. Throttle validation

With a test-only clock and reduced thresholds:

1. Fail fewer than threshold attempts; expect no added delay.
2. Reach threshold; expect configured initial retry.
3. Continue failures; expect exponential delay capped at maximum.
4. Repeat from another source against same account; account limit still applies.
5. Spray one password across unknown subjects from one source; source limit applies.
6. Advance clock beyond window; retry allowed.
7. Succeed with correct credential; account consecutive count resets.
8. Run concurrent failures; final count equals attempts and never decreases.

Expected: no permanent `locked` state and no difference in public error revealing account existence.

## 8. State/backfill validation

Prepare legacy activity fixtures:

- known `login` duplicate rows for same request.
- `microsoft_login` and `microsoft_sso_login`.
- rows with and without request ID.
- user with no rows.
- event newer and event older than current projection.

Run:

```powershell
C:/xampp/php/php.exe tools/backfill_authentication_state.php --dry-run --database=<fixture_test>
```

Expected dry-run: counts/checksum only، no writes.

Run guarded apply, then run it again.

Expected:

- users with trusted maximum become `observed`.
- existing no-event users remain `unknown_historical`.
- new post-cutover no-event user is `never`.
- second run is a no-op or same projection/checkpoint.
- older source event never lowers last success.
- no attempt count/previous login invented from proximity.

## 9. Account UI validation

For both `student_accounts.php` and `staff_accounts.php`:

1. Load default list and measure baseline/feature-on duration.
2. Sort last login ascending/descending including NULL states.
3. Filter each history state, age bucket and method.
4. Combine existing stage/grade/class/role/status/config filters with login filters.
5. Verify test accounts excluded/included according to selected scope.
6. Toggle the new column in column settings and reload.
7. Open history modal as normal admin and super_admin.
8. Inject names/details containing HTML and confirm escaping.
9. Turn UI flag off and confirm current table works without shifted action columns.

Expected: exact Arabic states, exact local datetime, relative helper, no IP in table, and no N+1/correlated activity query.

## 10. Session validation

1. Open two sessions for one user from two fixture clients.
2. Confirm two registry rows and one account-level last login.
3. Send multiple requests/tabs within heartbeat interval; expect at most one update.
4. Revoke one session as super_admin; its next request ends, the other remains.
5. Revoke all sessions; all next requests end.
6. Attempt revoke as normal admin; expect 403/generic response and no write.
7. Disable account/reset credential; expect active sessions revoked.
8. Trigger idle timeout; expect safe session destruction and end reason when registry available.
9. Adopt a pre-rollout session during compatibility; expect `adopted_legacy=1`.
10. Turn registry flag off; expect native session behavior restored.

Never assert browser close as a confirmed logout.

## 11. Credential lifecycle validation

- Reset creates hash-only temporary credential and displays plaintext once.
- Later reveal/history/export never returns temporary plaintext.
- First login with temporary credential reaches change-only flow.
- Failed change keeps `must_change_password=1`.
- Successful change clears flag, increments version as designed, and revokes older sessions.
- Legacy login upgrades hash without losing access; ciphertext clears only after verified replacement transaction.
- Report reaches zero legacy before reveal flag is turned off permanently.
- No periodic-expiry behavior appears.

## 12. Retention validation

Prepare events at <30d, >30d and >180d.

1. Dry-run: expected redact/delete counts, no writes.
2. Apply in small batches with checkpoint.
3. Interrupt and resume.
4. Run again; idempotent.

Expected:

- recent event unchanged.
- >30d event retains non-network outcome but IP/client details null and `redacted_at` set.
- >180d event deleted.
- `authentication_user_state` remains.
- receipt contains counts/cutoffs/checksum only.
- no sensitive undo snapshot.

## 13. Leakage and authorization review

Search test DB rows, PHP logs, JSON captures and rendered HTML for fixture secrets:

```text
plaintext password
password hash fixture
Microsoft access/id/refresh token
authorization code
PHP session ID/cookie
CSRF token
client secret
raw unknown username
```

Expected: zero matches outside the test input fixture itself. Confirm masked IP and coarse user-agent summary.

## 14. Performance acceptance

Use an isolated synthetic fixture of 10,000 users and 1,000,000 events:

- Measure list query before/after one-to-one state join.
- Measure order/filter/summary paths.
- Measure login transaction under concurrent success/failure.
- Measure heartbeat write reduction.
- Explain query plans and index use.

Expected: visible list under two seconds in acceptance and <=15% regression from baseline; no full event scan for normal list.

## 15. Project gates

After focused tests:

```powershell
composer audit-write-coverage
composer architecture-audit
composer quality
git diff --check
```

Also run the existing Microsoft SSO, student login policy, account DataTables, audit, session, admin-page asset and role contract tests affected by the diff.

Expected:

- `AUDIT_REVIEW_REQUIRED=0`.
- architecture audit passes without baseline expansion.
- quality passes.
- no unrelated or generated artifacts in diff.

## 16. Rollback rehearsal

In the test environment, disable in reverse order:

1. credential legacy retirement.
2. session registry.
3. throttle enforcement.
4. history UI.
5. observability wiring.

Expected: current login routes/messages/redirects/DataTables continue working; additive tables remain; no DROP or data restoration is needed. Re-enable and confirm no state regression or duplicate events.

## 17. Production stop gate

Do not proceed to production if any of the following is unresolved:

- no verified backup/restore proof;
- schema/migration state unknown;
- web/session topology unknown;
- trusted proxy policy unknown;
- data-view/retention owner approval absent;
- dirty-worktree overlap unresolved;
- event persistence or route coverage test failing;
- any test writes to `educore`;
- baseline expansion proposed only to obtain green status.
