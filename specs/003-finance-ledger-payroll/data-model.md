# Data Model: Finance Ledger & Payroll

> All new tables are created via dated migrations in `database/migrations/` with preconditions + rollback. Money columns are `DECIMAL(14,2)` in the database; PHP represents money as integer minor units (piasters, EGP) via the `Money` value object — no PHP float for financial math. Every table has `created_at`, `created_by` plus lifecycle timestamps where noted. Schema is inspected only from an isolated `*_test` database; no production dump is used as a truth source. **Full GL + P&L is in scope for v1.**

## 0. Unified Sub-ledger Truth Source (MANDATORY — students AND staff)

A single pair of append-only sub-ledger tables is the source of truth for ALL party balances (students and staff). Domain tables (charges, allocations, refunds, payroll, advances, vouchers) hold operational detail only; balances are computed EXCLUSIVELY from posted sub-ledger lines — never from domain-table sums.

### finance_subledger_accounts  (one per party × stable scope × currency)
- `id`, `party_type` (enum: student, staff), `party_id` (int — FK to `users`), `scope_key` (VARCHAR — academic-year ID for students; the constant `STAFF_GLOBAL` for staff), `currency`, `status` (enum: active, closed), `created_at`, `created_by`
- unique(`party_type`, `party_id`, `scope_key`, `currency`)
- A staff account is lifetime-stable and MUST NOT be keyed by compensation contract, payroll model, or employment episode. Those references belong on source/domain transactions.
- **NO authoritative balance column.** All balances derived from `finance_subledger_lines`.

### finance_subledger_transactions  (one header per source operation; append-only)
- `id`, `subledger_account_id` (FK), `source_type` (enum: charge, adjustment, receipt, refund_allocation, refund_unapplied_credit, unapplied_credit_application, payroll_run_item_posting, payroll_settlement_item_posting, payroll_payment, advance_issue, advance_cash_repayment, advance_payroll_deduction, advance_write_off, reversal, opening_balance), `source_ref_id` (nullable logical reference to the owned domain row; not a polymorphic database FK), `source_idempotency_key` (CHAR(32)), `status` (enum: **draft, posted** ONLY — no `reversed` status), `batch_id`, `request_id`, `posted_at`, `posted_by`, `reversal_of` (self-FK, nullable — used only when this transaction reverses a previously posted transaction), `created_at`, `created_by`
- unique(`source_idempotency_key`) — one sub-ledger transaction per source operation; a retry returns the original.
- **`is_reversed`** is a DERIVED boolean (true if another transaction has `reversal_of` = this id), NOT a stored status. The original is NEVER excluded from SUM. Both original and reversal are `status=posted` and counted together; `original + reversal = 0`.

### finance_subledger_lines  (append-only; signed deltas per bucket)
- `id`, `transaction_id` (FK, **ON DELETE RESTRICT** — NOT CASCADE, because the ledger is append-only), `line_number` (int), `bucket_code` (VARCHAR), `amount_delta` DECIMAL(14,2) (signed), `installment_id` (FK nullable), `cost_center_id` (FK nullable), `description`
- unique(`transaction_id`, `line_number`)
- Append-only: lines are NEVER edited or hard-deleted; correction is a new reversal transaction with opposite deltas.

### Bucket codes (per party type)

**Student buckets:**
- `STUDENT_OUTSTANDING_DUE` — what the student owes (charges increase, allocations decrease, refund_allocation restores).
- `STUDENT_UNAPPLIED_CREDIT` — overpayment/prepayment held as credit (creation increases, application decreases, refund_unapplied_credit decreases).

**Staff buckets:**
- `STAFF_PAYROLL_PAYABLE` — net salary owed to staff (payroll posting increases, payroll payment decreases).
- `STAFF_ADVANCE_RECEIVABLE` — advance owed BY staff to school (advance issue increases; cash repayment, payroll deduction, and approved write-off decrease).

### Derived equations (computed EXCLUSIVELY from posted `finance_subledger_lines`)

For each `finance_subledger_accounts` row:
- `bucket_balance(bucket_code) = SUM(amount_delta) OVER posted sub-ledger lines WHERE bucket_code = X for that account`
- Student: `outstanding_due = bucket_balance(STUDENT_OUTSTANDING_DUE)`; `unapplied_credit = bucket_balance(STUDENT_UNAPPLIED_CREDIT)`; `net_account_position = outstanding_due − unapplied_credit`
- Staff: `payroll_payable = bucket_balance(STAFF_PAYROLL_PAYABLE)`; `advance_receivable = bucket_balance(STAFF_ADVANCE_RECEIVABLE)`. A retroactive settlement is a separate source operation but affects `STAFF_PAYROLL_PAYABLE`, so normal payroll payment clears it.

