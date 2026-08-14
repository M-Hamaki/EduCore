<?php

declare(strict_types=1);

/**
 * Seeds the minimum safe Finance configuration required for the first posting.
 *
 * Existing accounts, cashboxes, periods, and mapping lines are never replaced.
 * New cashboxes require their own explicit mappings and therefore fail closed.
 */
return static function (PDO $db): void {
    $accountRows = [
        ['1100', 'الخزينة الرئيسية', 'asset', 0],
        ['1200', 'ذمم الطلاب المدينة', 'asset', 1],
        ['1210', 'سلف العاملين المدينة', 'asset', 1],
        ['2100', 'أرصدة الطلاب الدائنة', 'liability', 1],
        ['2200', 'رواتب مستحقة الدفع', 'liability', 1],
        ['2210', 'استقطاعات رواتب مستحقة', 'liability', 0],
        ['4100', 'إيرادات المصروفات الدراسية', 'revenue', 0],
        ['4200', 'إيرادات أخرى', 'revenue', 0],
        ['5100', 'مصروف الرواتب', 'expense', 0],
        ['5200', 'مصروفات عمومية', 'expense', 0],
        ['5300', 'خصومات ومنح الطلاب', 'expense', 0],
        ['5400', 'ديون طلاب معدومة', 'expense', 0],
        ['5500', 'سلف عاملين معدومة', 'expense', 0],
    ];
    $insertAccount = $db->prepare(
        'INSERT IGNORE INTO accounting_accounts
            (code, name_ar, type, is_active, is_control_account)
         VALUES (?, ?, ?, 1, ?)'
    );
    $findAccount = $db->prepare(
        'SELECT id, type, is_control_account
           FROM accounting_accounts
          WHERE code = ?
          LIMIT 1'
    );
    $accountIds = [];
    foreach ($accountRows as [$code, $name, $type, $isControl]) {
        $insertAccount->execute([$code, $name, $type, $isControl]);
        $findAccount->execute([$code]);
        $account = $findAccount->fetch(PDO::FETCH_ASSOC);
        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('Unable to resolve default Finance account ' . $code);
        }
        if ((string) ($account['type'] ?? '') !== $type) {
            throw new RuntimeException('Existing Finance account ' . $code . ' has an incompatible type.');
        }
        if ($isControl === 1 && (int) ($account['is_control_account'] ?? 0) !== 1) {
            throw new RuntimeException('Existing Finance account ' . $code . ' must be a control account.');
        }
        $accountIds[$code] = $accountId;
    }

    $db->exec(
        "INSERT IGNORE INTO finance_cashboxes
            (code, name, type, is_active, accountability_role, receipt_prefix)
         VALUES ('MAIN', 'الخزينة الرئيسية', 'cash', 1, 'admin', 'RCP')"
    );
    $cashboxId = (int) $db->query(
        "SELECT id FROM finance_cashboxes WHERE code = 'MAIN' LIMIT 1"
    )->fetchColumn();
    if ($cashboxId <= 0) {
        throw new RuntimeException('Unable to resolve the default Finance cashbox.');
    }

    $academicYearsTableExists = (int) $db->query(
        "SELECT COUNT(*)
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'academic_years'"
    )->fetchColumn() > 0;
    $activeYearId = $academicYearsTableExists
        ? (int) $db->query(
            'SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
        )->fetchColumn()
        : 0;
    if ($activeYearId > 0) {
        $period = $db->prepare(
            "INSERT IGNORE INTO finance_periods
                (academic_year_id, name, start_date, end_date, status)
             VALUES (?, 'العام الدراسي الكامل', NULL, NULL, 'open')"
        );
        $period->execute([$activeYearId]);
    }

    $mappingRows = [
        ['student_charge', null, null, null, null, null, '1200', '4100'],
        ['student_discount', null, null, null, null, null, '5300', '1200'],
        ['receipt', null, null, null, $cashboxId, null, '1100', '1200'],
        ['unapplied_credit', null, null, null, $cashboxId, null, '1100', '2100'],
        ['unapplied_credit_application', null, null, null, null, null, '2100', '1200'],
        ['refund_allocation', null, null, null, null, null, '1200', '1100'],
        ['refund_unapplied_credit', null, null, null, null, null, '2100', '1100'],
        ['student_debt_write_off', null, null, null, null, null, '5400', '1200'],
        ['advance_issue', null, null, null, null, null, '1210', '1100'],
        ['advance_cash_repayment', null, null, null, $cashboxId, null, '1100', '1210'],
        ['advance_payroll_deduction', null, null, null, null, null, '2200', '1210'],
        ['advance_write_off', null, null, null, null, null, '5500', '1210'],
        ['payroll_component', null, null, null, null, null, '5100', '2210'],
        ['payroll_run_item_posting', null, null, null, null, null, '5100', '2200'],
        ['payroll_payment', null, null, null, $cashboxId, null, '2200', '1100'],
        ['voucher', null, null, null, $cashboxId, 'expense', '5200', '1100'],
        ['voucher', null, null, null, $cashboxId, 'other_income', '1100', '4200'],
        ['voucher_transfer_out', null, null, null, $cashboxId, null, '1100', '1100'],
        ['voucher_transfer_in', null, null, null, $cashboxId, null, '1100', '1100'],
    ];

    // Never mix defaults into a user-managed mapping set. If any mapping exists,
    // preserve it and let missing operations fail closed for explicit review.
    $existingMappingCount = (int) $db->query(
        'SELECT COUNT(*) FROM accounting_account_mapping_lines'
    )->fetchColumn();
    if ($existingMappingCount === 0) {
        $activeHeaderId = (int) $db->query(
            "SELECT id FROM accounting_account_mapping_headers
              WHERE status = 'active' ORDER BY version_number DESC LIMIT 1"
        )->fetchColumn();
        if ($activeHeaderId <= 0) {
            $nextVersion = (int) $db->query(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                   FROM accounting_account_mapping_headers'
            )->fetchColumn();
            $header = $db->prepare(
                "INSERT INTO accounting_account_mapping_headers
                    (version_number, effective_from, status, created_by)
                 VALUES (?, CURRENT_DATE, 'active', NULL)"
            );
            $header->execute([$nextVersion]);
            $activeHeaderId = (int) $db->lastInsertId();
        }

        $insertMapping = $db->prepare(
            'INSERT INTO accounting_account_mapping_lines
                (mapping_header_id, operation_type, selector_charge_type_id,
                 selector_payroll_component_id, selector_payment_method,
                 selector_cashbox_id, selector_voucher_type, debit_account_id,
                 credit_account_id, cost_center_scope, specificity_score, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($mappingRows as $row) {
            [
                $operation,
                $chargeTypeId,
                $payrollComponentId,
                $paymentMethod,
                $selectorCashboxId,
                $voucherType,
                $debitCode,
                $creditCode,
            ] = $row;
            $specificity = count(array_filter([
                $chargeTypeId,
                $payrollComponentId,
                $paymentMethod,
                $selectorCashboxId,
                $voucherType,
            ], static fn ($value): bool => $value !== null));
            $insertMapping->execute([
                $activeHeaderId,
                $operation,
                $chargeTypeId,
                $payrollComponentId,
                $paymentMethod,
                $selectorCashboxId,
                $voucherType,
                $accountIds[$debitCode],
                $accountIds[$creditCode],
                'none',
                $specificity,
                100,
            ]);
        }
    }

    $controlRows = [
        ['1200', 'student', 'debit'],
        ['2100', 'student', 'credit'],
        ['1210', 'staff', 'debit'],
        ['2200', 'staff', 'credit'],
    ];
    $insertControl = $db->prepare(
        'INSERT IGNORE INTO accounting_control_accounts
            (account_id, sub_ledger_type, normal_balance, reconciliation_tolerance)
         VALUES (?, ?, ?, 0.00)'
    );
    foreach ($controlRows as [$code, $subledgerType, $normalBalance]) {
        $insertControl->execute([
            $accountIds[$code],
            $subledgerType,
            $normalBalance,
        ]);
    }
};
