# Contract: Authentication Observability, State, and Session Control

## 1. Contract purpose

يوحد هذا العقد كل مسارات المصادقة الحالية من دون تغيير URLs أو الحقول أو مفاتيح الجلسة. صفحات/مسارات HTTP تبقى adapters؛ قرار الأهلية يبقى عند مالكه الحالي؛ تسجيل النتيجة والحالة والجلسة يمر عبر خدمة واحدة وتدقيق مشترك.

## 2. Canonical vocabulary

### Methods

```text
password
microsoft_interactive
microsoft_silent
teams_silent
```

### Outcomes

```text
success   # authentication and usable session completed
failure   # authenticator/identity proof failed
denied    # identity proven but account/access policy denied entry
error     # internal/external dependency prevented a reliable result
```

### Reason codes

```text
authenticated
invalid_credentials
unknown_subject
inactive
graduated
transferred
discontinued
identity_unlinked
identity_mismatch
identity_ambiguous
invalid_token
expired_token
stage_missing
role_unavailable
throttled
audit_persistence_failed
session_init_failed
session_revoked
idle_timeout
credential_changed
account_disabled
unexpected_error
```

إضافة reason جديد تحتاج test + mapping + redaction review. لا يسمح بكود مشتق من exception message أو user input.

## 3. Application service contract

### `AuthenticationOutcomeService::recordFinalOutcome()`

**Input**:

```text
requestId: 32-char lowercase hex
idempotencyKey: optional bounded string/hash
userId: positive integer or null
subjectIdentifier: optional raw input, consumed only to create keyed hash and never persisted/logged
method: canonical method
outcome: canonical outcome
reasonCode: canonical reason
primaryRole: server-resolved role or null
activeRole: server-resolved role or null
clientContext:
  remoteAddress: server-derived
  trustedForwardedAddress: only after trusted-proxy validation
  userAgent: consumed for hash/coarse summary; never persisted raw
sessionId: optional raw current PHP ID, consumed only for HMAC; never persisted/logged
occurredAt: injectable clock, UTC
details: allow-listed scalar metadata only
```

**Output**:

```text
eventId: positive integer
state:
  historyStatus
  lastSuccessAt
  previousSuccessAt
  lastMethod
throttle:
  allowed
  retryAfterSeconds
  triggeredAlert
sessionIntentId: optional
```

**Guarantees**:

- Final outcome is idempotent by key/request contract.
- Known-user event, state update, and session intent are atomic where applicable.
- One known-user summary row is visible in shared activity log; no duplicate legacy call is allowed.
- Secrets are redacted before SQL and before exception/log context.
- A success that cannot be persisted throws a controlled exception; caller clears partial session and returns generic failure.
- Failure/denied recording errors never change a denial into success.

### `LoginThrottlePolicy::decision()`

**Input**: user state (if known), source throttle state, current UTC time, configured thresholds.

**Output**:

```text
allowed: bool
retryAfterSeconds: non-negative integer
nextFailureCount: integer
nextThrottleUntil: UTC or null
alert: none|threshold_reached|distributed_pattern
```

**Rules**:

- Pure deterministic policy; no HTTP/PDO/session dependencies.
- Enforcement uses account and source decisions; the stricter retry applies.
- No permanent lock transition.
- Successful authentication resets the authenticator-related consecutive count after outcome persistence.

### `AuthenticationSessionService`

```text
start(userId, sessionId, method, roles, clientContext, expiresAt) -> sessionRecord
adoptLegacy(userId, sessionId, roles, clientContext, expiresAt) -> sessionRecord
heartbeat(userId, sessionId, now) -> no-op|updated|revoked
end(userId, sessionId, reason, now) -> ended state (idempotent)
revoke(actorId, targetUserId, registryId|all, reason) -> affected count
revokeForCredentialChange(targetUserId, credentialVersion) -> affected count
revokeForAccountDisable(targetUserId) -> affected count
```

**Guarantees**:

