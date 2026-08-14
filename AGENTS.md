# EduCore — Project Instructions (AGENTS.md)

> **هذا هو الملف المرجعي الموحّد للتعليمات** — يقرأه كل مساعد ذكاء اصطناعي:
> GitHub Copilot, Codex (ChatGPT), Claude Code, Cursor, ZCode/Zai, Google Antigravity/Gemini, Windsurf, Aider.
>
> يجب الالتزام بجميع القواعد أدناه عند إنشاء أو تعديل أي صفحة.

---

## Engineering Documentation Layer

Use this file as the mandatory rules source, then use the focused docs below for faster orientation in future Codex + LeanCTX sessions:

- `docs/architecture.md` — high-level system shape, folders, modules, auth, API, frontend.
- `docs/project-memory.md` — confirmed project memory and unknowns; update after meaningful investigations.
- `docs/database.md` — confirmed database access pattern and important schema entry points.
- `docs/coding-rules.md` — concise engineering checklist derived from this AGENTS file.
- `docs/project-structure.md` — directory ownership, public entrypoints, and placement rules.
- `docs/architecture-decisions.md` — accepted architectural decisions and their consequences.
- `docs/ai-change-checklist.md` — pre-change and pre-commit checklist for AI-assisted work.
- `docs/file-upload-standard.md` — mandatory upload, storage, URL, rollback, and verification contract.
- `docs/architecture-audit/` — evidence, risks, target architecture, roadmap, and implementation log.

Rules for future documentation updates:
- Do not rescan the entire repository when these docs already answer the question.
- Only inspect additional files when a required detail cannot be confirmed.
- Mark unknown information as `Not confirmed yet`.
- Do not invent implementation details.

### Instruction And Visual Precedence
- This root `AGENTS.md` is the single authoritative project-instruction source for Antigravity, ZCode, Codex, and compatible VS Code agents.
- Tool-specific instruction files may only point to this file or summarize how to load it; they must not duplicate or override project rules.
- For admin UI work, `admin/ui_preview.php` is the canonical visual and structural reference. When an older example in this file or another document differs from the preview, follow the preview and the centralized classes it uses.
- Existing page behavior, field names, element IDs, form actions, CSRF fields, POST handlers, and SQL remain authoritative for functionality; matching the preview must not change them.
- Shared visual rules belong in `assets/css/buttons.css`, `assets/css/premium-dashboard.css`, and `assets/css/admin-unified.css`, according to their ownership below. Do not recreate those rules in page-level `<style>` blocks.

## Architecture Governance — MANDATORY

- The target is a **pragmatic modular monolith**, reached incrementally. Do not introduce a parallel framework, router, auth stack, validation stack, or full rewrite.
- Existing role pages remain stable HTTP entrypoints during migration. Preserve URLs, form names/IDs/actions, session keys, JSON fields, permissions, and SQL behavior unless a separately specified compatibility change requires otherwise.
- Request orchestration follows this sequence: the entrypoint/controller validates the request and calls an application service; the service applies domain policies through contracts and infrastructure adapters; the view/response renders the result.
- Dependency direction is Presentation → Application → Domain/contracts. Infrastructure implements contracts; Domain MUST NOT depend on HTTP, PDO, rendering, or another module's private internals.
- Search for an existing helper, service, validator, repository, and shared UI primitive before creating one. Do not duplicate business rules or add global functions when an owned service/class is the proper reusable boundary.
- Cross-module behavior MUST use a documented service/query contract. If none exists, define the smallest owned contract first; new direct access to another module's internal implementation or tables is forbidden. A temporary exception requires a reviewed ADR with owner, expiry/remediation, evidence, and rollback.
- Schema changes belong in `database/migrations/`. Runtime `CREATE/ALTER/DROP/TRUNCATE TABLE` in requests, pages, services, or models is forbidden for new code.
- Treat `src/`, `classes/`, `config/`, `database/`, `tools/`, `tests/`, `scratch/`, `tmp/`, and `storage/` as internal, non-HTTP source/data. Do not expose a new internal directory or executable utility through the web root.
- Before splitting a large legacy page, add characterization coverage, extract one responsibility at a time, keep an adapter/compatible entrypoint, and document rollback.
- Any change to module boundaries, dependency direction, public contracts, storage strategy, or instruction precedence MUST update `docs/architecture-decisions.md`.
- Run `composer architecture-audit` before closing any PHP, web-boundary, or architecture-baseline change. Findings are review candidates, not automatic security verdicts.
- `tools/architecture_audit_baseline.json` is a ratchet for existing debt, not a permanent allow-list. Do not expand it merely to make strict mode pass; an expansion requires isolated evidence and a documented decision.
- The current strict audit is a **path-level** ratchet: it catches a new file entering a debt category, unreadable scanned files, and lost internal-directory protection. It does not prove that debt inside an already-baselined file did not grow; review those diffs manually.
- Stop and request direction when a business rule, permission, caller, production-data effect, rollback, or dirty-worktree overlap cannot be proved safe.

### Project Mission And Protected Workflows

- EduCore is a single-deployment school management system used by administrators, teachers, students, specialists, supervisors, and external teachers. Preserve continuity of enrollment, accounts, assessment/reporting, attendance, staff/finance, transport, notifications, and learning-content workflows.
- Authentication, authorization, sessions, assessment/grades, attendance, finance, database bootstrap/migrations, audit/undo, SSO, and student/staff attachments are protected areas. Changes require focused contract or role tests and, when data writes are involved, an isolated non-production database with an explicit rollback.
- Production configuration, `.env`, encryption keys, upload/storage paths, shared bootstrap files, and database/schema code must not be changed casually or used as experimentation targets.

### Future Write, Audit, Undo, And Draft Contract — MANDATORY

- Every current or future server-side state change (create, update, delete, status, settings, import, batch, assignment, file metadata, or external side-effect intent/outcome) MUST be recorded through the shared audit architecture. Do not create a page-local logger or bypass it because the change is small.
- Before implementing a new write owner, run `composer audit-write-coverage`. A new candidate MUST either call the shared audit service, delegate to a documented audited owner, or receive a reviewed false-positive classification with evidence. The gate MUST finish with `AUDIT_REVIEW_REQUIRED=0`.
- New tables and entity types fail closed until their policy is registered. Explicitly decide undo eligibility, actor scope, retention, redaction, conflict behavior, and treatment of external effects; never make credentials, secrets, hashes, tokens, or irreversible financial effects restorable snapshots.
- New fields on an already tracked entity are captured by before/after diffing, but the implementer MUST review field labels and sensitive-data redaction. New child tables, files, and external effects are separate resources and require explicit coverage and rollback ordering.
- Business data and its audit/undo record MUST share one transaction where atomicity is possible. If mandatory audit persistence fails, roll back the business write. Batch writes use one batch identifier and undo atomically; do not allow partial batch undo.
- Draft recovery for eligible data-entry forms and undo notifications MUST use the shared role footer components and `assets/js/form-safety.js` / `assets/js/undo-toast.js`; do not add page-local toast, draft, keyboard shortcut, browser `confirm()`, or competing storage-key behavior.
- Any new role footer or data-entry surface MUST be added to the role-coverage contract test. Any intentional exclusion requires a documented reason and a focused test proving that no recoverable write is silently omitted.
- Before closing any feature that changes state, run focused tests plus `composer quality`. CI runs this same command on every pull request and push to the protected main branches; do not weaken, skip, or reclassify a gate merely to obtain a green build.
- Changes to this contract, its scanners, classifications, policy registry, shared UI components, or CI enforcement are protected architecture changes and require focused contract tests plus an ADR update.

### Mandatory Change Process And Git Safety

1. Read this file and the relevant architecture/module documents; inspect target files from disk.
2. Run `git status`, identify pre-existing changes, and define exact in-scope/out-of-scope files.
3. Identify affected callers, routes, forms, endpoints, permissions, session keys, tables, public contracts, and deployment assumptions.
4. Define verification, data-safety, and rollback steps before editing; do not run DB-writing tests against `educore` or production-like data.
5. Keep one coherent concern per change and preserve unrelated user work. Never use destructive reset/clean commands, force operations, or overwrite another change.
6. Review `git diff` and `git diff --check` before commit. Use focused commits, never commit secrets/generated backups/cache/logs, and never push unless the user explicitly requests it.

### Definition Of Done

- The change follows the approved placement and dependency rules and does not introduce a duplicate architecture, helper, service, auth, validation, logging, or data-access path.
- URLs, request fields, sessions, JSON contracts, permissions, schema behavior, and existing workflows remain compatible unless an approved migration explicitly changes them.
- Modified PHP passes syntax checks; relevant tests, `composer architecture-audit`, and any applicable UI/documentation checks pass; security and role boundaries are reviewed.
- Documentation and ADRs are synchronized when architecture, boundaries, public contracts, schema, security, deployment, or business rules change.
- The final diff contains no unrelated file, no secret, and no unexplained generated artifact; rollback is practical and recorded.