> No equation subtracts `refunds` or `reversals` as fixed categories — their deltas are already encoded in the sub-ledger lines they post.

### Movement type → signed deltas (authoritative table)

| Movement type | bucket | `amount_delta` |
|---|---|---|
| Charge (debit) | STUDENT_OUTSTANDING_DUE | +net_due |
| Adjustment debit | STUDENT_OUTSTANDING_DUE | +amount |
| Credit note / debit note | STUDENT_OUTSTANDING_DUE | −amount |
| Payment allocation (on receipt) | STUDENT_OUTSTANDING_DUE | −allocated_amount |
| Unapplied credit creation (on receipt) | STUDENT_UNAPPLIED_CREDIT | +amount |
| Unapplied credit application | STUDENT_OUTSTANDING_DUE | −applied_amount; STUDENT_UNAPPLIED_CREDIT | −applied_amount |
| Refund of allocation | STUDENT_OUTSTANDING_DUE | +amount |
| Refund of unapplied credit | STUDENT_UNAPPLIED_CREDIT | −amount |
| Payroll posting | STAFF_PAYROLL_PAYABLE | +net |
| Payroll payment | STAFF_PAYROLL_PAYABLE | −amount |
| Advance issue | STAFF_ADVANCE_RECEIVABLE | +amount |
| Advance cash repayment | STAFF_ADVANCE_RECEIVABLE | −amount |
| Advance payroll deduction | STAFF_ADVANCE_RECEIVABLE | −amount |
| Advance write-off | STAFF_ADVANCE_RECEIVABLE | −approved remaining amount (independent operation, not reversal of issue) |
| Payroll settlement posting (retroactive) | STAFF_PAYROLL_PAYABLE | ±net_difference |
| Voucher (expense) | (GL only; no sub-ledger bucket unless AP) | — |
| Reversal transaction | same bucket(s) as original | opposite deltas |

### Receipt posting = ONE sub-ledger transaction (multi-line)

A receipt is ONE source operation → ONE `finance_subledger_transactions` row with multiple `finance_subledger_lines`:
- One negative line on `STUDENT_OUTSTANDING_DUE` for each allocation (−allocated_amount per installment).
- One positive line on `STUDENT_UNAPPLIED_CREDIT` for the overpayment amount (if any).
- All allocation rows and unapplied-credit rows created by the receipt carry the SAME `subledger_transaction_id`.
- ONE GL journal entry is created with the same `source_idempotency_key`.
- Subsequent refund/application/reversal operations are SEPARATE transactions, each with its own sub-ledger transaction + GL journal.

### Reversal semantics (precise)
- The original transaction remains `status=posted` and is ALWAYS counted in SUM.
- A reversal creates a NEW `finance_subledger_transactions` row (also `status=posted`) with `reversal_of` → original, carrying opposite-sign `finance_subledger_lines` in the same bucket(s).
- `is_reversed` on the original is a DERIVED flag (exists another transaction with `reversal_of` = this id), NOT a stored status and NOT a reason to exclude.
- Invariant: `SUM(original lines) + SUM(reversal lines) = 0` per bucket.

## 1. Core / Shared

### finance_charge_types
- `id`, `code` (enum: tuition, bus, books, activities, events, uniform, other_services, opening_balance), `name_ar`, `is_system` (bool)
- unique(`code`)

### finance_periods
- `id`, `academic_year_id` (FK), `name`, `start_date`, `end_date`
- `status` (enum: open, closed, reopened), `closed_at`, `closed_by`, `reopen_reason`, `reopened_by`, `reopened_at`
- unique(`academic_year_id`, `name`)
- `FinancePeriodGuard` extends `AcademicYearWriteGuard`; reopen is maker-checker, audited.

### finance_cashboxes
- `id`, `code`, `name`, `type` (enum: cash, bank), `is_active`, `accountability_role`, `receipt_prefix` (nullable)
- unique(`code`)

### finance_bank_accounts
- `id`, `cashbox_id` (FK), `bank_name`, `iban_masked`, `account_last4`, `currency`
- unique(`cashbox_id`)

### finance_cashbox_settlements
- `id`, `cashbox_id` (FK), `period_id` (FK), `settlement_date`, `opening_float`, `expected_total`, `counted_total`, `difference`, `status` (enum: open, settled, adjusted), `settled_by`, `settled_at`

