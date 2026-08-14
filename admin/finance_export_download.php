<?php
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Operations\Audit\AuditService;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');

try {
    $database = new Database();
    $db = $database->getConnection();
    $download = (new FinanceServiceFactory($db, new AuditService($db)))->exportService()->download((string) ($_GET['ref'] ?? ''), (int) ($_SESSION['user_id'] ?? 0));
    $mimes = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . $mimes[$download['extension']]);
    header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
    header('Content-Length: ' . strlen($download['contents']));
    header('X-Content-Type-Options: nosniff');
    echo $download['contents'];
} catch (Throwable $exception) {
    error_log('Finance export download failed: ' . $exception->getMessage());
    http_response_code(404);
    echo 'الملف غير موجود أو انتهت مدة الاحتفاظ به.';
}
