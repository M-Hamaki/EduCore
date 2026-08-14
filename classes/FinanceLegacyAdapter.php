<?php

declare(strict_types=1);

/**
 * Finance legacy adapter — bridges legacy pages to the new Finance module services.
 *
 * When FinanceFeatureFlag is in 'execute' mode, legacy pages delegate their
 * read/write operations to Finance services. When in 'off'/'shadow'/'display',
 * legacy pages operate as-is (off) or compute comparison balances (shadow/display).
 *
 * Every legacy entrypoint calls this adapter immediately after authentication.
 * Off/shadow/display preserve the legacy implementation; execute delegates the
 * browser surface to Finance and fails closed for obsolete direct write/AJAX calls.
 */

require_once __DIR__ . '/FinanceFeatureFlag.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

final class FinanceLegacyAdapter
{
    private const ENTRYPOINTS = [
        'fee_structure.php' => ['target' => 'finance_fee_plans.php', 'auto_delegate' => true, 'actions' => ['save_fee_structure','copy_fee_structure','delete_fee_structure','save_sibling_discounts','save_bus_zone','delete_bus_zone','save_other_discount','toggle_other_discount','delete_other_discount'], 'ajax' => ['view_installments','get_fee_structure']],
        'fee_calculator.php' => ['target' => 'finance_discounts.php', 'auto_delegate' => true, 'actions' => [], 'ajax' => ['calculate','calculate_family']],
        'fee_payments.php' => ['target' => 'finance_receipts.php', 'auto_delegate' => true, 'actions' => ['assign_discount'], 'ajax' => ['get_student_fee','record_payment','delete_payment','generate_fees']],
        'ajax_fee_payments_datatable.php' => ['target' => 'finance_student_accounts.php', 'auto_delegate' => false, 'actions' => [], 'ajax' => ['datatable']],
        'staff_financial_data.php' => ['target' => 'finance_staff_contracts.php', 'auto_delegate' => true, 'actions' => ['save_financial_data'], 'ajax' => ['get_staff_financial']],
        'school_budget.php' => ['target' => 'finance_budgets.php', 'auto_delegate' => false, 'actions' => [], 'ajax' => []],
        'student_buses.php' => ['target' => 'finance_buses.php', 'auto_delegate' => false, 'passthrough_get' => true, 'actions' => ['assign_bus','bulk_assign'], 'ajax' => []],
        'bus_report.php' => ['target' => 'bus_report.php', 'auto_delegate' => false, 'passthrough_all' => true, 'actions' => ['do_export'], 'ajax' => []],
        'statements.php' => ['target' => 'statements.php', 'auto_delegate' => false, 'passthrough_all' => true, 'actions' => [], 'ajax' => []],
    ];

    /** Canonical report URLs that retain the legacy finance compatibility contract. */
    private const ENTRYPOINT_ALIASES = [
        'student_numbers_reports.php' => 'school_budget.php',
    ];

    /**
     * Check if the Finance module should handle a given operation.
     */
    public static function shouldHandle(): bool
    {
        return FinanceFeatureFlag::isExecute();
    }

    /**
     * Check if the Finance module should run in shadow mode (parallel computation).
     */
    public static function shouldShadow(): bool
    {
        return FinanceFeatureFlag::isShadow() || FinanceFeatureFlag::isDisplay();
    }

    /** @return array{target:string,auto_delegate:bool,passthrough_get?:bool,passthrough_all?:bool,actions:list<string>,ajax:list<string>} */
    public static function contract(string $entrypoint): array
    {
        $name = basename($entrypoint);
        $name = self::ENTRYPOINT_ALIASES[$name] ?? $name;
        if (!isset(self::ENTRYPOINTS[$name])) {
            throw new InvalidArgumentException('Unknown legacy finance entrypoint: ' . $name);
        }
        return self::ENTRYPOINTS[$name];
    }