### Mandatory Stop Conditions

- Stop instead of guessing when a business rule, permission, caller, dynamic include, production-data effect, migration state, route/API compatibility, deployment boundary, or rollback cannot be established.
- Stop when the required test would touch a non-isolated database, when existing changes overlap the intended hunk, or when verification cannot prove the requested behavior.
- Stop when a proposal needs a new framework, router, auth stack, directory convention, or cross-module dependency that is not already approved and documented.

---


## Platform & Stack
- PHP 8.0+ (per `composer.json`) / MySQL (MariaDB), XAMPP, PDO
- Path: `<project-root>`
- PHP executable: `php`
- Bootstrap 5.3.2 RTL, Font Awesome 6, DataTables
- Database: `educore`, localhost

## Environment & Secrets
- All API keys, DB credentials, and secrets stored in `.env` (root)
- Loaded via `config/env_loader.php` (auto-included by `config/database.php`)
- Use `env('KEY', 'default')` to read values — NEVER hardcode secrets
- `.env` is in `.gitignore`, `.env.example` has template with placeholder values
- Config files: `database.php`, `ai_config.php`, `azure_sso.php`, `encryption.php`

---

## Authentication & Security

### Auth Pattern (MANDATORY for all admin pages)
Every admin PHP file MUST validate auth BEFORE any POST/GET/AJAX processing:
```php
<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
// ... now safe to process requests
```
- `admin_header.php` calls `validateSession('admin')` at line 15 — but this is TOO LATE for files that process POST before including it
- ALWAYS add explicit `validateSession()` after class requires, before DB init or POST handling

### CSRF Protection
- Token generated in `includes/session_config.php` via `bin2hex(random_bytes(32))`
- Frontend: jQuery `ajaxSetup` in `assets/js/main.js` auto-appends `csrf_token` to all AJAX POST
- Backend: `includes/ajax_handlers.php` validates both `$_POST['csrf_token']` and `X-CSRF-TOKEN` header
- Use `hash_equals()` for timing-safe comparison

### XSS Prevention
- All user input echoed in HTML must use `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- Especially GET parameters in form value attributes

### Debug Mode
- NEVER use `ini_set('display_errors', 1)` in production files
- Use `error_log()` for server-side logging only

### File Upload And Storage — MANDATORY

- Read `docs/file-upload-standard.md` before creating or changing any upload, attachment, import, generated-file, or download workflow.
- Every new uploaded file MUST be validated through `FileUploadGuard`; do not validate by extension or browser-provided MIME alone.
- Reject dangerous double extensions, verify the real MIME type, enforce a byte limit, and generate an unpredictable storage name. Keep the original name as display metadata only.
- Store only a file identifier or normalized relative path in the database. NEVER store `localhost`, a domain name, `file://`, a Windows drive path, or an absolute server filesystem path.
- Build external links from `APP_URL`. Use a relative URL or the current request origin for internal links. `SITE_URL` is compatibility-only.
- Sensitive student/staff attachments belong in `storage/private/` and are downloaded through an authenticated, authorized controller; never expose them through a direct `uploads/` URL.
- Public upload directories MUST inherit `uploads/.htaccess`; do not add a server configuration that enables directory indexes, CGI, PHP, or another script handler below `uploads/`.
- A database-backed upload is a two-resource operation: remove the new file if the database write fails; on replacement, commit the new database reference before deleting the old file; on deletion, remove/update the database reference before deleting the old file.
- Do not call `move_uploaded_file()` in a new path until that path is classified in `tools/upload_policy_manifest.json` and passes `php tools/audit_upload_policy.php --strict`. The manifest is a reviewed inventory, not a bypass list.
- A new upload workflow requires focused tests for invalid MIME, dangerous names, size/upload errors, collision-resistant names, authorization, and file/database rollback where applicable.
- Before closing upload-related work, run `composer upload-policy-audit`, `composer architecture-audit`, and the staging checks in `docs/upload-verification-plan.md` that are safe for the available environment.

---

## Button Style — MANDATORY — نظام موحد ⚠️

> **الملف المرجعي الوحيد للأزرار:** `assets/css/buttons.css`
> لا تضع أي تنسيق زر في ملفات CSS أخرى أو داخل `<style>` في صفحات PHP.

### قاعدة الأولوية (Cascade Order):
Bootstrap RTL → `style.css` → `premium-dashboard.css` → **`buttons.css`** → `admin-unified.css`

- `buttons.css` هو مرجع ألوان وأحجام الأزرار.
- `admin-unified.css` هو طبقة التوحيد النهائية الاختيارية للصفحات التي تم ترحيلها، ويجب ألا يعيد تعريف ألوان الأزرار الأساسية.

---

### هرم الأزرار المعتمد:

| الحالة | الكلاس | اللون |
|--------|---------|-------|
| إضافة / إنشاء | `btn-success` | أخضر مصمت |
| حفظ / تعديل | `btn-primary` | أزرق مصمت |
| تأكيد / إرسال | `btn-primary` | أزرق مصمت |
| حذف / رفض نهائي | `btn-danger` | أحمر مصمت |
| إلغاء / إغلاق / رجوع | `btn-secondary` | رمادي مصمت |
| تفعيل | `btn-success` | أخضر مصمت |
| إحصائيات / بحث / تصدير | `btn-outline-{color}` | مفرغ (Outline) |
| إعادة تعيين / مسح فلتر خارج قوائم الإدارة الحرة | `btn-outline-secondary` | مفرغ رمادي |
| إعادة تعيين / بحث / إعدادات جدول في قوائم الإدارة الحرة | `btn-light btn-sm` | مطابق لـ `ui_preview.php` |
| داخل header داكن | `btn-light` أو `btn-outline-light` | أبيض |
| إجراءات ثانوية (NAV) | `btn-outline-secondary` | مفرغ |
| ترتيب / نقل | `btn-secondary btn-sm` | رمادي صغير |

---

### أحجام الأزرار:

| الحجم | الكلاس | الاستخدام |
|-------|---------|----------|
| كبير | `btn-lg` | زر رئيسي وحيد في الصفحة |
| عادي | `btn` (بدون suffix) | أزرار الشريط العلوي / النماذج |
| صغير | `btn-sm` | أزرار الجداول وcard-header |
| أيقونة | `btn-icon` | أزرار مربعة/دائرية بدون نص |

---

### قواعد الأزرار في الجداول — MANDATORY ⚠️:

```html
<td>
  <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل">
    <i class="fas fa-edit"></i>
  </button>
  <button class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="تعطيل">
    <i class="fas fa-ban"></i>
  </button>
  <button class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تفعيل">
    <i class="fas fa-check"></i>
  </button>
  <button class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف">
    <i class="fas fa-trash"></i>
  </button>
</td>
```

**قواعد:**
- استخدم `btn-action-pills` دائماً لأزرار الإجراءات الأيقونية داخل الجداول مثل صفحة `ui_preview.php`.
- استخدم النوع الدلالي `btn-edit`, `btn-deactivate`, `btn-activate`, أو `btn-delete`؛ لا تستخدم الأزرار المصمتة `btn-primary/btn-danger` لهذه الإجراءات.
- `me-1` للمسافة بين الأزرار (لا `btn-group`)
- أيقونات فقط بدون نص في الجداول
- `data-bs-toggle="tooltip"` مع `title` لكل زر

---

### قواعد شريط الأدوات (Page Header Toolbar):

```html
<div class="btn-toolbar mb-2 mb-md-0 gap-2">
    <a href="statistics.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
        <i class="fas fa-chart-pie me-2"></i>الإحصائيات
    </a>
    <button class="btn btn-success shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus-circle me-2"></i>إضافة جديد
    </button>
</div>
```

---

### قواعد أزرار الـ Modal:

```html
<div class="modal-footer">
    <!-- زر الإلغاء/الرجوع دائماً btn-secondary مثل صفحة اختبار الواجهة -->
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        <i class="fas fa-times me-1"></i>إلغاء
    </button>
    <!-- زر التأكيد دائماً على اليمين (مصمت) -->
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save me-1"></i>حفظ
    </button>
</div>
```

**قواعد:**
- modal إضافة: submit = `btn-success`
- modal تعديل: submit = `btn-primary`
- modal حذف: submit = `btn-danger` ، إلغاء = `btn-secondary`
- modal تعطيل: submit = `btn-warning`
- MANDATORY: أزرار الإلغاء والإغلاق والرجوع داخل المودالات تستخدم `btn-secondary` الرمادي مثل صفحة `ui_preview.php`.
- MANDATORY: أزرار الحذف والرفض النهائي فقط تستخدم `btn-danger` مصمتة.
- MANDATORY: أزرار البحث والمزامنة والفلترة والإحصائيات يجب أن تكون `btn-outline-*`.

---

### محظورات الأزرار — NEVER ❌:

