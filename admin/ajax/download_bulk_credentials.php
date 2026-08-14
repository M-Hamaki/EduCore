<?php

declare(strict_types=1);

require_once '../../config/database.php';
require_once '../../classes/utilities.php';
require_once '../../includes/session_config.php';

Utilities::validateSession('admin');

$token = (string)($_GET['token'] ?? '');
if ($token === '' || empty($_SESSION['bulk_credentials_export'][$token])) {
    http_response_code(404);
    echo 'ملف التصدير غير موجود أو انتهت صلاحية التنزيل.';
    exit();
}

$export = $_SESSION['bulk_credentials_export'][$token];
unset($_SESSION['bulk_credentials_export'][$token]);
if ((int)($export['created_at'] ?? 0) < (time() - 600)) {
    http_response_code(410);
    echo 'انتهت صلاحية ملف التصدير. أعد تنفيذ عملية التصدير.';
    exit();
}

$type = (string)($export['type'] ?? 'account');
$rows = is_array($export['data'] ?? null) ? $export['data'] : [];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="bulk_' . $type . '_credentials_' . date('Y-m-d_H-i') . '.csv"');
header('X-Content-Type-Options: nosniff');

$safeCsvCell = static function ($value): string {
    $value = (string)$value;
    return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
};

$output = fopen('php://output', 'w');
if ($output !== false) {
    // UTF-8 BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");
    $codeHeader = ($type === 'student') ? 'كود الطالب' : 'كود الموظف';
    fputcsv($output, array_map($safeCsvCell, [$codeHeader, 'الاسم', 'اسم المستخدم', 'كلمة المرور']));

    foreach ($rows as $row) {
        $code = (string)($row['student_code'] ?? $row['employee_code'] ?? '');
        $name = (string)($row['name'] ?? '');
        $username = (string)($row['username'] ?? '');
        $password = (string)($row['password'] ?? '');
        fputcsv($output, array_map($safeCsvCell, [$code, $name, $username, $password]));
    }
    fclose($output);
}
exit();