- Raw session ID exists only in process memory long enough to derive keyed hash.
- `heartbeat` never revives ended/revoked sessions and writes at most once per configured interval.
- `revoke` requires server-side authorization before calling service and writes shared audit evidence.
- Credential/account revocation is idempotent and safe under concurrent requests.

## 4. Route instrumentation matrix

| Existing route/owner | Required canonical call | Compatibility notes |
|---|---|---|
| `login.php` password POST | one final outcome after password + policy + stage/session readiness | preserve fields, CSRF, messages, redirects, session keys |
| `classes/user.php::login()` | return verification/policy decision; no final logging | keep legacy signature until callers migrated |
| `auth/microsoft_callback.php` | one interactive outcome | remove second duplicate utility log after shadow proof |
| `classes/MicrosoftSSO.php::loginUser()` | delegate link/policy/session intent; no route-specific action names | preserve ADR-076 fail-closed behavior |
| `auth/teams_sso.php` | one Teams result | preserve fallback portal and video policy |
| `auth/teams_token_handler.php` | one silent Teams result | preserve JSON status and no raw token logs |
| `select_role.php` | `role_selected` event | does not update last login timestamp |
| `logout.php` | end current session + `logout` | preserve redirects and external-teacher exclusion |
| idle timeout path | end registry as `idle_timeout` when user/session known | absence of event must not block safe session destruction |
| account disable/reset owners | revoke active sessions | use existing write owner, transaction/outbox decision documented |

## 5. Admin list read contract

### Inputs added to both DataTables endpoints

All current inputs remain unchanged. New optional arrays/scalars:

```text
login_state[] = observed|never|unknown_historical
last_login_age[] = today|7d|30d|90d|stale_90d
login_method[] = password|microsoft_interactive|microsoft_silent|teams_silent
active_session = any|yes|no
```

Invalid values are ignored or rejected consistently with existing filter policy; they never become SQL fragments.

### Row projection added

```text
history_status
last_success_at_utc
last_success_method
active_session_count
```

The presenter returns escaped HTML only. Exact datetime is in visible/tooltip text, relative text is secondary. No IP/device in list rows.

### Display states

| State | Display |
|---|---|
| `observed` + timestamp | local exact datetime + relative helper + method badge |
| `never` | «لم يسجل دخولاً» |
| `unknown_historical` | «لا توجد بيانات تاريخية موثوقة» |
| feature flag off/schema compatibility | column/filter hidden, list remains usable |

### Ordering and performance

- Ordering maps to `authentication_user_state.last_success_at_utc`, not formatted text.
- NULL ordering is explicit and consistent.
- Join is one-to-one by PK; no correlated subquery/N+1.
- Summary counts use the same base filter contract as row data.

## 6. Sensitive history endpoint

### Route

`POST admin/ajax_account_auth_history.php`

### Request

```json
{
  "csrf_token": "existing-session-token",
  "user_id": 123,
  "limit": 20,
  "before_id": 98765
}
```

### Authorization

- Validate admin session before request processing.
- Verify CSRF with existing helper.
- Resolve target from `users` server-side and ensure it is within student/staff account scope of the page/actor.
- Summary history without network details follows approved page access; network/session detail and revoke controls require current `super_admin`.
- Never trust role/account type supplied by client.

### Success response

```json
{
  "success": true,
  "user": {
    "id": 123,
    "name": "escaped by renderer",
    "history_status": "observed",
    "last_success_at": "ISO-8601 UTC",
    "last_success_display": "localized server-rendered value"
  },
  "events": [
    {
      "id": 98765,
      "event_name": "login_attempt",
      "outcome": "success",
      "method": "password",
      "reason_code": "authenticated",
      "occurred_at": "ISO-8601 UTC",
      "occurred_at_display": "localized value",
      "ip_masked": "192.0.2.x",
      "client_family": "Chrome",
      "device_type": "desktop"
    }
  ],
  "next_before_id": 98744,
  "permissions": {
    "view_network": false,
    "view_sessions": false,
    "revoke_sessions": false
  }
}
```