    /**
     * In execute mode, canonical legacy list/form GETs delegate to the new UI.
     * AJAX and state requests remain on their original URLs until a field-for-field
     * Application-service translation is available; this preserves the public
     * contract without introducing a dual-write path.
     */
    public static function delegateRequestIfEnabled(string $entrypoint, ?PDO $db = null): void
    {
        if (!self::shouldHandle()) {
            return;
        }
        $contract = self::contract($entrypoint);
        $name = basename($entrypoint);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (($contract['passthrough_all'] ?? false)
            || ($method === 'GET' && ($contract['passthrough_get'] ?? false))) {
            return;
        }
        $isAjax = isset($_GET['ajax'])
            || isset($_GET['action'])
            || $name === 'ajax_fee_payments_datatable.php';
        if ($method !== 'GET') {
            self::requireCsrf($isAjax);
        }

        $db = $db ?? (new Database())->getConnection();
        if (!$db instanceof PDO) {
            throw new RuntimeException('Finance compatibility database connection is unavailable.');
        }
        $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
        $factory = new \EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory($db, $audit);
        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        if ($actorId <= 0) {
            self::fail('تعذر تحديد المستخدم الحالي.', $isAjax, 403);
        }

        try {
            if ($name === 'fee_structure.php') {
                self::handleFeeStructure($factory->legacyFeeDefinitionService(), $actorId);
            } elseif ($name === 'fee_calculator.php') {
                self::handleFeeCalculator($factory->legacyFeeDefinitionService());
            } elseif ($name === 'fee_payments.php') {
                self::handleFeePayments($factory->legacyCollectionCompatibilityService(), $actorId);
            } elseif ($name === 'ajax_fee_payments_datatable.php') {
                $year = trim((string) ($_POST['year'] ?? ''));
                self::json($factory->legacyCollectionCompatibilityService()->dataTable($_POST, $year));
            } elseif ($name === 'staff_financial_data.php') {
                self::handleStaffFinance($factory, $actorId);
            } elseif ($name === 'student_buses.php') {
                self::handleStudentBuses($db, $actorId);
            } elseif ($method !== 'GET') {
                self::fail('هذه العملية متاحة من الواجهة الجديدة فقط.', $isAjax, 409);
            }
        } catch (Throwable $error) {
            error_log('Finance legacy adapter [' . $name . ']: ' . $error->getMessage());
            self::fail($error->getMessage(), $isAjax, 422);
        }

        if ($method !== 'GET' || $isAjax) {
            self::fail('عملية مالية قديمة غير مدعومة في وضع التنفيذ.', $isAjax, 409);
        }

        header('Location: ' . $contract['target'] . '?legacy_entrypoint=' . rawurlencode(basename($entrypoint)));
        exit();
    }

    /** Backward-compatible alias retained for callers during the same release. */
    public static function delegateReadIfEnabled(string $entrypoint): void
    {
        self::delegateRequestIfEnabled($entrypoint);
    }