### finance_receipt_number_sequences
- `id`, `cashbox_id` (FK), `academic_year_id` (FK), `next_sequence` (int)
- unique(`cashbox_id`, `academic_year_id`); atomic increment under `FOR UPDATE`.

### finance_import_batches
- `id`, `batch_id` (CHAR(32)), `schema_version`, `source_file_ref`, `status` (enum: staged, posted, abandoned), `posted_at`, `posted_by`, `approved_by` (maker-checker), `row_count`, `error_count`
- unique(`batch_id`)

### finance_import_rows
- `id`, `import_batch_id` (FK, cascade), `row_number`, `payload_json`, `validation_status`, `error_messages_json`

## 2. Fee Plans, Versions, Installments

### finance_fee_plans
- `id`, `charge_type_id` (FK), `academic_year_id` (FK), `stage_id`, `grade_id`, `name`, `status` (enum: draft, active, archived)
- unique(`charge_type_id`, `academic_year_id`, `grade_id`)

### finance_fee_plan_versions
- `id`, `fee_plan_id` (FK), `version_number`, `snapshot_json`, `effective_from`, `effective_to` (nullable), `status` (enum: draft, active, superseded), `superseded_at`, `created_by`, `created_at`
- unique(`fee_plan_id`, `version_number`)

### finance_fee_plan_installments
- `id`, `fee_plan_version_id` (FK, cascade), `installment_name`, `gross_amount` DECIMAL(14,2), `due_date`, `display_order`

## 3. Student Accounts, Contracts, Charges, Installments (domain detail)

### finance_student_accounts
- `id`, `student_id` (FK→`users`), `academic_year_id` (FK), `currency`, `status`, `subledger_account_id` (FK→`finance_subledger_accounts`)
- unique(`student_id`, `academic_year_id`)
- **NO authoritative balance column.**

### finance_student_contracts
- `id`, `student_account_id` (FK), `fee_plan_version_id` (FK), `snapshot_json`, `signed_at`, `status` (enum: draft, active, closed), `created_by`, `created_at`
- unique(`student_account_id`, `fee_plan_version_id`)

### finance_student_charges  (domain detail; sub-ledger is the truth source)
- `id`, `student_account_id` (FK), `student_contract_id` (FK), `charge_type_id` (FK), `direction` (enum: debit, credit), `gross_amount`, `discount_amount`, `adjustment_amount`, `net_due`, `due_date`, `source` (enum: plan, manual, import, opening_balance, prior_year), `academic_year_id` (FK), `status` (enum: pending, posted), `posted_at`, `posted_by`, `reversal_of` (self-FK, nullable), `subledger_transaction_id` (FK→`finance_subledger_transactions`), `batch_id`, `request_id`, `created_at`, `created_by`
- `request_id` matches the sub-ledger transaction's `source_idempotency_key`.

### finance_charge_installments  (the due-unit for allocation)
- `id`, `student_charge_id` (FK, cascade), `installment_name`, `net_amount` DECIMAL(14,2), `due_date`, `display_order`, `status` (enum: pending, partially_paid, paid)

## 4. Discounts (truly versioned; scope-aware; MariaDB-safe activation)

### finance_discount_rules  (versioned; scope-aware)
- `id`, `code` (enum: sibling, employee_child, scholarship, hardship, manual, exemption, promotional), `name_ar`, `priority` (int), `combinable` (bool, default false), `cap_amount` DECIMAL(14,2) nullable, `effective_from`, `effective_to` (nullable), `status` (enum: draft, active, superseded, archived), `academic_year_id` (FK), `scope_charge_type_key` (VARCHAR NOT NULL — `'ALL'` for general, or charge_type `code` for specific), `version_number` (int), `superseded_at`
- unique(`code`, `academic_year_id`, `scope_charge_type_key`, `version_number`)
- **MariaDB constraint note**: no partial index assumed. Active-version uniqueness is enforced by an **activation transaction** using `SELECT ... FOR UPDATE` on the `(code, academic_year_id, scope_charge_type_key)` row-set: it checks no `status='active'` version exists, then activates. Only ONE active version per policy scope is allowed. Future versions remain `status='draft'` until activation. If a generated/computed active-key column is used, its compatibility with the actual MariaDB version MUST be documented.
- A used version CANNOT be edited; a new version is created instead.

### finance_discount_awards
- `id`, `student_account_id` (FK, cascade), `discount_rule_id` (FK), `awarded_amount` DECIMAL(14,2), `reason`, `requested_by`, `approved_by` (maker-checker), `approved_at`, `status` (enum: pending, approved, rejected, revoked), `document_ref`, `created_at`

