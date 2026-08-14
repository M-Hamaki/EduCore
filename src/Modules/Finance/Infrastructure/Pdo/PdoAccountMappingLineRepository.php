<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\AccountMappingLineRepository;
use InvalidArgumentException;
use PDO;

final class PdoAccountMappingLineRepository implements AccountMappingLineRepository
{
    private const SELECTORS = [
        'charge_type_id' => 'selector_charge_type_id',
        'payroll_component_id' => 'selector_payroll_component_id',
        'payment_method' => 'selector_payment_method',
        'cashbox_id' => 'selector_cashbox_id',
        'voucher_type' => 'selector_voucher_type',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function findActiveLines(string $operationType, array $selectors): array
    {
        $sql = 'SELECT ml.*, mh.version_number
                FROM accounting_account_mapping_lines ml
                INNER JOIN accounting_account_mapping_headers mh ON mh.id = ml.mapping_header_id
                WHERE ml.operation_type = ? AND mh.status = ?';
        $params = [$operationType, 'active'];

        foreach (self::SELECTORS as $key => $column) {
            if (array_key_exists($key, $selectors) && $selectors[$key] !== null) {
                $sql .= " AND ({$column} = ? OR {$column} IS NULL)";
                $params[] = $selectors[$key];
            } else {
                $sql .= " AND {$column} IS NULL";
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $fields): int
    {
        foreach (['mapping_header_id', 'operation_type', 'debit_account_id', 'credit_account_id'] as $required) {
            if (!isset($fields[$required])) {
                throw new InvalidArgumentException('Missing account-mapping field: ' . $required);
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO accounting_account_mapping_lines
                (mapping_header_id, operation_type, selector_charge_type_id, selector_payroll_component_id,
                 selector_payment_method, selector_cashbox_id, selector_voucher_type, debit_account_id,
                 credit_account_id, cost_center_scope, specificity_score, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fields['mapping_header_id'],
            $fields['operation_type'],
            $fields['selector_charge_type_id'] ?? null,
            $fields['selector_payroll_component_id'] ?? null,
            $fields['selector_payment_method'] ?? null,
            $fields['selector_cashbox_id'] ?? null,
            $fields['selector_voucher_type'] ?? null,
            $fields['debit_account_id'],
            $fields['credit_account_id'],
            $fields['cost_center_scope'] ?? 'none',
            $fields['specificity_score'] ?? 0,
            $fields['priority'] ?? 0,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
