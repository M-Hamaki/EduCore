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
            COUNT(DISTINCT g.id) as grades_count,
            COUNT(DISTINCT c.id) as classes_count,
            COUNT(DISTINCT u.id) as students_count
        FROM stages s 
        LEFT JOIN grades g ON s.id = g.stage_id 
        LEFT JOIN classes c ON g.id = c.grade_id
        LEFT JOIN users u ON c.id = u.class_id AND u.role = 'student'
        GROUP BY s.id 
        ORDER BY s.stage_order ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $data[] = ['الترتيب', 'اسم المرحلة', 'الاسم بالإنجليزية', 'كود المرحلة', 'عدد الصفوف', 'عدد الفصول', 'عدد الطلاب', 'الحالة'];

    foreach ($rows as $row) {
        $data[] = [
            $row['stage_order'],
            $row['stage_name'],
            $row['stage_name_en'] ?? '',
            $row['stage_code'] ?? '',
            $row['grades_count'],
            $row['classes_count'],
            $row['students_count'],
            ($row['status'] === 'active') ? 'نشط' : 'معطل'
        ];
    }

    $excel_handler = new ExcelHandler();
    $filepath = $excel_handler->exportToExcel($data, 'تقرير_المراحل');

    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($ext === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="تقرير_المراحل_' . date('Y-m-d') . '.xlsx"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="تقرير_المراحل_' . date('Y-m-d') . '.csv"');
        }
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
} catch (Exception $e) {
    error_log("Export stages error: " . $e->getMessage());
    header('Location: stages.php?error=export_failed');
    exit;
}
