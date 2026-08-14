<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

try {
    $stmt = $db->query("
        SELECT s.*, 
            (SELECT COUNT(*) FROM teacher_subjects WHERE subject_id = s.id) as teachers_count
        FROM subjects s
        ORDER BY s.sort_order, s.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $data[] = ['الترتيب', 'اسم المادة', 'الكود', 'مادة أساسية', 'عدد المعلمين', 'الحالة'];

    foreach ($rows as $row) {
        $data[] = [
            $row['sort_order'],
            $row['name'],
            $row['code'] ?? '',
            ($row['is_core'] == 1) ? 'نعم' : 'لا',
            $row['teachers_count'],
            ($row['is_active'] == 1) ? 'نشطة' : 'معطلة'
        ];
    }

    $excel_handler = new ExcelHandler();
    $filepath = $excel_handler->exportToExcel($data, 'تقرير_المواد');

    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($ext === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="تقرير_المواد_' . date('Y-m-d') . '.xlsx"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="تقرير_المواد_' . date('Y-m-d') . '.csv"');
        }
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
} catch (Exception $e) {
    error_log("Export subjects error: " . $e->getMessage());
    header('Location: subjects.php?error=export_failed');
    exit;
}
