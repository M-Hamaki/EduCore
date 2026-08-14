<?php

declare(strict_types=1);

/**
 * Reads and validates the annual-result policy separately from a term scheme.
 * Legacy two-term fields remain a read-only fallback for schemes without a family
 * policy, so deploying the migration never changes historic annual results.
 */
final class AssessmentAnnualPolicyService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{source:string,family_id:int|null,enabled:bool,weights_by_term_id:array<int,float>,weights_by_term_order:array<int,float>,valid:bool}
     */
    public function policyForScheme(int $schemeId, bool $legacyEnabled = false, float $firstWeight = 50.0, float $secondWeight = 50.0): array
    {
        if ($schemeId > 0 && $this->hasFamilyPolicySchema()) {
            $stmt = $this->db->prepare("SELECT p.id, p.is_enabled, s.family_id
                FROM assessment_schemes s
                JOIN assessment_annual_policies p ON p.family_id = s.family_id
                WHERE s.id = ? AND s.family_id IS NOT NULL
                LIMIT 1");
            $stmt->execute([$schemeId]);
            $policy = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($policy) {
                $weightStmt = $this->db->prepare('SELECT term_id, weight FROM assessment_annual_policy_terms WHERE policy_id = ? ORDER BY term_id');
                $weightStmt->execute([(int) $policy['id']]);
                $weights = [];
                foreach ($weightStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $weights[(int) $row['term_id']] = (float) $row['weight'];
                }
                $enabled = (int) $policy['is_enabled'] === 1;
                $positiveWeightCount = count(array_filter(
                    $weights,
                    static fn(float $weight): bool => $weight > 0
                ));
                return [
                    'source' => 'family',
                    'family_id' => (int) $policy['family_id'],
                    'enabled' => $enabled,
                    'weights_by_term_id' => $weights,
                    'weights_by_term_order' => [],
                    'valid' => !$enabled || ($this->weightsTotalIsValid($weights) && $positiveWeightCount >= 2),
                ];
            }
        }

        $legacy = [1 => $firstWeight, 2 => $secondWeight];
        return [
            'source' => 'legacy',
            'family_id' => null,
            'enabled' => $legacyEnabled,
            'weights_by_term_id' => [],
            'weights_by_term_order' => $legacy,
            'valid' => !$legacyEnabled || $this->weightsTotalIsValid($legacy),
        ];
    }

    /** @param array<int,float|int|string> $weights */
    public function assertWeightsAreValid(array $weights): array
    {
        $normalized = [];
        foreach ($weights as $termId => $weight) {
            $termId = (int) $termId;
            $weight = $this->numericWeight($weight);
            if ($termId <= 0 || $weight === null || $weight < 0 || $weight > 100) {
                throw new InvalidArgumentException('أوزان نهاية العام غير صالحة.');
            }
            $normalized[$termId] = round($weight, 3);
        }
        if ($normalized === [] || !$this->weightsTotalIsValid($normalized)) {
            throw new InvalidArgumentException('يجب أن يكون مجموع أوزان الترمات المؤهلة لنهاية العام 100%.');
        }
        return $normalized;
    }

    /** @param array<int,float|int|string> $weights */
    public function weightsTotalIsValid(array $weights): bool
    {
        if ($weights === []) {
            return false;
        }
        $total = 0.0;
        foreach ($weights as $weight) {
            $value = $this->numericWeight($weight);
            if ($value === null || $value < 0 || $value > 100) {
                return false;
            }
            $total += $value;
        }
        return abs($total - 100.0) < 0.001;
    }

    /** @param mixed $value */
    private function numericWeight($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return null;
            }
            $number = (float) $value;
        } else {
            return null;
        }

        return is_finite($number) ? $number : null;
    }

    private function hasFamilyPolicySchema(): bool
    {
        return $this->tableExists('assessment_annual_policies')
            && $this->tableExists('assessment_annual_policy_terms')
            && $this->columnExists('assessment_schemes', 'family_id');
    }

    private function tableExists(string $table): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->db->query("PRAGMA table_info(`$table`)");
                foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                    if (($row['name'] ?? '') === $column) return true;
                }
                return false;
            }
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