- ❌ كتابة CSS خاص بزر داخل `<style>` في صفحة PHP
- ❌ استخدام `!important` على أي خاصية زر
- ❌ تداخل `btn-group` مع أزرار الجداول
- ❌ `btn-outline-*` كزر أساسي للإجراءات التدميرية (حذف/تعطيل)
- ❌ حجم `btn-xs` (غير موجود في Bootstrap 5 — استخدم `btn-sm`)
- ❌ أزرار بدون `<i class="fas fa-*">` في الجداول
- ❌ `onclick="return confirm(...)"` — استخدم Bootstrap Modal دائماً
- ❌ `SweetAlert` / `Swal` — محظور في كل أنحاء النظام

---

### استثناءات مسموح بها ✅:

- `btn-outline-*` في toolbars الصفحة (زر الإحصائيات، تصدير CSV)
- `btn-outline-light` فوق خلفيات داكنة
- أزرار التبديل (toggle buttons) في واجهة الحضور والحالات الثنائية

---



## Statistics Cards (Stat Cards)

Use the `stat-card` structure and behavior demonstrated in `admin/ui_preview.php`. **Never** use plain `card border-0 shadow-sm text-center`.

### Required Structure (مع إعدادات الحركة):
```html
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #COLOR1, #COLOR2);">
      <div class="stat-card-icon"><i class="fas fa-ICON"></i></div>
      <div class="stat-card-info">
        <!-- استخدام كلاس counter وسمة data-target مع تهيئة القيمة بـ 0 -->
        <div class="stat-card-number counter" data-target="RAW_VALUE">0</div>
        <div class="stat-card-label">Label</div>
        <div class="stat-card-sub"><i class="fas fa-ICON"></i> Subtitle</div>
      </div>
    </div>
  </div>
</div>
```

### Standard Color Gradients:
- Blue (total/primary): `#3b82f6, #2563eb`
- Green (active/success): `#10b981, #059669`
- Yellow (pending/warning): `#f59e0b, #d97706`
- Red (blocked/danger): `#ef4444, #dc2626`
- Purple: `#8b5cf6, #7c3aed`
- Cyan: `#0ea5e9, #0284c7`
- Orange: `#f97316, #ea580c`

### Count-Up Animation & Formatting (عدادات الحركة والوحدات):
- **التفعيل الإجباري:** يجب أن تحتوي جميع أرقام الإحصائيات على كلاس `counter` وسمة `data-target` لتفعيل حركة العداد التدريجي المكتوبة في `premium-dashboard.js`.
- **تهيئة القيمة المبدئية:** يجب كتابة الرقم `0` كقيمة مبدئية داخل عنصر العداد، وتجنب وضع القيمة الحقيقية مباشرة داخل ترويسة العنصر لتفادي توقف الحركة.
- **إرسال الأرقام الخام:** في سمة `data-target` يتم دائماً تمرير أرقام خام (صحيحة أو عشرية) خالية من الفواصل والنصوص، مثل `data-target="1500"` وتجنب كتابة `data-target="1,500"`.
- **الرموز والنسب المئوية:** إذا كان الرقم يحتوي على رمز مثل النسبة المئوية `%` أو وحدات قياس، يجب وضع الرمز خارج وسم العداد في حاوية الرقم كالتالي لضمان عدم تأثر السكربت بالرموز:
  `<div class="stat-card-number"><span class="counter" data-target="85">0</span>%</div>`

### CSS & Style Centralization:
- **تأثير النمط المركزي:** تم إدراج وتعريف كلاسات الـ `stat-card` بالكامل وبطريقة مركزية موحدة داخل ملف `assets/css/premium-dashboard.css` (الذي يتم تحميله تلقائياً في كل الصفحات).
- **يُمنع تماماً** إعادة كتابة أو كتابة أي تنسيق محلي (inline `<style>`) لكلاسات الـ `stat-card` أو ملحقاتها داخل الصفحات الفرعية.
- **الألوان الدلالية:** لا تجعل جميع كروت الإحصائيات بلون واحد أو لون فاتح موحد. استخدم `--card-gradient` بألوان مختلفة مناسبة لمحتوى الكرت مثل لوحة التحكم: أزرق للإجمالي، أخضر للنشط/المكتمل، أصفر/برتقالي للتنبيه أو التواريخ، أحمر للخطر/المعطل، بنفسجي للتمييز، وسماوي للمعلومات.
- **التصميم المستجيب:** تتبع الكروت نفس التنسيق الموحد وتنسجم مع أبعاد الصفحة المختلفة تلقائياً بفضل البناء المستجيب في ملف الـ CSS المركزي.

---

## Confirmation Dialogs / Modals

Always use **Bootstrap 5 modals** for confirmation dialogs (delete, toggle status, send push, etc.). **Never** use SweetAlert (`Swal.fire`) or browser `confirm()`.

### Standard Confirmation Modal Pattern:
```html
<div class="modal fade" id="confirmActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="page.php">
                <input type="hidden" name="action" value="action_name">
                <input type="hidden" name="id" id="actionItemId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-icon me-2"></i>عنوان العملية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-icon text-{color}" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">نص التأكيد <span class="fw-bold text-primary" id="actionItemName"></span>؟</p>
                    <div class="alert alert-{type}">
                        <i class="fas fa-info-circle me-2"></i>
                        وصف إضافي للعملية.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-{color}">
                        <i class="fas fa-icon me-1"></i>تأكيد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

### Rules:
- Header treatment comes from the `admin-modal-{type}` class on `.modal-content`; do not add Bootstrap `bg-*` or `text-*` classes to modal headers.
- Always include a large centered icon in modal body
- Cancel button is always `btn-secondary` with `data-bs-dismiss="modal"`, matching `ui_preview.php`
- Use `<form method="post">` inside the modal — no dynamic form creation in JS
- Show modal via JS: `new bootstrap.Modal(document.getElementById('modalId')).show()`
- In `staff.php` and `students.php` registration forms, **all delete/remove actions** (attachments, unlink sibling, remove guardian, remove extra phone/landline/additional data rows, remove allowances/deductions/advances/work history) MUST show a Bootstrap confirmation modal first
- NEVER delete/remove directly on click, and NEVER use browser `confirm()` for these actions

---

## General Admin Page Structure
1. **Page Header**: `<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2"><i class="fas fa-icon me-2"></i>Title</h1><div class="btn-toolbar mb-2 mb-md-0">buttons</div></div>`
2. Alerts: `<div class="alert alert-TYPE alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i>msg<button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`
3. Stat cards row (gradient `.stat-card` pattern)
4. Main content: free-list tables use `admin-list-surface`; explicit functional tools may use cards.
5. Footer: `<?php require_once '../includes/admin_footer.php'; ?>`

### Important Rules:
- **NO** extra `<div class="container-fluid py-4">` wrapper — content goes directly inside `<main>` from admin_header.php
- Free-list pages use the standalone `admin-filter-bar` above `admin-list-surface`, exactly as in `admin/ui_preview.php`; do not put their filters inside a card header.
- Explicit functional cards may place their own filters inside the card header when those filters control only that card.
- Form card headers use `bg-primary text-white` (not bg-info, bg-success, etc.)
- Tables use `table table-hover table-striped` with DataTables

## Functional Card With Inline Filters
```html
<!-- Use this card pattern only for an explicit functional card, not free-list pages. -->
<div class="card shadow admin-card-surface">
  <div class="card-header bg-primary text-white">
    <div class="row align-items-center">
      <div class="col-md-3"><h5 class="mb-0"><i class="fas fa-list me-2"></i>Title <span class="badge bg-light text-dark ms-2">count</span></h5></div>
      <div class="col-md-9">
        <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
          <select class="form-select form-select-sm" name="filter" style="width:auto; min-width:Xpx;">...</select>
          <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
          <a href="page.php" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        </form>
      </div>
    </div>
  </div>
  <div class="card-body"><!-- table here --></div>
</div>
```

---

## Unified Admin UI Layer — تدريجي وحذر ⚠️

> الملف المركزي لطبقة توحيد واجهة صفحات الإدارة: `assets/css/admin-unified.css`
> يتم تحميله بعد `buttons.css` داخل `includes/admin_header.php`.

### الهدف
- توحيد شكل صفحات الإدارة تدريجياً بدون كسر وظائف الصفحات.
- استخدام كلاسات اختيارية تبدأ بـ `admin-*` بدلاً من فرض تغييرات عامة على كل الصفحات دفعة واحدة.
- ترحيل الصفحات القديمة على مراحل، مع اختبار كل صفحة بعد التعديل.

### القاعدة الذهبية للتحويل
- ✅ مسموح تغيير الحاويات والكلاسات البصرية.
- ✅ مسموح نقل الفلاتر بصرياً طالما بقيت أسماء الحقول والمعرفات كما هي.
- ❌ ممنوع تغيير `name` أو `id` أو `action` أو hidden inputs أو CSRF أثناء توحيد الشكل.
- ❌ ممنوع تغيير استعلامات قاعدة البيانات أو POST handlers كجزء من توحيد CSS.
- ❌ ممنوع حذف JavaScript يعتمد على عناصر الصفحة دون بديل مؤكد.