    private static function requireCsrf(bool $isAjax): void
    {
        $json = self::jsonPayload();
        $provided = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (is_array($json) ? ($json['csrf_token'] ?? '') : ''));
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return;
        }
        http_response_code(419);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error_message'] = 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة وحاول مرة أخرى.';
        exit();
    }

    private static function handleFeeStructure(
        \EduCore\Modules\Finance\Application\LegacyFeeDefinitionService $service,
        int $actorId
    ): void {
        $ajax = (string) ($_GET['ajax'] ?? '');
        if ($ajax === 'view_installments') {
            $result = $service->feeStructure((int) ($_GET['fs_id'] ?? 0));
            self::json([
                'success' => (bool) ($result['success'] ?? false),
                'installments' => $result['installments'] ?? [],
                'total' => (string) ($result['fee']['total_amount'] ?? '0.00'),
            ]);
        }
        if ($ajax === 'get_fee_structure') {
            self::json($service->feeStructure((int) ($_GET['fs_id'] ?? 0)));
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_fee_structure') {
            $service->saveFeeStructure($_POST, $actorId);
        } elseif ($action === 'copy_fee_structure') {
            $service->copyFeeStructure(
                (int) ($_POST['from_grade_id'] ?? 0),
                (int) ($_POST['to_grade_id'] ?? 0),
                (string) ($_POST['academic_year'] ?? ''),
                $actorId
            );
        } elseif ($action === 'delete_fee_structure') {
            $service->archiveFeeStructure((int) ($_POST['fee_structure_id'] ?? 0), $actorId);
        } elseif ($action === 'save_sibling_discounts') {
            $service->saveSiblingDiscounts(
                (string) ($_POST['academic_year'] ?? ''),
                (array) ($_POST['sibling_order'] ?? []),
                (array) ($_POST['discount_percentage'] ?? []),
                $actorId
            );
        } elseif ($action === 'save_bus_zone') {
            $service->saveBusZone($_POST, $actorId);
        } elseif ($action === 'delete_bus_zone') {
            $service->archiveBusZone(
                (int) ($_POST['zone_id'] ?? 0),
                (string) ($_POST['academic_year'] ?? $_GET['year'] ?? ''),
                $actorId
            );
        } elseif ($action === 'save_other_discount') {
            $service->saveOtherDiscount($_POST, $actorId);
        } elseif ($action === 'toggle_other_discount') {
            $service->setOtherDiscountStatus(
                (int) ($_POST['od_id'] ?? 0),
                (string) ($_POST['new_status'] ?? ''),
                $actorId
            );
        } elseif ($action === 'delete_other_discount') {
            $service->archiveOtherDiscount((int) ($_POST['od_id'] ?? 0), $actorId);
        } else {
            throw new InvalidArgumentException('عملية إعداد رسوم غير مدعومة.');
        }
        self::redirectWithMessage('finance_fee_plans.php', 'تم حفظ إعدادات الرسوم في النظام المالي الجديد.');
    }

    private static function handleFeeCalculator(
        \EduCore\Modules\Finance\Application\LegacyFeeDefinitionService $service
    ): void {
        $ajax = (string) ($_GET['ajax'] ?? '');
        if ($ajax === 'calculate') {
            self::json($service->calculate(
                (int) ($_GET['grade_id'] ?? 0),
                (int) ($_GET['sibling_order'] ?? 1),
                (int) ($_GET['bus_zone_id'] ?? 0),
                (string) ($_GET['year'] ?? '')
            ));
        }
        if ($ajax === 'calculate_family') {
            $siblings = json_decode((string) ($_GET['siblings'] ?? '[]'), true);
            self::json($service->calculateFamily(
                is_array($siblings) ? $siblings : [],
                (string) ($_GET['year'] ?? '')
            ));
        }
    }

    private static function handleFeePayments(
        \EduCore\Modules\Finance\Application\LegacyCollectionCompatibilityService $service,
        int $actorId
    ): void {
        $ajax = (string) ($_GET['ajax'] ?? '');
        if ($ajax === 'get_student_fee') {
            self::json($service->studentFee(
                (int) ($_GET['student_id'] ?? 0),
                (string) ($_GET['year'] ?? '')
            ));
        }
        if ($ajax === 'record_payment') {
            self::json($service->recordPayment(self::jsonPayload(), $actorId));
        }
        if ($ajax === 'delete_payment') {
            self::json($service->requestReceiptReversal(
                (int) (self::jsonPayload()['payment_id'] ?? 0),
                $actorId
            ));
        }
        if ($ajax === 'generate_fees') {
            self::json($service->generateFees(self::jsonPayload(), $actorId));
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && (string) ($_POST['action'] ?? '') === 'assign_discount') {
            $requestId = $service->requestDiscount(
                (int) ($_POST['student_id'] ?? 0),
                (int) ($_POST['other_discount_id'] ?? 0),
                (string) ($_POST['year'] ?? $_GET['year'] ?? ''),
                $actorId
            );
            self::redirectWithMessage(
                'finance_approvals.php',
                'تم إنشاء طلب الخصم رقم ' . $requestId . ' وينتظر اعتماد مستخدم آخر.'
            );
        }
    }

    private static function handleStaffFinance(
        \EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory $factory,
        int $actorId
    ): void {
        if (!method_exists($factory, 'legacyStaffFinanceCompatibilityService')) {
            throw new RuntimeException('محول مالية العاملين لم يكتمل بعد.');
        }
        $service = $factory->legacyStaffFinanceCompatibilityService();
        if ((string) ($_GET['action'] ?? '') === 'get_staff_financial') {
            self::json($service->staffFinancial((int) ($_GET['staff_id'] ?? 0)));
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $contractId = $service->save($_POST, $actorId);
            self::redirectWithMessage(
                'finance_staff_contracts.php',
                'تم إنشاء عقد مالي جديد للعامل رقم ' . $contractId . ' مع الاحتفاظ بالسجل السابق.'
            );
        }
    }

    private static function handleStudentBuses(PDO $db, int $actorId): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }
        if (!class_exists(\EduCore\Modules\Transport\Application\LegacyStudentBusAssignmentService::class)) {
            throw new RuntimeException('محول اشتراكات الحافلات لم يكتمل بعد.');
        }
        $service = \EduCore\Modules\Transport\Infrastructure\TransportServiceFactory::legacyStudentBusAssignmentService($db);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'assign_bus') {
            $service->assign(
                (int) ($_POST['student_id'] ?? 0),
                (int) ($_POST['bus_id'] ?? 0),
                (int) ($_POST['backup_bus_id'] ?? 0),
                (string) ($_POST['notes'] ?? ''),
                $actorId
            );
        } elseif ($action === 'bulk_assign') {
            $service->bulkAssign($_POST, $actorId);
        } else {
            throw new InvalidArgumentException('عملية حافلات غير مدعومة.');
        }
        self::redirectWithMessage('finance_buses.php', 'تم تحديث اشتراكات الحافلات بنجاح.');
    }

    /** @return array<string,mixed> */
    private static function jsonPayload(): array
    {
        static $payload = null;
        if (is_array($payload)) {
            return $payload;
        }
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $payload = is_array($decoded) ? $decoded : [];
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    private static function fail(string $message, bool $isAjax, int $status): void
    {
        if ($isAjax) {
            self::json(['success' => false, 'message' => $message], $status);
        }
        http_response_code($status);
        $_SESSION['error_message'] = $message;
        exit();
    }

    private static function redirectWithMessage(string $target, string $message): void
    {
        $_SESSION['success_message'] = $message;
        header('Location: ' . $target);
        exit();
    }

    public static function bridgeNotice(string $entrypoint): string
    {
        // The compatibility mode is an internal rollout detail and must never
        // leak into user-facing pages.
        return '';
    }

    /**
     * Get the sub-ledger balance for a student (from the unified sub-ledger).
     * Returns null if the Finance module is not enabled.
     *
     * @param PDO $db
     * @param int $studentId
     * @param int $academicYearId
     * @return array{outstanding_due: string, unapplied_credit: string, net_account_position: string}|null
     */
    public static function studentBalance(PDO $db, int $studentId, int $academicYearId): ?array
    {
        if (!self::shouldHandle() && !self::shouldShadow()) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT outstanding_due, unapplied_credit, net_account_position
                 FROM v_student_subledger_balances
                 WHERE student_id = ? AND academic_year_id = ?'
            );
            $stmt->execute([$studentId, (string) $academicYearId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // Tables may not exist yet — safe fallback.
            return null;
        }
    }

    /**
     * Compare legacy balance with Finance module balance (for shadow/display mode).
     *
     * @param string $legacyBalance  decimal string from student_fees.balance
     * @param string $financeBalance  decimal string from v_student_subledger_balances
     * @return bool true if they match (within tolerance)
     */
    public static function balancesMatch(string $legacyBalance, string $financeBalance): bool
    {
        return \EduCore\Modules\Finance\Domain\Money::fromDecimalString($legacyBalance)
            ->equals(\EduCore\Modules\Finance\Domain\Money::fromDecimalString($financeBalance));
    }
}