### finance_discount_applications
- `id`, `discount_award_id` (FK, cascade), `student_charge_id` (FK), `student_charge_installment_id` (FK nullable), `applied_amount` DECIMAL(14,2), `created_at`

## 5. Collection, Receipts, Allocation, Unapplied Credits (domain detail)

### finance_receipts  (reversal-only; numbering per cashbox/year)
- `id`, `receipt_number` (display), `cashbox_id` (FK), `academic_year_id` (FK), `sequence_number` (int), `student_account_id` (FK), `payment_method` (enum: cash, bank_transfer, check, card, other), `gross_amount` DECIMAL(14,2), `currency`, `idempotency_key`, `status` (enum: draft, posted, reversed), `posted_at`, `posted_by`, `reversed_at`, `reversed_by`, `reversal_of` (self-FK), `approved_by` (maker-checker), `notes`, `subledger_transaction_id` (FK→`finance_subledger_transactions`), `batch_id`, `request_id`, `created_at`, `created_by`
- unique(`cashbox_id`, `academic_year_id`, `sequence_number`); unique(`idempotency_key`)
- **Receipt = ONE source operation**: `source_type=receipt`, `source_ref_id=receipt.id`. One sub-ledger transaction with multi-line (allocation lines + unapplied credit line). One GL journal with the same `source_idempotency_key`.
- Registered in `REVERSAL_ONLY_TABLES`.