### النمط الرسمي لصفحات القوائم الحرة
للصفحات التي تعتمد على جدول قائمة رئيسي حر مثل صفحات الطلاب:

```html
<form method="GET" class="admin-filter-bar">
  <div class="admin-filter-controls">
    <!-- filters -->
  </div>
  <div class="admin-filter-actions">
    <!-- reset/settings/search buttons -->
  </div>
</form>

<div class="admin-list-surface">
  <div class="admin-table-wrap">
    <table class="table table-hover table-striped datatable admin-data-table">
      <!-- rows -->
    </table>
  </div>
</div>
```

### قواعد الجداول الموحدة
- جداول القوائم الحرة لا توضع داخل `card`, `card shadow`, أو `table-card` إلا إذا كانت الصفحة مصممة ككارت وظيفي صريح.
- لا تستخدم حواف دائرية أو ظل حول جدول القائمة الحرة.
- الفلاتر تكون أعلى الجدول في `admin-filter-bar`.
- الجداول الموحدة `admin-data-table` يجب أن تُظهر خطوطاً فاصلة عمودية خفيفة بين الأعمدة لزيادة الوضوح.
- أزرار الفلاتر الثانوية مثل `إعادة تعيين` و`إعدادات الجدول` و`بحث` في صفحات القوائم الحرة تستخدم `btn btn-light btn-sm` لمطابقة صفحة الطلاب المرجعية.
- لا تضف تبويبات لصفحة لا تحتوي تبويبات أصلاً. استخدم `admin-tabs` فقط مع التبويبات الموجودة أو عند طلب تبويب جديد صراحة.
- أزرار الاستيراد تستخدم دائماً كلاس `btn-header-premium btn-import-soft` ومسمى موحد مثل `استيراد Excel` عندما يكون الاستيراد من Excel.
- أزرار التصدير إلى Excel تستخدم دائماً كلاس `btn-header-premium btn-export-soft` ومسمى `تصدير Excel`.
- أزرار الطباعة تستخدم مسمى `طباعة` مع لون نص أسود وخلفية بيضاء عبر `btn-print-soft`.
- أزرار التصدير إلى PDF تستخدم مسمى `PDF` مع لون أحمر وخلفية بيضاء عبر `btn-pdf-soft`.
- أزرار الإجراءات داخل الجدول تكون أيقونات فقط باستخدام `btn-action-pills` والنوع الدلالي المناسب وtooltip.
- **مؤشر الفلتر النشط (Active Filter Indicator):** أزرار فلاتر الاختيار المتعدد (dropdown) التي تحتوي على خيارات محددة يجب أن يضاف إليها كلاس `.active-filter` ديناميكياً لتلوين خلفيتها باللون الأزرق الفاتح (`#e0f2fe`) وإطارها باللون الأزرق الداكن (`#0284c7`) كدلالة بصرية على تفعيل التصفية.
- عند وجود صفحة مرجعية طلبها المستخدم، تطبق الصفحة الهدف نفس التركيب البصري والكلاسات المرجعية.

### قواعد المودالات الموحدة
- تستخدم المودالات الاختيارية كلاس `admin-modal` مع نوع العملية:
  - `admin-modal-create`
  - `admin-modal-edit`
  - `admin-modal-delete`
  - `admin-modal-warning`
  - `admin-modal-view`
- لا يتم تغيير أسماء الحقول أو hidden inputs أو JS الذي يملأ المودال أثناء توحيد الشكل.
- أزرار المودال تبقى خاضعة لقواعد `buttons.css`.

### مسار التنفيذ
1. أضف/استخدم كلاسات `admin-*` في الصفحة المستهدفة.
2. شغّل `php -l path\to\page.php`.
3. اختبر الفلاتر وDataTables وأزرار الإضافة/التعديل/الحذف.
4. لا تنقل صفحة جديدة للنظام الموحد قبل التأكد من الصفحة السابقة.
5. قبل إغلاق أي دفعة توحيد، شغّل `php tools\audit_admin_ui.php` ويجب أن تكون النتيجة `UI_AUDIT_ISSUES=0`.

---

## Table Column Settings (إعدادات الجدول) — MANDATORY ⚠️

> **السلوك الموحّد:** عند تحديد/إلغاء أي checkbox في مودال إعدادات الأعمدة، يُطبَّق التغيير **فوراً** على الجدول ويُحفظ في `localStorage` — **بدون زر "تطبيق"**.

### الملف المرجعي: `assets/js/admin_table_actions.js`
الدالة `initializeTableColumnSettings(tableId, mapping, storageKey)` تضيف `change` listener على كل checkbox يُطبّق الإخفاء/الإظهار فوراً ويحفظ الحالة.

### النمط المعتمد (تطبيق مباشر — بدون زر تطبيق):
```html
<!-- زر فتح المودال -->
<button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal"
        data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
    <i class="fas fa-cog me-1"></i>إعدادات الجدول
</button>

<!-- المودال: footer يحتوي زر "إغلاق" فقط -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>اختر الأعمدة التي تريد عرضها في الجدول:</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_name" checked>
                    <label class="form-check-label" for="col_name">العمود</label>
                </div>
                <!-- باقي الـ checkboxes -->
            </div>
            <div class="modal-footer">
                <!-- زر إغلاق فقط — التطبيق فوري عبر JS -->
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>
```

### JS المطلوب في الصفحة:
```html
<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    initializeTableColumnSettings('myTableId', {
        col_name: 1,   // {checkboxId: columnIndex}
        col_status: 2,
        // ...
    }, 'myTable_columns'); // storageKey فريد لكل جدول
});
</script>
```

### القواعد:
- ✅ **التطبيق فوري** عبر `change` listener على كل checkbox.
- ✅ الحالة تُحفظ في `localStorage` بمفتاح فريد لكل جدول.
- ✅ الـ `mapping` يربط `id` الـ checkbox بـ **index** العمود (يبدأ من 0).
- ❌ **يُمنع** إضافة زر "تطبيق" — أصبح زائداً مع التطبيق الفوري.
- ❌ **يُمنع** استدعاء `applyTableColumnSettings()` يدوياً — `initializeTableColumnSettings` يكفي.
- ✅ عند إنشاء جدول جديد، استخدم `storageKey` فريد مثل `{page}_table_columns` لتجنّب التعارض.

---

## DataTables Action Return State — MANDATORY

> **المالك المركزي الوحيد:** `assets/js/datatable-state.js`.
> تحتفظ السياسة بموضع الجدول مؤقتًا لمرة واحدة فقط عند بدء إجراء من صف الجدول، سواء اكتمل داخل الصفحة بـ`POST`/AJAX أو فتح نموذجًا كاملاً ثم عاد إلى القائمة، دون أن يتحول موضع الجدول إلى تفضيل دائم.

