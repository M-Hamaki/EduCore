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
        SELECT g.*, s.stage_name,
            (SELECT COUNT(*) FROM classes WHERE grade_id = g.id) as classes_count,
            (SELECT COUNT(DISTINCT u.id) FROM users u JOIN classes c ON u.class_id = c.id WHERE c.grade_id = g.id AND u.role = 'student') as students_count
        FROM grades g
        LEFT JOIN stages s ON g.stage_id = s.id
        ORDER BY g.grade_order
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $data[] = ['الترتيب', 'اسم الصف', 'الكود', 'المرحلة', 'الوصف', 'عدد الفصول', 'عدد الطلاب', 'الحالة'];

    foreach ($rows as $row) {
        $data[] = [
            $row['grade_order'],
            $row['grade_name'],
            $row['grade_code'] ?? '',
            $row['stage_name'] ?? '',
            $row['description'] ?? '',
            $row['classes_count'],
            $row['students_count'],
            ($row['status'] === 'active') ? 'نشط' : 'معطل'
        ];
    }

    $excel_handler = new ExcelHandler();
    $filepath = $excel_handler->exportToExcel($data, 'تقرير_الصفوف');

    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($ext === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="تقرير_الصفوف_' . date('Y-m-d') . '.xlsx"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="تقرير_الصفوف_' . date('Y-m-d') . '.csv"');
        }
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
} catch (Exception $e) {
    error_log("Export grades error: " . $e->getMessage());
    header('Location: grades.php?error=export_failed');
    exit;
}
