<?php

declare(strict_types=1);

/**
 * Finance authorization facade — permission matrix + maker-checker enforcement.
 *
 * Permissions (proposed, require business adoption):
 *   finance_view, student_charge_manage, payment_record, payment_reverse,
 *   discount_request, discount_approve, payroll_prepare, payroll_review,
 *   payroll_approve, payroll_pay, finance_export, finance_audit_view,
 *   period_close, period_reopen, budget_manage
 *
 * Maker-checker is MANDATORY in v1 for sensitive operations:
 *   receipt reversal, refund, write-off, manual journal entries, import posting,
 *   payroll approval/payment, period reopen, and manual/exception discounts.
 *   The creator MUST NOT approve the same sensitive operation.
 */
final class FinanceAuthorization
{
    /**
     * All finance permissions.
     */
    public const PERMISSIONS = [
        'finance_view',
        'student_charge_manage',
        'payment_record',
        'payment_reverse',
        'discount_request',
        'discount_approve',
        'payroll_prepare',
        'payroll_review',
        'payroll_approve',
        'payroll_pay',
        'finance_export',
        'finance_audit_view',
        'period_close',
        'period_reopen',
        'budget_manage',
    ];

    /**
     * Operations that require maker-checker (creator ≠ approver).
     */
    public const MAKER_CHECKER_OPERATIONS = [
        'receipt_reversal',
        'refund',
        'write_off',
        'manual_journal',
        'import_posting',
        'payroll_approval',
        'payroll_payment',
        'period_reopen',
        'manual_discount',
        'exception_discount',
    ];

    /**
     * Check if a session role has a given finance permission.
     * v1: admin role has all finance permissions until granular adoption.
     *
     * @param array $session
     * @param string $permission
     * @return bool
     */
    public static function can(array $session, string $permission): bool
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        $role = (string) ($session['active_role'] ?? $session['role'] ?? '');

        // v1: admin has all finance permissions.
        if ($role === 'admin') {
            return true;
        }

        // Future: granular permission checks against a finance_permissions table.
        return false;
    }

    /**
     * Assert that the session has a permission; throws if not.
     *
     * @param array $session
     * @param string $permission
     * @throws RuntimeException if not authorized.
     */
    public static function assertCan(array $session, string $permission): void
    {
        if (!self::can($session, $permission)) {
            throw new RuntimeException('ليست لديك صلاحية: ' . $permission);
        }
    }

    /**
     * Maker-checker enforcement: the creator MUST NOT approve the same sensitive operation.
     *
     * @param int $creatorId
     * @param int $approverId
     * @param string $operation
     * @throws RuntimeException if creator == approver for a maker-checker operation.
     */
    public static function assertMakerChecker(int $creatorId, int $approverId, string $operation): void
    {
        if (!in_array($operation, self::MAKER_CHECKER_OPERATIONS, true)) {
            return; // Not a maker-checker operation.
        }

        if ($creatorId > 0 && $creatorId === $approverId) {
            throw new RuntimeException(
                'لا يمكن لمنشئ العملية الحساسة اعتمادها بنفسه (maker-checker): ' . $operation
            );
        }
    }

    /**
     * Check if an operation requires maker-checker.
     *
     * @param string $operation
     * @return bool
     */
    public static function requiresMakerChecker(string $operation): bool
    {
        return in_array($operation, self::MAKER_CHECKER_OPERATIONS, true);
    }
}