### القواعد:
- كل صفحة تحمل DataTables عبر `admin_header.php` أو `teacher_header.php` ترث السياسة المركزية تلقائيًا، و`specialist_header.php` يرثها من رأس الإدارة. الصفحة المستقلة التي تهيّئ DataTables MUST تحمل `assets/js/datatable-state.js` قبل مكتبة DataTables وقبل أي استدعاء `.DataTable(...)`.
- لا تنشئ `stateSaveCallback` أو `stateLoadCallback` محليًا في صفحة أو مكوّن. الاستثناء يتطلب ADR واختبارًا مركزًا يثبت سبب عدم كفاية العقد المشترك.
- استخدم `id` ثابتًا وفريدًا لكل جدول، أو `data-table-state-key` ثابتًا عندما يتعذر ذلك. لا تعتمد على معرّف مولد يتغير بين الطلبات.
- لا تحفظ حالة الجدول لمجرد تغيير الصفحة أو عدد السجلات أو البحث أو الترتيب. التنقل العادي بعيدًا عن الصفحة، والعودة إليها، والتحديث اليدوي يجب أن يبدأ من إعدادات الجدول الافتراضية.
- عند إرسال نموذج `POST` إلى مسار الصفحة نفسه تُحفظ الصفحة الحالية وعدد السجلات والبحث والترتيب في `sessionStorage` بجسر قصير العمر، ثم تُستهلك الحالة وتحذف عند أول تحميل تالٍ. إذا اختلف عنوان الدخول الافتراضي عن عنوان `action`/PRG (مثل غياب `tab` ثم ظهوره بعد الحفظ)، ينشئ الجسر مراجع مؤقتة مترابطة للسياقين ويحذفها كلها بمجرد استهلاك أحدها. نموذج يعبر إلى endpoint آخر يحتاج `data-datatable-return="true"` فقط إذا كان عقده الموثق يعيد المستخدم إلى الصفحة الأصلية، ويمكن تحديد عنوان الرجوع الصريح بـ`data-datatable-return-url` عندما لا يكفي عنوان المصدر.
- رابط إجراء صف يفتح صفحة/نموذجًا كاملاً يجب أن يكون رابطًا دلاليًا قياسيًا مثل `btn-action-pills`، أو يحمل `action` ومعرف كيان معتمد في query، أو يصرّح بـ`data-datatable-return="true"`. يلتقط الأصل المركزي سياق قائمة المصدر ويستنتج alias الرجوع بعد حذف `action` ومعرف الكيان، ويمنحه مهلة 30 دقيقة لإكمال النموذج. الروابط العادية لا تُلتقط، ويمكن إلغاء الالتقاط صراحةً على رابط استثنائي بـ`data-datatable-return="false"`.
- يجب أن يملك كل صف هوية ثابتة يمكن استعادتها (`data-datatable-row-key` أو أحد معرفات الكيان المعتمدة أو checkbox تحديد الصف بقيمة المعرف). يمكن للأصل استخراج الهوية من query رابط الإجراء كحل مركزي، لكن يفضل كذلك وضع `data-{entity}-id` على زر الإجراء في presenters الخادمية. نموذج المودال الموجود خارج الصف يحدد الجدول والحقل صراحةً عبر `data-datatable-return-table="TABLE_ID"` و`data-datatable-return-row-field="ENTITY_ID_FIELD"`؛ لا تحفظ إحداثي `scrollY` بديلاً عن هوية السجل.
- بعد PRG يعيد الأصل المشترك رسم الصفحة المحفوظة ثم يبحث عن الصف بالهوية الثابتة. لا يمرر الشاشة إلا إذا كان الصف خارج مجال الرؤية، ويمنح الصف تركيزًا مؤقتًا وتمييزًا بصريًا وإعلانًا لقارئ الشاشة، مع احترام `prefers-reduced-motion`. اختفاء الصف بسبب تغيير الحالة/الفلتر نتيجة صحيحة ولا يبرر نقل المستخدم إلى صفحة أخرى عشوائيًا.
- لا تحفظ تحديد الصفوف الجماعي أو المودالات أو كلمات المرور المكشوفة أو بيانات الاعتماد أو حالة إعدادات الأعمدة. إعدادات إظهار الأعمدة تبقى مملوكة حصريًا لـ`assets/js/admin_table_actions.js` و`localStorage`.
- استخدم `data-datatable-return-state="false"` لجدول وظيفي لا يجوز استعادة موضعه، و`data-datatable-return="false"` لنموذج `POST` أو رابط صف لا يمثل إجراء رجوع إلى القائمة؛ كل استثناء يحتاج سببًا موثقًا واختبارًا مركزًا.
- لا تضف زر «إعادة ضبط العرض» لحالة الرجوع المؤقتة؛ لا توجد حالة دائمة تحتاج إلى مسح يدوي.
- AJAX تحسين تدريجي صريح وليس اعتراضًا عامًا للنماذج: استخدم `data-datatable-ajax="true"` فقط لنموذج مودال مؤهل، أو `data-datatable-ajax="manual"` عندما يجب أن ينفذ تحقق الصفحة أولًا ثم يستدعي `EduCoreDataTableState.submitAjax(form, event)`. يجب أن يقبل الخادم `datatable_ajax=1` مع `X-Requested-With: XMLHttpRequest` ويعيد JSON موحدًا، مع بقاء POST → Redirect → GET نفسه صالحًا عند غياب JavaScript أو DataTables أو فشل شرط الأهلية.
- لا تكرر منطق الكتابة من أجل AJAX: يستعمل JSON وPRG نفس handler/service والصلاحيات وCSRF والمعاملة وسجل التدقيق، ويختلف تمثيل الاستجابة فقط. عمليات الملفات والاستيراد والدفعات والمال والآثار الخارجية أو أي تدفق لا يمكن إثبات أمانه تبقى PRG حتى يملك عقدًا واختبارات مخصصة.
- بعد تغيير صف مؤهل عبر AJAX استخدم `table.ajax.reload(callback, false)` للحفاظ على الصفحة الحالية، ثم أعد الصف بهويته الثابتة وحدّث الملخصات المتأثرة من استجابة الخادم. عند تغيير فلتر يغيّر مجموعة النتائج استخدم reset صريحًا للترقيم (`table.ajax.reload(null, true)` أو الافتراضي) لتجنب صفحة خارج النطاق.
- قبل إغلاق أي تغيير يمس DataTables أو تحميل أصوله شغّل `composer datatable-state-audit`؛ يجب أن ينفذ عقد الجرد PHP واختبار السلوك JavaScript معًا، وتبقى بوابة `@datatable-state-audit` ضمن `composer quality`.

---

## Cascading (Dynamic) Filters

When filtering by المرحلة → الصف → الفصل, use cascading `data-*` attributes so each dropdown filters the next.

### HTML Pattern:
```html
<select id="filterStage"><option value="">الكل</option>
  <option value="1">...</option></select>
<select id="filterGrade"><option value="">الكل</option>
  <option value="1" data-stage="1">...</option></select>
<select id="filterClass"><option value="">الكل</option>
  <option value="1" data-grade="2">...</option></select>
```

### JS Pattern:
```js
stageFilter.addEventListener('change', function() {
    var stageId = this.value;
    gradeFilter.value = '';
    gradeFilter.querySelectorAll('option[data-stage]').forEach(function(opt) {
        opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
    });
    classFilter.value = '';
    classFilter.querySelectorAll('option[data-grade]').forEach(function(opt) { opt.style.display = 'none'; });
});
gradeFilter.addEventListener('change', function() {
    var gradeId = this.value;
    classFilter.value = '';
    classFilter.querySelectorAll('option[data-grade]').forEach(function(opt) {
        opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
    });
});
```

### Rules:
- Query classes with `grade_id`: `SELECT c.id, c.name, c.grade_id FROM classes c`
- Query grades with `stage_id`: `SELECT id, grade_name, stage_id FROM grades`
- When stage changes, reset both grade and class filters
- When grade changes, reset class filter only
- Use `display: none` to hide non-matching options (not `disabled`)

---

## Class Filter (filterByClass) — MANDATORY ⚠️

A global `filterByClass()` function exists in `assets/js/main.js`. It uses the current page URL (not hardcoded `students.php`) to stay on the same page when filtering.

### Rules:
- When creating a new page with a class filter (`#classFilter`), define a **local** `filterByClass()` that redirects to the **current page** (not `students.php`):
  ```js
  function filterByClass(classId) {
      if (classId) {
          window.location.href = 'CURRENT_PAGE.php?class_id=' + classId;
      } else {
          window.location.href = 'CURRENT_PAGE.php';
      }
  }
  ```
- Replace `CURRENT_PAGE.php` with the actual filename (e.g., `student_reports.php`, `student_evaluations.php`)
- NEVER hardcode `students.php` in `filterByClass` on pages other than `students.php`
- The jQuery handler in `main.js` only fires on `students.php` — other pages rely on `onchange="filterByClass(this.value)"` attribute

---

## Form Submission Pattern (PRG Pattern) — MANDATORY ⚠️

To prevent the **"Form Resubmission"** bug (where refreshing the page after a POST request duplicates data), you MUST implement the **Post/Redirect/Get (PRG)** pattern for ALL state-changing operations (Add, Edit, Delete, Toggle Status, Import).

### Implementation Steps:
1. **Initialize Sessions Early**: Retrieve and clear session messages at the very top of the file:
   ```php
   $success_message = $_SESSION['success_message'] ?? null;
   $error_message = $_SESSION['error_message'] ?? null;
   unset($_SESSION['success_message'], $_SESSION['error_message']);
   ```
2. **Process POST & Store Feedback**: In the POST handler, store feedback in `$_SESSION` instead of local variables:
   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       // ... processing logic ...
       if ($success) {
           $_SESSION['success_message'] = "تمت العملية بنجاح";
       } else {
           $_SESSION['error_message'] = "حدث خطأ";
       }
       // 3. Redirect immediately after processing
       header("Location: " . $_SERVER['PHP_SELF']);
       exit();
   }
   ```
3. **Redirect Immediately**: ALWAYS call `header("Location: ...")` followed by `exit()` after any POST processing.
4. **Preserve Tab State**: If the page uses tabs, append the active tab name to the redirect URL:
   `header("Location: page.php?action=edit&id=$id&tab=attachments");`

---

## Tab Persistence (Hybrid Approach) — MANDATORY ⚠️

To ensure the active tab is maintained across page refreshes, redirects, and form submissions (POST to `PHP_SELF`), you MUST implement the **Hybrid Persistence** model.

### 1. Server-Side Initialization
Initialize the `$activeTab` variable at the top of the file to prioritize both GET and POST parameters:
```php
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'basic');
$validTabs = ['tab1', 'tab2', '...']; // Define valid tabs for safety
if (!in_array($activeTab, $validTabs)) { $activeTab = 'basic'; }
```

### 2. Form Integration
ALL forms (main forms and modals) on a tab-based page MUST include a hidden input to carry the state:
```html
<input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
```

### 3. JavaScript Synchronization
Use JavaScript to sync the UI, the browser's URL, and ALL hidden inputs simultaneously:
```javascript
// On tab 'shown' event (Bootstrap)
const tabName = target.replace('#pane-', ''); // Adjust selector as needed
const activeTabInputs = document.querySelectorAll('.active-tab-input');

