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
        SELECT c.id, c.name, c.room_location, c.display_order, c.status, g.grade_name, s.stage_name,
            COUNT(DISTINCT u.id) as student_count
        FROM classes c
        LEFT JOIN grades g ON c.grade_id = g.id
        LEFT JOIN stages s ON g.stage_id = s.id
        LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active'
        GROUP BY c.id
        ORDER BY c.display_order, s.stage_order, g.grade_order, c.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $data[] = ['الترتيب', 'اسم الفصل', 'مقر الفصل', 'المرحلة', 'الصف الدراسي', 'عدد الطلاب', 'الحالة'];

    foreach ($rows as $row) {
        $data[] = [
            $row['display_order'],
            $row['name'],
            $row['room_location'] ?? '',
            $row['stage_name'] ?? '',
            $row['grade_name'] ?? '',
            $row['student_count'],
            ($row['status'] === 'active') ? 'نشط' : 'معطل'
        ];
    }

    $excel_handler = new ExcelHandler();
    $filepath = $excel_handler->exportToExcel($data, 'تقرير_الفصول');

    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($ext === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="تقرير_الفصول_' . date('Y-m-d') . '.xlsx"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="تقرير_الفصول_' . date('Y-m-d') . '.csv"');
        }
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
} catch (Exception $e) {
    error_log("Export classes error: " . $e->getMessage());
    header('Location: classes.php?error=export_failed');
    exit;
}