### finance_payment_allocations  (domain detail; targets finance_charge_installments)
- `id`, `receipt_id` (FK, cascade), `student_charge_installment_id` (FK), `allocated_amount` DECIMAL(14,2), `status` (enum: applied, reversed), `reversal_of` (self-FK, nullable), `subledger_transaction_id` (FK — same as receipt's), `batch_id`, `request_id`, `created_at`
- All allocations from one receipt share the SAME `subledger_transaction_id`.

### finance_unapplied_credits  (domain detail)
- `id`, `student_account_id` (FK), `receipt_id` (FK), `amount` DECIMAL(14,2), `status` (enum: open, applied, refunded, reversed), `applied_at`, `reversal_of` (self-FK, nullable), `subledger_transaction_id` (FK — same as receipt's), `batch_id`, `request_id`, `created_at`, `created_by`

### finance_unapplied_credit_applications  (domain detail; partial application)
- `id`, `unapplied_credit_id` (FK, cascade), `student_charge_installment_id` (FK), `payment_allocation_id` (FK nullable), `applied_amount` DECIMAL(14,2), `status` (enum: applied, reversed), `reversal_of` (self-FK, nullable), `subledger_transaction_id` (FK — separate transaction from the receipt), `batch_id`, `request_id`, `created_at`, `created_by`

### finance_adjustments  (domain detail)
- `id`, `student_account_id` (FK), `adjustment_type` (enum: credit, debit), `amount` DECIMAL(14,2), `reason`, `source` (enum: manual, student_debt_write_off, credit_note, debit_note, migration_reconciliation, prior_year), `status` (enum: pending, posted), `posted_at`, `posted_by`, `approved_by` (maker-checker), `reversal_of` (self-FK), `subledger_transaction_id` (FK), `batch_id`, `request_id`
- `student_debt_write_off` is a separate credit adjustment, requires an independent approver, locks the target account/installment, and requires `0 < amount <= remaining_due`; it is not a payment allocation and not a reversal of the original charge.

### finance_refunds  (domain detail; refund_type distinguishes allocation vs unapplied-credit; separate transaction)
- `id`, `receipt_id` (FK), `refund_type` (enum: refund_allocation, refund_unapplied_credit), `payment_allocation_id` (FK nullable), `unapplied_credit_id` (FK nullable), `amount` DECIMAL(14,2), `payment_method`, `reason`, `status` (enum: pending, posted, reversed), `posted_at`, `posted_by`, `approved_by` (maker-checker), `reversal_of` (self-FK), `subledger_transaction_id` (FK — separate transaction), `batch_id`, `request_id`
- `refund_allocation` posts `STUDENT_OUTSTANDING_DUE` delta = +amount. `refund_unapplied_credit` posts `STUDENT_UNAPPLIED_CREDIT` delta = −amount.

## 6. Staff Compensation, Payroll, Advances (domain detail)

### staff_compensation_contracts
- `id`, `staff_id` (FK→`users`), `effective_from`, `effective_to` (nullable), `version_number`, `status` (enum: draft, active, superseded, expired), `approved_by`, `approved_at`, `provenance` (enum: business_decision, legacy_migration, other), `history_confidence` (enum: confirmed, uncertain), `created_by`, `created_at`
- unique(`staff_id`, `effective_from`, `version_number`); same-day legacy changes create a new immutable version rather than overwriting history.

### staff_compensation_contract_components  (PRIMARY source, not snapshot_json)
- `id`, `contract_id` (FK, cascade), `payroll_component_id` (FK), `amount` DECIMAL(14,2), `effective_from`, `effective_to` (nullable), `direction` (enum: earning, deduction), `status` (enum: active, superseded)

### payroll_components
- `id`, `code` (enum: basic, allowance_fixed, allowance_variable, bonus, overtime, insurance, tax, attendance_deduction, penalty, advance, other_deduction), `name_ar`, `direction` (enum: earning, deduction), `is_system`
- unique(`code`)

### payroll_periods  (uniqueness by finance_period + date range, NOT by name)
- `id`, `finance_period_id` (FK), `start_date`, `end_date`, `pay_date`, `status` (enum: open, closed)
- unique(`finance_period_id`, `start_date`, `end_date`)
- Monthly periodicity following the academic year.

### payroll_runs  (versions, settlements, reversal via reversal_of)
- `id`, `payroll_period_id` (FK), `version_number` (int), `status` (enum: draft, calculated, reviewed, approved, posted, paid, reversed), `batch_id`, `posted_at`, `posted_by`, `approved_by` (maker-checker), `reversed_at`, `reversed_by`, `reversal_of` (self-FK, nullable), `is_settlement` (bool), `created_at`
- unique(`payroll_period_id`, `version_number`)

### payroll_run_items
- `id`, `payroll_run_id` (FK, cascade), `staff_id` (FK), `contract_snapshot_json` (frozen copy, NOT primary), `gross` DECIMAL(14,2), `total_deductions` DECIMAL(14,2), `net` DECIMAL(14,2), `status` (enum: draft, locked, paid, reversed), `reversal_of` (self-FK), `payslip_ref_number`, `payment_status` (enum: unpaid, paid), `subledger_transaction_id` (FK — payroll posting posts STAFF_PAYROLL_PAYABLE +net)
- unique(`payroll_run_id`, `staff_id`)

### payroll_item_components
- `id`, `payroll_run_item_id` (FK, cascade), `payroll_component_id` (FK), `amount` DECIMAL(14,2), `direction`

### staff_advances
- `id`, `staff_id` (FK), `amount` DECIMAL(14,2), `issue_date`, `reason`, `status` (enum: active, repaid, written_off), `subledger_transaction_id` (FK — advance issue posts STAFF_ADVANCE_RECEIVABLE +amount), `created_by`, `created_at`
- `remaining_amount` is NOT a stored authoritative column; it is derived from `SUM(STAFF_ADVANCE_RECEIVABLE amount_delta)` on the staff sub-ledger, or a documented cache reconciled on demand. `repayment_plan_json` is display metadata only.

### staff_advance_installments  (PRIMARY source for repayment schedule)
- `id`, `staff_advance_id` (FK, cascade), `due_date`, `amount` DECIMAL(14,2), `status` (enum: pending, paid, overdue)
- This is the PRIMARY source for the repayment schedule; `repayment_plan_json` on `staff_advances` is NOT a parallel truth source.

### staff_advance_movements  (operational detail for repayment/deduction/write-off)
- `id`, `staff_advance_id` (FK), `movement_type` (enum: cash_repayment, payroll_deduction, write_off), `amount` DECIMAL(14,2), `cashbox_id` (FK nullable), `payroll_run_item_id` (FK nullable), `reason`, `status` (enum: pending, posted, reversed), `approved_by` (maker-checker for write_off), `approved_at`, `subledger_transaction_id` (FK), `reversal_of` (self-FK nullable), `batch_id`, `request_id`, `created_at`, `created_by`
- `cash_repayment` requires `cashbox_id` and no `payroll_run_item_id`; GL: `Dr Cash/Bank, Cr Staff Advance Receivable`.
- `payroll_deduction` requires `payroll_run_item_id`; GL: `Dr Payroll Payable, Cr Staff Advance Receivable`.
- `write_off` requires an independent approver and reason; GL: `Dr Advance Write-off Expense, Cr Staff Advance Receivable`.
- At posting, lock the staff sub-ledger account/advance rows, derive the current remaining receivable, and require `0 < amount <= remaining`. A write-off does **not** set `reversal_of` to the advance issue. `reversal_of` is used only to reverse an erroneous movement.

### payroll_payments  (reversal-only; posts STAFF_PAYROLL_PAYABLE −amount)
- `id`, `payroll_run_item_id` (FK), `cashbox_id` (FK), `amount` DECIMAL(14,2), `payment_method`, `status` (enum: posted, reversed), `posted_at`, `posted_by`, `approved_by` (maker-checker), `reversal_of` (self-FK), `subledger_transaction_id` (FK), `batch_id`, `request_id`

## 7. General Vouchers (expense, other_income, cash_transfer) — IN SCOPE v1

### finance_vouchers  (general operations; reversal-only; maker-checker)
- `id`, `voucher_number`, `voucher_type` (enum: expense, other_income, cash_transfer), `cashbox_id` (FK nullable — required for expense/other_income), `source_cashbox_id` (FK nullable), `destination_cashbox_id` (FK nullable), `amount` DECIMAL(14,2), `finance_period_id` (FK), `entry_date`, `cost_center_id` (FK nullable), `status` (enum: draft, posted, reversed), `posted_at`, `posted_by`, `approved_by` (maker-checker), `reversal_of` (self-FK), `batch_id`, `request_id`, `notes`, `created_at`, `created_by`
- unique(`voucher_number`); registered in `REVERSAL_ONLY_TABLES`.
- For `expense`/`other_income`: `cashbox_id` is required and transfer fields are NULL.
- For `cash_transfer`: `source_cashbox_id` and `destination_cashbox_id` are required, `cashbox_id` is NULL, source ≠ destination, and `amount > 0`.
- **Supplier/AP scope decision (RESOLVED)**: Supplier accounts payable (AP) is **OUT OF SCOPE for v1**. Vouchers are pure GL operations and never create a party sub-ledger transaction.

### finance_voucher_lines  (multi-line detail with GL mapping)
- `id`, `voucher_id` (FK, cascade), `account_id` (FK), `cost_center_id` (FK nullable), `debit` DECIMAL(14,2), `credit` DECIMAL(14,2), `description`
- Invariant: `SUM(debit) = SUM(credit)` per voucher (enforced at posting).

## 8. General Ledger (IN SCOPE for v1)

### accounting_accounts
- `id`, `code`, `name_ar`, `type` (enum: asset, liability, equity, revenue, expense), `parent_id` (self-FK), `is_active`, `is_control_account` (bool), `created_at`, `created_by`
- unique(`code`)

### accounting_cost_centers
- `id`, `code`, `name_ar`, `scope` (enum: stage, grade, bus, activity, department)
- unique(`code`)

### accounting_journal_entries  (reversal-only; exactly one per posted accounting source operation)
- `id`, `entry_number`, `finance_period_id` (FK), `entry_date`, `source_type` (enum: student_charge, receipt, refund, adjustment, unapplied_credit, payroll_run_item_posting, payroll_settlement_item_posting, payroll_payment, advance_issue, advance_cash_repayment, advance_payroll_deduction, advance_write_off, voucher, manual, import_row), `source_ref_id` (nullable), `source_idempotency_key` (CHAR(32)), `subledger_transaction_id` (nullable FK→`finance_subledger_transactions`, unique), `status` (enum: draft, posted ONLY), `batch_id`, `posted_at`, `posted_by`, `approved_by` (maker-checker for manual), `reversal_of` (self-FK)
- unique(`source_idempotency_key`)
- Party-affecting operations require `subledger_transaction_id` and share the same `source_idempotency_key` with that transaction. A payroll run is a batch; each `payroll_run_item` is one party-specific source operation with its own linked journal. Pure GL operations (voucher/manual journal not tied to a party) require `subledger_transaction_id = NULL`. Budget planning writes never create a journal.
- Invariant: `SUM(debit) = SUM(credit)` per entry (enforced at posting).

### accounting_journal_lines
- `id`, `journal_entry_id` (FK, cascade), `account_id` (FK), `cost_center_id` (FK nullable), `debit` DECIMAL(14,2), `credit` DECIMAL(14,2), `description`, `analytic_ref_type` (enum: student, staff, cashbox, voucher, none), `analytic_ref_id` (nullable)

### accounting_account_mapping_headers  (versioned)
- `id`, `version_number` (int), `effective_from`, `effective_to` (nullable), `status` (enum: draft, active, superseded), `superseded_at`, `created_by`, `created_at`
- unique(`version_number`)

### accounting_account_mapping_lines  (selectors + multiple lines; deterministic resolution)
- `id`, `mapping_header_id` (FK, cascade), `operation_type`, `selector_charge_type_id` (FK nullable), `selector_payroll_component_id` (FK nullable), `selector_payment_method` (enum nullable), `selector_cashbox_id` (FK nullable), `selector_voucher_type` (enum nullable), `debit_account_id` (FK), `credit_account_id` (FK), `cost_center_scope` (enum: stage, grade, bus, activity, department, none), `specificity_score` (int), `priority` (int)
- Deterministic resolution: `specificity_score` DESC, then `priority` DESC, then `version_number` DESC. No two active lines share the same `operation_type + selectors + specificity + priority`. Posting refused on zero/ambiguous matches.

### accounting_control_accounts
- `id`, `account_id` (FK, unique), `party_type` (enum: student, staff), `reconciliation_tolerance` DECIMAL(14,2) default 0.00

## 9. Budget Model (versioned; actuals from GL, NOT an independent source)

### finance_budgets
- `id`, `academic_year_id` (FK), `name`, `status` (enum: draft, reviewed, approved, locked, revised), `approved_by`, `approved_at`, `created_by`, `created_at`
- unique(`academic_year_id`, `name`)

### finance_budget_versions
- `id`, `budget_id` (FK), `version_number`, `status` (enum: draft, active, superseded), `superseded_at`, `created_by`, `created_at`
- unique(`budget_id`, `version_number`)

### finance_budget_lines
- `id`, `budget_version_id` (FK, cascade), `account_id` (FK), `cost_center_id` (FK nullable), `period_id` (FK nullable), `planned_amount` DECIMAL(14,2)
- **NO `actual_amount` column.** Computed from posted GL via view (`v_budget_actuals`) or reconciled cache.

## 10. Views / Query Services (separate per party type)

- `v_student_subledger_balances` — per student account: `outstanding_due`, `unapplied_credit`, `net_account_position` from `finance_subledger_lines` (party_type=student).
- `v_staff_subledger_balances` — per lifetime staff account (`scope_key=STAFF_GLOBAL`): `payroll_payable`, `advance_receivable` from `finance_subledger_lines` (party_type=staff).
- `v_budget_actuals` — budget actuals from posted GL journal entries.
- `admin/finance_student_ledger.php` and `admin/finance_staff_ledger.php` read EXCLUSIVELY from `finance_subledger_transactions`/`finance_subledger_lines` (filtered by party_type), NOT from domain-table sums.

## 11. Legacy → New Mapping

- `student_fees` → read-only adapter; `balance` compared to `net_account_position` for reconciliation only.
- `fee_payments` → migrated to `finance_receipts` + `finance_payment_allocations` + sub-ledger transactions/lines (idempotency from legacy `id`).
- `student_other_discounts` → `finance_discount_awards` + `finance_discount_applications`.
- `student_fee_balances_history` → migrated as `opening_balance` sub-ledger transactions.
- `staff_profiles` finance columns → `staff_compensation_contracts` + `staff_compensation_contract_components` (`provenance=legacy_migration`, `history_confidence=uncertain`).
- `admin/school_budget.php` → NOT the truth source; new `finance_budgets` model.

## Invariants (MANDATORY — enforced at posting, tested)

### Sub-ledger truth-source invariants
- All party balances (student AND staff) computed EXCLUSIVELY from `SUM(finance_subledger_lines.amount_delta)` per bucket — never from domain-table sums.
- `finance_subledger_lines` FK is ON DELETE RESTRICT (append-only; no cascade delete).
- Each source operation creates EXACTLY ONE `finance_subledger_transactions` row (unique `source_idempotency_key`).
- `unique(transaction_id, line_number)` on lines.
- Sub-ledger transaction `status` is `draft` or `posted` ONLY (no `reversed`); `is_reversed` is derived.

### Reversal invariants
- Original stays `posted` and is ALWAYS counted in SUM.
- Reversal creates a NEW `posted` transaction with `reversal_of`→original, opposite deltas in the same bucket(s).
- `SUM(original lines) + SUM(reversal lines) = 0` per bucket (tested).

### Receipt granularity invariant
- ONE receipt = ONE sub-ledger transaction (multi-line: allocation lines + unapplied credit line) = ONE GL journal (same `source_idempotency_key`).
- All allocation/unapplied-credit rows from one receipt share the SAME `subledger_transaction_id`.
- Refund/application/reversal are SEPARATE transactions, each with its own sub-ledger transaction + GL journal.

### Staff ledger invariants
- Payroll posting → `STAFF_PAYROLL_PAYABLE` +net.
- Payroll payment → `STAFF_PAYROLL_PAYABLE` −amount.
- Retroactive settlement posting → `STAFF_PAYROLL_PAYABLE` ±net_difference; payment clears it through the normal payroll-payment flow.
- Advance issue → `STAFF_ADVANCE_RECEIVABLE` +amount.
- Advance cash repayment → `STAFF_ADVANCE_RECEIVABLE` −amount; GL `Dr Cash/Bank, Cr Staff Advance Receivable`.
- Advance payroll deduction → `STAFF_ADVANCE_RECEIVABLE` −amount; GL `Dr Payroll Payable, Cr Staff Advance Receivable`.
- Advance write-off → independent maker-checker operation limited to the locked/derived remaining receivable; GL `Dr Advance Write-off Expense, Cr Staff Advance Receivable`; it is not a reversal of the advance issue.
- A staff account uses `scope_key=STAFF_GLOBAL` and survives compensation-contract/payroll-model changes.
- `admin/finance_staff_ledger.php` reads EXCLUSIVELY from sub-ledger lines.

### Domain-vs-subledger consistency invariants
- `SUM(installment.net_amount) = charge.net_due`.
- `SUM(discount_applications.applied_amount for a charge) = charge.discount_amount`.
- `allocation.allocated_amount <= installment.remaining_due`.
- `receipt.amount = SUM(allocations) + SUM(created unapplied_credit)` for that receipt's sub-ledger transaction.
- `unapplied_credit_application.applied_amount <= unapplied_credit.remaining`.
- Domain totals match the corresponding sub-ledger transaction's deltas.

### GL invariants
- Every party-affecting sub-ledger transaction ↔ GL journal is 1:1 through unique `accounting_journal_entries.subledger_transaction_id`, with matching `source_idempotency_key`.
- Every pure GL source operation creates exactly one journal and no sub-ledger transaction.
- A payroll run/import batch groups atomic source operations with `batch_id`; each party-specific `payroll_run_item`/import row has its own idempotent sub-ledger transaction and linked journal.
- Journal status is draft|posted only. The original posted journal remains counted; reversal creates a new posted opposite journal with `reversal_of`, and `is_reversed` is derived.
- Per journal entry: `SUM(debit) = SUM(credit)`.
- Every party-affecting posted operation creates domain record(s) + sub-ledger transaction/lines + GL journal inside the same transaction; a pure GL operation creates domain record(s) + GL journal inside the same transaction; mandatory audit participates in both.
- Student/staff sub-ledger balances reconcile to their GL control accounts; cashbox/voucher reconciliation uses GL/domain records, not fake party buckets.
- Posting refused on zero/ambiguous account-mapping matches.
- Voucher/manual journals are refused if a line touches a student/staff control account without the matching linked sub-ledger transaction. Budget plan/version/line writes create no journal.

### Discount/versioning invariants
- `scope_charge_type_key` NOT NULL (`'ALL'` or charge_type `code`).
- One active version per `code + academic_year_id + scope_charge_type_key` (enforced by activation transaction + `FOR UPDATE`, not partial index).
- Future versions remain `draft` until activation.
- Default no-combine (highest-benefit); explicit-combine requires cap.
- Sibling ordering: oldest enrollment date first; ties by `student_id`.

### Voucher invariants
- `finance_vouchers` are reversal-only, maker-checker.
- `amount = SUM(debit) = SUM(credit)` per voucher.
- Expense/other-income has exactly one `cashbox_id`.
- Cash transfer has non-null, distinct source/destination cashboxes and positive amount.
- AP/supplier is OUT OF SCOPE for v1; vouchers are pure GL and create no party sub-ledger transaction.

### Payroll/advance invariants
- `staff_advance_installments` is the PRIMARY repayment schedule source (not `repayment_plan_json`).
- `remaining_amount` derived from sub-ledger or reconciled cache.
- Write-off amount is positive and no greater than locked/derived remaining receivable; partial repayment + write-off cannot produce a negative receivable.
- Cash repayment and payroll deduction use distinct source types and GL mappings.
- `payroll_periods` unique by `finance_period_id + start_date + end_date` (not by `name`).

### Money/resync/retention invariants
- EGP; integer piaster minor units in PHP; `DECIMAL(14,2)` in DB; no PHP float.
- `AuditService` inside transaction; `AUDIT_REVIEW_REQUIRED=0`.
- No auto-delete before formal retention policy; temp export files deleted after 24h.
- `storage/private/db_backups` NOT a truth source; `*_test` only.
- Legacy tables NOT deleted in same cutover release.
- Resync workflow: spec-only commit → adopt main changes in reviewed commit → merge/rebase → overlap check; no stash/reset/clean/force; `origin/main` not used for local changes.