// 1. Update hidden inputs for all forms
activeTabInputs.forEach(input => input.value = tabName);

// 2. Update URL without reload for consistency
const newUrl = new URL(window.location);
newUrl.searchParams.set('tab', tabName);
window.history.replaceState({}, '', newUrl);

// 3. Save to sessionStorage for refresh fallback
sessionStorage.setItem('page_active_tab', tabName);
```

### 4. Redirect Pattern
Always use `Utilities::buildQueryString()` to preserve the tab state in redirects:
```php
header("Location: page.php" . Utilities::buildQueryString(['tab' => $activeTab]));
exit();
```

---

## Performance Patterns

### N+1 Query Prevention
- NEVER query inside a foreach loop
- Use batch fetch with `WHERE id IN (...)` or `GROUP_CONCAT` in the initial query
- Pre-load lookup maps before loops: `$map = []; foreach($rows as $r) $map[$r['id']] = $r;`

### Transactions for Bulk Operations
```php
$db->beginTransaction();
try {
    $stmt = $db->prepare("INSERT INTO ...");
    foreach ($items as $item) { $stmt->execute([...]); }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}
```

---

## Undo System (CTRL+Z)
- Table: `undo_log` — stores INSERT/UPDATE/DELETE actions with JSON old_data/new_data
- Class: `classes/UndoManager.php` — static methods: `logInsert()`, `logUpdate()`, `logDelete()`, `undo()`, `fetchRecord()`
- Integration pattern:
  ```php
  require_once '../classes/UndoManager.php';
  // Before edit/delete: $oldData = UndoManager::fetchRecord('table_name', $id);
  // After add:    UndoManager::logInsert('table', $id, $newData, 'وصف العملية');
  // After edit:   UndoManager::logUpdate('table', $id, $oldData, $newData, 'وصف العملية');
  // After delete: UndoManager::logDelete('table', $id, $oldData, 'وصف العملية');
  ```

---

## Activity Log (سجل العمليات) — MANDATORY ⚠️

The system uses `classes/ActivityLog.php` to log all CRUD operations into `activity_logs` table. The `details` column stores **free-form JSON** — this is deliberately flexible.

### Recording Operations — ALWAYS do this when:
- Adding, editing, or deleting any entity (student, teacher, class, fee, location, etc.)
- Changing status (active/inactive/graduated)
- Importing/exporting data
- Changing system settings

### Pattern:
```php
require_once '../classes/ActivityLog.php';
ActivityLog::logCreate('target_type', $id, $name, ['key1' => 'val1', 'key2' => 'val2']);
ActivityLog::logUpdate('target_type', $id, $name, ['changes' => [...], 'some_field' => $value]);
ActivityLog::logDelete('target_type', $id, $name, ['relevant_detail' => $value]);
```

### Future-Proofing Rules — CRITICAL ⚠️

1. **When adding a NEW field to any entity's form**: Include it in the `$details` array of the corresponding `ActivityLog::log*()` call. This ensures it appears automatically in ALL log display pages.

2. **When adding a NEW field to track for update changes**: Add it to the `$trackFields` array in the update handler so changes are recorded.

3. **New detail keys in Arabic**: Add the key→Arabic label mapping to `ActivityLog::getDetailKeyLabel()` in `classes/ActivityLog.php`. This single dictionary powers ALL log display pages.

4. **New target types**: Add the target type→Arabic label to `ActivityLog::getTargetLabel()` in `classes/ActivityLog.php`.

5. **Displaying log details**: ALWAYS use `ActivityLog::formatDetailsHtml($details, $format)` to render details. Two formats:
   - `'inline'` — `key: value<br>` (for main activity_logs.php page)
   - `'badge'` — badge per key-value (for tab log sections like fee_payments)
   - NEVER hardcode detail key rendering — the shared method handles all keys dynamically

6. **Log display pages are DYNAMIC**: All log tabs iterate JSON keys dynamically via `formatDetailsHtml()`. Adding new detail keys will automatically appear — no display code changes needed. Just:
   - Add the key to `getDetailKeyLabel()` if you want an Arabic label (otherwise raw key shows)
   - Pass the data in the `$details` array when logging

### Files involved:
- `classes/ActivityLog.php` — Central class with `getDetailKeyLabel()`, `getTargetLabel()`, `formatDetailsHtml()`
- `admin/activity_logs.php` — Global log page (uses `formatDetailsHtml('inline')`)
- Log tabs in entity pages (students, fee_payments, etc.) — use `formatDetailsHtml('badge')` or custom `buildDetailHtml()`

---

## Activity Log — Field Tracking System — MANDATORY ⚠️

> **هذا القسم يكمّل القسم السابق.** كل أداة AI (Copilot, Codex, Claude, Cursor, ZCode, Antigravity) MUST read this before adding/editing any field in student/staff forms.

The system uses **three coordinated functions per entity** to track changes accurately. Missing ANY one causes broken logging.

### The Three-Function Pattern (per entity)

For each entity (student, staff, ...), three functions work together:

1. **`get_{entity}_activity_snapshot(PDO $db, int $id): array`**
   - Returns ALL tracked fields for the entity in one SELECT.
   - Called BEFORE saving (to get old values) and AFTER saving (to get new values).
   - MUST return exactly the same field keys as the `tracked` list in the builder.

2. **`build_{entity}_activity_details(array $before, array $after, bool $passwordChanged = false): ?array`**
   - Compares `$before` vs `$after` field-by-field using strict `(string)$old !== (string)$new`.
   - Returns `['summary' => ..., 'changes' => ['field' => ['from' => old, 'to' => new], ...]]` OR
   - Returns **`null`** if no changes detected (this prevents empty log entries — MANDATORY).
   - The `$tracked`/`$fields` array inside this function is the **single source of truth** for what gets logged.

3. **`ActivityLog::logUpdate($target_type, $id, $name, $details)`**
   - Only called if `build_*_activity_details()` returned non-null.

### ✅ WORKFLOW: Adding a NEW field to a student/staff form

**When you add a new `<input name="new_field">` to a form, you MUST update all 5 points:**

```php
// STEP 1: Database — add the column (via migration)
ALTER TABLE student_profiles ADD COLUMN new_field VARCHAR(255) NULL;

// STEP 2: Form save handler — add to the $profileFields / save list
$profileFields = [..., 'new_field', ...];

// STEP 3: get_student_activity_snapshot() — add to the SELECT query
$stmt = $db->prepare("SELECT ..., sp.new_field, ... FROM ...");

// STEP 4: build_student_activity_details() — add to the $tracked array
$tracked = [..., 'new_field', ...];