### Error response

```json
{
  "success": false,
  "message": "تعذر تحميل سجل الدخول حالياً."
}
```

Use 401/403/422/500 consistently, but do not expose SQL, table names, stack traces, raw reason context, or whether a target outside actor scope exists.

## 7. Session action endpoint

### Route

`POST admin/ajax_account_session_action.php`

### Request

```json
{
  "csrf_token": "existing-session-token",
  "action": "revoke_one|revoke_all",
  "user_id": 123,
  "session_registry_id": 456,
  "reason": "bounded administrator reason"
}
```

`session_registry_id` required only for `revoke_one`. No session key/hash appears in request or response.

### Guarantees

- `super_admin` authorization rechecked server-side.
- Target session belongs to target user and is active.
- Operation idempotent; already revoked returns success with affected=0 and stable status.
- Shared audit records actor, target, action, affected count, reason and request ID; no session secret.
- DataTables/history UI refreshes dynamically; native PRG fallback is only required if implemented as a form route, otherwise JSON contract is authoritative.

### Response

```json
{
  "success": true,
  "affected": 1,
  "message": "تم إلغاء الجلسة."
}
```

## 8. Credential reset contract

- Existing account page owner remains responsible for authorization, CSRF, validation, audit and response.
- New reset generates a cryptographically random temporary password, stores only a supported adaptive hash, increments `credential_version`, sets `must_change_password=1`, and revokes sessions.
- Plain temporary password may appear once in the immediate authorized response/modal. It is never returned by a later `get_password` request or export.
- Password change by the user verifies policy, stores hash, clears `must_change_password`, increments version if policy requires, and does not auto-log in from a reset token flow.
- No periodic expiry date is added.

## 9. Backfill contract

### Command modes

```text
php tools/backfill_authentication_state.php --dry-run --database=<name>
php tools/backfill_authentication_state.php --apply --database=<name> --batch=<n> --confirmation=<marker>
```

Exact CLI flags are finalized during implementation, but all modes must:

- refuse apply unless environment/test/production authority guards pass;
- never create/alter schema;
- use known actions only and `MAX(created_at)` per target user;
- leave missing-history existing accounts as `unknown_historical`;
- be idempotent, checkpointed, checksummed and resumable;
- emit counts only, no usernames/IP/details;
- write a shared audit summary for apply.

## 10. Retention contract

- CLI-only internal tool, default dry-run.
- Redact network/device fields older than configured detail period.
- Delete event rows older than event retention in bounded batches.
- Never delete user state or active sessions as a side effect of event retention.
- Never snapshot deleted sensitive rows into undo/audit.
- Record cutoffs, counts, checksum, operator and status; apply is non-undoable and requires backup/restore proof.

## 11. Feature flag behavior

| Flag | Off behavior | On behavior |
|---|---|---|
| `AUTH_OBSERVABILITY_ENABLED` | legacy logging adapter remains | canonical outcomes/state write |
| `AUTH_HISTORY_UI_ENABLED` | no column/filter/modal | list/history read model visible |
| `AUTH_THROTTLE_ENFORCEMENT_ENABLED` | counters/metrics only | progressive retry enforced |
| `AUTH_SESSION_REGISTRY_ENABLED` | native PHP session behavior | registry/adoption/heartbeat/revoke checks |
| `PASSWORD_LEGACY_REVEAL_ENABLED` | reveal/export controls unavailable | temporary compatibility only |

Flags are read from environment/config; users cannot switch them from HTTP.

## 12. Audit and redaction assertions

- Forbidden field/name markers: `password`, `password_hash`, `token`, `secret`, `session_id`, `cookie`, `csrf`, `authorization`, `code_verifier`.
- Details are allow-list, not deny-list only.
- Unknown subject uses keyed hash with version; key never appears in `.env.example` beyond placeholder.
- IP and client details require a permission gate and expire per retention.
- Every administrative session revocation, policy change, credential reset, backfill apply and retention apply is visible in shared activity audit.
