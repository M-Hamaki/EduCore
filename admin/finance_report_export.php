<?php
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();

$type = (string) ($_POST['report'] ?? '');
$periodId = (int) ($_POST['finance_period_id'] ?? 0) ?: null;
$yearId = (int) ($_POST['academic_year_id'] ?? 0);
$runId = (int) ($_POST['payroll_run_id'] ?? 0);
$budgetVersionId = (int) ($_POST['budget_version_id'] ?? 0);

try {
    $database = new Database(); $db = $database->getConnection();
    $factory = new FinanceServiceFactory($db, new AuditService($db)); $reports = $factory->reportService();
    $rows = match ($type) {
        'trial_balance' => $reports->trialBalance($periodId), 'profit_loss' => $reports->profitAndLoss($periodId),
        'cash_flow' => $reports->cashFlow($periodId), 'collection' => $reports->collectionSummary($yearId),
        'debt_aging' => $reports->debtAging($yearId), 'payroll' => $reports->payrollSummary($runId),
        'budget_actual' => $reports->budgetVsActual($budgetVersionId),
        default => throw new InvalidArgumentException('Unsupported finance report.'),
    };
    if ($rows === []) { throw new RuntimeException('The report has no rows to export.'); }
    $columns = array_keys($rows[0]);
    $filters = ['finance_period_id' => $periodId, 'academic_year_id' => $yearId, 'payroll_run_id' => $runId, 'budget_version_id' => $budgetVersionId];
    $ref = $factory->exportService()->export($type, $rows, $columns, $columns, $filters, (int) ($_SESSION['user_id'] ?? 0), (string) ($_POST['format'] ?? 'csv'));
    header('Location: finance_export_download.php?ref=' . rawurlencode($ref));
    exit();
} catch (Throwable $exception) {
    error_log('Finance report export failed: ' . $exception->getMessage());
    $_SESSION['error_message'] = 'تعذر تصدير التقرير المالي.';
    header('Location: finance_reports.php');
    exit();
}