// STEP 5: ActivityLog::getDetailKeyLabel() — add Arabic label
'new_field' => 'الحقل الجديد',
```

**If you skip Step 3 or 4:** the field will save correctly but changes will NOT appear in the log.
**If you skip Step 5:** the field appears with its raw English key instead of Arabic.

### 🔴 Scope Rule — Each Page Logs ONLY Its Own Form — MANDATORY ⚠️

The activity log tab inside each entity page must show **only operations performed from that page's form**. Do NOT log fields managed by other pages.

| Page | Logs (target_type) | Does NOT log (managed elsewhere) |
|------|--------------------|------------------------------------|
| `admin/students.php` | `student` (form fields only) | `student_account`, `student_mark` (→ student_accounts.php, assessment pages) |
| `admin/staff.php` | `staff` (form fields only) | `staff_account`, `staff_role` (→ staff_accounts.php), `staff_financial` (→ staff_financial_data.php) |

**Concrete consequences:**
- In `students.php`, the snapshot/builder MUST NOT track `username`, `password`, `role`, `status` — these belong to `student_accounts.php`. Only `name`, `class_id`, and `student_profiles.*` fields are tracked.
- In `staff.php`, the snapshot/builder MUST NOT track `username`, `role`, `status`, `is_supervisor` (→ staff_accounts.php) NOR `basic_salary`, `allowance_*`, `deduction_*`, `net_salary`, `advances_data`, `other_*_data` (→ staff_financial_data.php).
- Each page's log filter (`$actLogFilters['target_types']` / `$staffLogFilters['target_types']`) MUST be scoped to its own target types only.

### 🔴 Empty Log Prevention — MANDATORY ⚠️

The `build_*_activity_details()` function MUST return `null` when no actual field changed. The caller MUST check for `null` before logging:

```php
$details = build_student_activity_details($oldActivityData, $newActivityData, $passwordChanged);
if ($details !== null) {
    ActivityLog::logUpdate('student', $userId, $user->name, $details);
}
```

**NEVER** log a record with `summary: "تم الحفظ دون تغيير"` — this clutters the audit trail.

### Display Formats for `formatDetailsHtml($details, $format)`

| Format | Use case | Output |
|--------|----------|--------|
| `'diff_table'` | **Default for entity pages** (students.php, staff.php) | Mini-table: الحقل / قبل (red `<del>`) / بعد (green) |
| `'inline'` | Global `activity_logs.php` page | `label: value` separated by `<br>` |
| `'badge'` | Compact tabs (e.g. fee_payments log tab) | One badge per key-value |

**Use `'diff_table'`** in the activity log tab of entity form pages — it makes before/after changes visually clear without bloating the row height.

### Target Type Conventions

- Use the **base entity type** (`'student'`, `'staff'`, `'teacher'`, `'specialist'`) for form operations — NOT the account type.
- `'student_account'` / `'staff_account'` / `'staff_role'` are reserved for the accounts/permissions pages.
- `'staff_financial'` is reserved for `staff_financial_data.php`.
- Add new types to `ActivityLog::getTargetLabel()`.

### Current function locations

| Function | File:Line |
|----------|-----------|
| `get_student_activity_snapshot()` | `admin/students.php` |
| `build_student_activity_details()` | `admin/students.php` |
| `get_staff_activity_snapshot()` | `admin/staff.php` |
| `build_staff_activity_details()` | `admin/staff.php` |
| `getDetailKeyLabel()` / `getTargetLabel()` / `formatDetailsHtml()` | `classes/ActivityLog.php` |

---

## File Structure Conventions
- `config/` — Configuration files
- `classes/` — PHP classes
- `includes/` — Shared includes (admin_header, admin_footer, session_config, ajax_handlers)
- `admin/` — Admin panel pages
- `teacher/` — Teacher panel pages
- `student/` — Student panel pages
- `specialist/` — Specialist panel pages
- `assets/` — CSS, JS, images

## Code Style
- Arabic comments for business logic, English for technical comments
- PHP class names PascalCase, methods camelCase
- Database columns snake_case
- Session keys: `user_id`, `role`, `name`, `csrf_token`
- Always use prepared statements with positional `?` or named `:param` — NEVER concatenate SQL

## Tab Form Design (staff.php style)
- Tabs use pill-style gradient design with `linear-gradient(135deg, #f8fafc, #e2e8f0)` background
- Active tab: white text + blue gradient bg + shadow
- Section headers inside tabs use `.tab-section-title` with color variants: `.blue`, `.green`, `.amber`, `.purple`, `.red`, `.cyan`
- Form fields: rounded 8px borders, blue focus glow

---

## Teacher Page Structure — MANDATORY ⚠️

Teacher pages that use `teacher_header.php` / `teacher_footer.php` MUST follow the **same visual patterns** as admin pages. The teacher header provides a horizontal navbar (no sidebar), but all content styling must match admin conventions.

### Required Includes:
```php
<?php
$page_title = "عنوان الصفحة";
$custom_page_title = true; // prevents teacher_header from rendering default title

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('teacher');

$database = new Database();
$db = $database->getConnection();

// ... page logic ...

require_once '../includes/teacher_header.php';
?>
```

### CSS Already Loaded by teacher_header.php:
- `style.css`, `premium-dashboard.css`, `buttons.css` — same as admin
- jQuery loaded in `<head>` (same as admin)
- DataTables CSS loaded

### Page Header Pattern (Admin-Style):
```html
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-icon me-2 text-primary"></i>عنوان الصفحة</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
        <button class="btn btn-success shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus-circle me-2"></i>إضافة جديد
        </button>
    </div>
</div>
```

### Stat Cards — Use `.stat-card` Pattern:
```html
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
      <div class="stat-card-icon"><i class="fas fa-icon"></i></div>
      <div class="stat-card-info">
        <div class="stat-card-number counter" data-target="RAW_VALUE">0</div>
        <div class="stat-card-label">Label</div>
      </div>
    </div>
  </div>
</div>
```
Use the centralized `.stat-card` styles from `premium-dashboard.css`. Do not add or duplicate stat-card CSS in the teacher page. Preserve the `counter`, raw `data-target`, and initial `0` pattern used by `admin/ui_preview.php`.

### Tables And Filters:
Teacher free-list pages use the same free-list structure as `admin/ui_preview.php`:
```html
<form method="GET" class="admin-filter-bar">
  <div class="admin-filter-controls"><!-- filters --></div>
  <div class="admin-filter-actions"><!-- reset/settings/search --></div>
</form>
<div class="admin-list-surface">
  <div class="admin-table-wrap">
    <table class="table table-hover table-striped datatable admin-data-table"><!-- rows --></table>
  </div>
</div>
```
Only explicit functional tools may use the `admin-card-surface` card pattern documented above.

### Back-to-Portal Button — MANDATORY:
Every teacher page MUST include a "العودة للبوابة" button in the page header toolbar linking to `portal.php`. Use `btn-outline-secondary` style.

### NEVER ❌:
- ❌ Create standalone HTML pages with their own `<!DOCTYPE>` and `<head>` — ALWAYS use `teacher_header.php` / `teacher_footer.php`
- ❌ Duplicate CSS classes like `.portal-back-btn` in page-level `<style>` blocks
- ❌ Use custom `.page-header` gradient — use the admin-style `d-flex border-bottom` pattern
- ❌ Use custom `.act-stat` or similar — use `.stat-card` gradient pattern
- ❌ Wrap a free-list table or its filters in a card; use `admin-filter-bar` and `admin-list-surface`

---

## National ID & Phone Rules — MANDATORY ⚠️

### National ID (all modules)
- Any field labeled `الرقم القومي` MUST accept digits only and exactly 14 digits.
- Frontend requirements: `maxlength="14"`, `inputmode="numeric"`, regex/validation for 14 digits.
- Backend requirements: strict server-side validation (reject invalid values with clear message).

### Mobile Number (all modules)
- Any field labeled `رقم الموبايل` MUST accept digits only and exactly 11 digits.
- Frontend requirements: `maxlength="11"`, `inputmode="numeric"`, regex/validation for 11 digits.
- Backend requirements: strict server-side validation (reject invalid values with clear message).

### Landline Number (all modules)
- Any landline field (`هاتف أرضي` / `تليفون المنزل`) MUST accept digits only.
- No fixed length is required for landline.
- Backend validation must reject non-digit values.

---

## Other Option Behavior — MANDATORY ⚠️

- In ANY dropdown/select that contains `أخرى` / `other`, selecting it MUST show a text input to capture the custom value.
- Hiding rules:
- If user switches from `أخرى` to a standard option, hide and clear the custom text input.
- Submission rules:
- Backend must read and save custom value when `أخرى` is selected.

---

## Data Form Layout Standards — MANDATORY ⚠️

- In student/staff/guardian forms, separate `بيانات العنوان` from `بيانات التواصل` into different sections.
- Keep contact validation rules consistent with the phone rules above.
- In all data-entry screens, provide an `إضافة بيانات أخرى` area at the end of the form with:
- `مسمى البيانات`
- `بيانها`

---

## AJAX State Updates — MANDATORY ⚠️

After any AJAX operation that modifies data (transfer, delete, toggle status, etc.), **NEVER** use `window.location.reload()`. Instead:

1. **Update the affected UI elements dynamically** — remove/move rows, reload cards, update counters
2. **Preserve user state** — checkbox selections, loaded dynamic content, scroll position, active filters
3. **Update summary counts** — if a summary table shows totals, update those numbers via JS after the operation

### Pattern:
```javascript
// After successful AJAX operation:
// 1. Remove/update the affected row with fade animation
row.style.transition = 'opacity 0.3s';
row.style.opacity = '0';
setTimeout(function() { row.remove(); }, 300);

// 2. Reload affected dynamic cards (if applicable)
if (loadedCards[oldId]) {
    delete loadedCards[oldId];
    document.getElementById('card-' + oldId).remove();
    loadCard(oldId); // re-fetch via AJAX
}

// 3. Update summary counters
var countEl = document.querySelector('[data-count-id="' + id + '"]');
if (countEl) countEl.textContent = parseInt(countEl.textContent) + delta;

// 4. Close modal after brief success message
setTimeout(function() {
    bootstrap.Modal.getInstance(modal).hide();
}, 1200);
```

### Rules:
- `window.location.reload()` is ONLY acceptable when the entire page structure must change (e.g., role/permission change affecting the current user)
- For row transfers between lists/cards, re-fetch both the source and destination via AJAX
- Always update footer/header totals when counts change

---

## Adding New Portal Services — MANDATORY ⚠️

When adding a new service/feature accessible from the teacher or student portal, you MUST update **all three** registration points. Missing any one will cause the service card to not appear.

### Checklist (all 3 steps required):

1. **`admin/stages.php` — Service Registry**
   - Add the service key to `$available_teacher_services` array (for teacher services) or `$available_services` array (for student services)
   - Format: `'service_key' => 'الاسم بالعربي'`
   - This allows the admin to enable/disable the service per stage

2. **`teacher/portal.php` or `student/portal.php` — Navigation Card**
   - Add a `<a href="page.php" class="nav-button">` block inside the `.nav-grid` container
   - Wrap with the visibility condition: `<?php if (!$has_stage_config || in_array('service_key', $allowed_teacher_services)): ?>`
   - The `service_key` must match exactly what was added in step 1

3. **`includes/teacher_header.php` or equivalent — Inner Page Navigation**
   - Add a nav link for inner-page navigation (navbar)
   - This is separate from the portal card

### Portal Visibility Logic:
- `$has_stage_config = false` → ALL services show (no stage restrictions configured)
- `$has_stage_config = true` → Only services listed in the stage's `teacher_services` / `services` JSON column appear
- Per-user override via `user_services` table takes precedence over stage config
- The admin enables services from `admin/stages.php` → edit stage → checkboxes

### Example — Adding "الأنشطة التفاعلية":
```php
// 1. admin/stages.php
$available_teacher_services = [
    // ... existing services ...
    'activities' => 'الأنشطة التفاعلية'
];

// 2. teacher/portal.php (inside .nav-grid)
<?php if (!$has_stage_config || in_array('activities', $allowed_teacher_services)): ?>
<a href="activities.php" class="nav-button">
    <i class="fas fa-gamepad"></i>
    <h3>الأنشطة التفاعلية</h3>
    <p>ألعاب وأنشطة تعليمية تفاعلية</p>
</a>
<?php endif; ?>
```

### NEVER ❌:
- Add a portal card without registering the service key in `stages.php`
- Use a different service key in `portal.php` than what's in `stages.php`
- Forget the `!$has_stage_config ||` fallback (causes card to disappear when no stage config exists)

---

## Table Header Toolbars — MANDATORY ⚠️

عند تعديل أو إنشاء صفحات جديدة في لوحة التحكم الإدارية، يجب الالتزام بالتنسيقات والأنماط البصرية التالية لضمان توحيد المظهر:

### موضع الأزرار
- عندما تضاف أزرار أو روابط إجراءات داخل هيدر الكارد الأزرق (`card-header bg-primary text-white`).

### الستايل المعتمد
- يجب استخدام اللون الأبيض الممتلئ كليًا للأزرار: `class="btn btn-light btn-sm"`
- الأيقونات: تُستخدم أيقونات FontAwesome المناسبة مع هامش صغير (`me-1`).

### القيود
- **يُمنع** استخدام أزرار الحدود المفرغة (`btn-outline-light` أو `btn-outline-primary`) داخل الهيدر الأزرق (ضعف التباين).
- **يُمنع** استخدام الأزرار الرمادية (`btn-secondary`) داخل الهيدر الأزرق.

---

## Profile Details Layout — MANDATORY ⚠️

- **الهيكل العام:** يُمنع تمامًا استخدام المربعات أو البطاقات الفرعية المغلّفة (nested cards/boxes) لعرض البيانات الفردية لتجنب الهدر الرأسي.
- **تخطيط الأسطر المخططة (Underlined Flat Rows):**
  - تُسرد الحقول داخل شبكة Bootstrap (`col-md-4`) مع خط فاصل سفلي خفيف:
    `class="d-flex align-items-center py-2 border-bottom border-light"`
  - محاذاة الأيقونات رأسيًا بعرض ثابت:
    `<i class="fas fa-... me-2" style="width: 20px; text-align: center;"></i>`
  - تنسيق النصوص: المسمى باللون الثانوي والقيمة باللون الداكن العريض:
    `<span class="text-secondary me-2">كود الطالب:</span><strong class="text-dark">...</strong>`

---

## Dashboard & Statistics Pages Standards — MANDATORY ⚠️

جميع صفحات الإحصائيات واللوحات الرئيسية (مثل `index.php`, `school_statistics.php`, `student_statistics.php`... إلخ) يجب أن تتبع معايير التنسيق التالية:

### 1. توحيد الأزرار العلوية:
- **تخصيص لوحة الإحصائيات/التحكم:** يجب استخدام `btn btn-header-premium btn-print-soft` مثل `school_statistics.php` وصفحة الاختبار.
- **طباعة التقارير:** يجب استخدام المسمى `طباعة` مع `btn btn-header-premium btn-print-soft` (خلفية بيضاء ونص أسود).

### 2. ميزة السحب والإفلات (Drag & Drop) - Single Unified Grid:
- **المعمارية الموحدة (Unified Architecture):** يُمنع استخدام صفوف (`row`) متعددة لتجميع البطاقات. يجب وضع جميع البطاقات (Widgets, Charts, KPIs) داخل حاوية شبكية واحدة رئيسية لضمان حرية السحب والإفلات وتجنب مشاكل التعليق (Jittering).
- **الحاوية الرئيسية:** يجب أن تكون الحاوية بالشكل التالي: `<div class="row sortable-dashboard w-100 m-0" id="global-dashboard-grid">`
- **حجم العناصر:** جميع العناصر بداخلها يجب أن تستخدم نظام أعمدة Bootstrap (`col-12`, `col-lg-6`, `col-md-4` إلخ).
- **مقبض السحب (Drag Handle):** يجب إضافة أيقونة السحب داخل ترويسة (`card-header`) البطاقات الكبيرة كالتالي:
  `<i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>`

---

## Modal Filtering & Action Coherence — MANDATORY ⚠️

### 1. Filters inside Student Selection Modals
Whenever a modal contains a dropdown to select a student, you MUST provide cascading filters (Stage → Grade → Class) inside the modal to filter the student list dynamically:
- Use standard Bootstrap layouts (e.g., inline grid) to place Stage, Grade, and Class dropdowns above the student select field.
- Leverage vanilla JavaScript to dynamically filter the student dropdown options using custom `data-*` attributes (`data-stage`, `data-grade`, `data-class`) on the student option tags.
- Perform this filtering entirely on the client-side to avoid unnecessary network latency.

### 2. Action Button Style Coherence
- Quick-add actions (such as "إضافة تقييم سريع" in evaluations) use the solid green `btn-success` add style unless the approved reference page defines a dedicated semantic class.
- Ensure consistency in the visual weight and hierarchical placement of buttons on the same horizontal level.

---

## Reference Page Matching Rule — MANDATORY ⚠️
- عند طلب المستخدم دراسة أو تطبيق تنسيقات وطريقة عرض من **صفحة مرجعية محددة** إلى صفحة أخرى:
  1. **يجب فحص كود الصفحة المرجعية أولاً بالكامل** للوقوف على كافة الكلاسات الدقيقة (مثل كلاسات الأزرار `btn-outline-*` مقابل `btn-*` وكلاسات البادجات والهياكل).
  2. **تطبق نفس الكلاسات والتركيب البصري الصريح** الموجود في الصفحة المرجعية دون تغييره أو استبداله باجتهاد شخصي، لضمان المطابقة التامة والشاملة لما يراه المستخدم في الصفحة المرجعية.


## Global Page Title Icons & Tabs Standards — MANDATORY ⚠️

### 1. مطابقة وتلوين أيقونات عناوين الصفحات
- **المطابقة الشاملة:** يجب أن تكون الأيقونة المجاورة لعنوان الصفحة في ترويسة الصفحة مطابقة تماماً للأيقونة المحددة لها في القائمة الجانبية (Sidebar) من حيث الشكل واللون.
- **التطبيق البرمجي الموحد:** يتم هذا التحديث تلقائياً عبر سكريبت المطابقة المكتوب في `admin_footer.php` والذي يفحص الصفحة الحالية ويطابقها مع خلية القائمة الجانبية ويستنسخ أيقونتها ونمطها اللوني.
- **عند إنشاء صفحات جديدة:** تأكد من إدراج الأيقونة الافتراضية المناسبة في عنوان الصفحة وتضمين رابط الصفحة في القائمة الجانبية بالهيدر لتعمل المطابقة التلقائية بامتياز.

### 2. معايير التبويبات (Tabs) والإحصائيات الملحقة بها
- **خط عريض (Bold):** جميع نصوص التبويبات (`.nav-tabs`, `.nav-pills`) في جميع صفحات النظام يجب أن تكون بخط عريض بشكل افتراضي (تم تفعيل هذا عبر قاعدة CSS عامة في `admin_header.php`).
- **موضع شارات الأرقام:** إذا كان التبويب يحتوي على عدّاد أو إحصائية رقمية (مثل عدد الطلاب، عدد الزيارات، عدد العمليات)، **يُمنع تماماً** وضع الرقم في ترويسة الكارت أو خارج التبويب.
- **التصميم المعتمد للشارات:** يجب إدراج الرقم مباشرة بجانب اسم التبويب داخل زر/رابط التبويب نفسه باستخدام الشارة الدائرية الملونة:
  `<span class="badge rounded-pill bg-primary ms-1">COUNT</span>`

---

> **نهاية ملف التعليمات الموحّد.** ابدأ أي تغيير معياري من `AGENTS.md` بوصفه المصدر الأعلى، ثم حدّث في الدفعة نفسها وثائق البنية/ADR ومهايئات الأدوات المتأثرة؛ لا تعدّل مهايئًا وحده ولا تنشئ مصدر قواعد موازياً.
