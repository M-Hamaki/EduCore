<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\DiscountApplicationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountAwardRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountRuleRepository;
use PDO;

/**
 * PDO implementation for discount rules, awards, and applications.
 */
final class PdoDiscountRepository implements DiscountRuleRepository, DiscountAwardRepository, DiscountApplicationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findActiveRule(string $code, int $academicYearId, string $scopeKey, string $atDate): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_discount_rules
             WHERE code = ? AND academic_year_id = ? AND scope_charge_type_key = ? AND status = ?
               AND (effective_from IS NULL OR effective_from <= ?)
               AND (effective_to IS NULL OR effective_to >= ?)
             LIMIT 1'
        );
        $stmt->execute([$code, $academicYearId, $scopeKey, 'active', $atDate, $atDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findApplicableRule(string $code, int $academicYearId, string $chargeTypeKey, string $atDate): ?array
    {
        $specific = $this->findActiveRule($code, $academicYearId, $chargeTypeKey, $atDate);
        return $specific ?? $this->findActiveRule($code, $academicYearId, 'ALL', $atDate);
    }

    public function findRuleById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_discount_rules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createVersion(array $fields): int
    {
        $stmt = $this->db->prepare('SELECT version_number FROM finance_discount_rules WHERE code = ? AND academic_year_id = ? AND scope_charge_type_key = ? ORDER BY version_number FOR UPDATE');
        $stmt->execute([$fields['code'], $fields['academic_year_id'], $fields['scope_charge_type_key']]);
        $versions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $version = ($versions === [] ? 0 : max($versions)) + 1;
        $this->db->prepare(
            'INSERT INTO finance_discount_rules
                (code, name_ar, priority, combinable, cap_amount, effective_from, effective_to,
                 status, academic_year_id, scope_charge_type_key, calculation_type,
                 calculation_value, parameters_json, version_number, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fields['code'],
            $fields['name_ar'],
            $fields['priority'],
            $fields['combinable'],
            $fields['cap_amount'],
            $fields['effective_from'],
            $fields['effective_to'],
            'draft',
            $fields['academic_year_id'],
            $fields['scope_charge_type_key'],
            $fields['calculation_type'] ?? 'manual_amount',
            $fields['calculation_value'] ?? null,
            $fields['parameters_json'] ?? null,
            $version,
            $fields['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function activateRule(int $ruleId, int $activatedBy): void
    {
        $stmt = $this->db->prepare('SELECT id, code, academic_year_id, scope_charge_type_key FROM finance_discount_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            throw new \RuntimeException('Discount rule does not exist.');
        }

        $scopeStmt = $this->db->prepare('SELECT id, status FROM finance_discount_rules WHERE code = ? AND academic_year_id = ? AND scope_charge_type_key = ? ORDER BY id FOR UPDATE');
        $scopeStmt->execute([$rule['code'], $rule['academic_year_id'], $rule['scope_charge_type_key']]);
        $scopeRows = $scopeStmt->fetchAll(PDO::FETCH_ASSOC);
        $target = null;
        foreach ($scopeRows as $scopeRow) {
            if ((int) $scopeRow['id'] === $ruleId) {
                $target = $scopeRow;
            } elseif ((string) $scopeRow['status'] === 'active') {
                throw new \RuntimeException('An active discount rule already exists for this scope.');
            }
        }
        if ($target === null || (string) $target['status'] !== 'draft') {
            throw new \RuntimeException('Only a draft discount rule can be activated.');
        }
        $this->db->prepare('UPDATE finance_discount_rules SET status = ?, activated_by = ?, activated_at = NOW() WHERE id = ?')->execute(['active', $activatedBy, $ruleId]);
    }

    public function archiveRule(int $ruleId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE finance_discount_rules
             SET status = 'archived'
             WHERE id = ? AND status IN ('draft','active','superseded')"
        );
        $stmt->execute([$ruleId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Discount rule was not found or is already archived.');
        }
    }

    public function createAward(int $studentAccountId, int $ruleId, string $awardedAmount, string $reason, int $requestedBy, ?int $approvedBy): int
    {
        $this->db->prepare(
            'INSERT INTO finance_discount_awards
                (student_account_id, discount_rule_id, awarded_amount, reason, requested_by, approved_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $studentAccountId, $ruleId, $awardedAmount, $reason, $requestedBy, $approvedBy,
            $approvedBy ? 'approved' : 'pending',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_discount_awards WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Discount award locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_discount_awards WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function approve(int $id, int $approvedBy): void
    {
        $stmt = $this->db->prepare('UPDATE finance_discount_awards SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = ?');
        $stmt->execute(['approved', $approvedBy, $id, 'pending']);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Only a pending discount award can be approved.');
        }
    }

    public function createApplication(
        int $awardId,
        int $chargeId,
        ?int $installmentId,
        string $appliedAmount,
        string $ledgerEffectAmount = '0.00',
        ?int $adjustmentId = null,
        ?int $subledgerTransactionId = null,
        ?string $requestId = null
    ): int
    {
        $this->db->prepare(
            'INSERT INTO finance_discount_applications
                (discount_award_id, student_charge_id, student_charge_installment_id, applied_amount,
                 ledger_effect_amount, adjustment_id, subledger_transaction_id, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $awardId,
            $chargeId,
            $installmentId,
            $appliedAmount,
            $ledgerEffectAmount,
            $adjustmentId,
            $subledgerTransactionId,
            $requestId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function sumForCharge(int $chargeId): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(applied_amount), 0) FROM finance_discount_applications WHERE student_charge_id = ?'
        );
        $stmt->execute([$chargeId]);
        return (string) $stmt->fetchColumn();
    }

    public function sumForAward(int $awardId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(applied_amount), 0) FROM finance_discount_applications WHERE discount_award_id = ?');
        $stmt->execute([$awardId]);
        return (string) $stmt->fetchColumn();
    }

    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM finance_discount_applications WHERE request_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
