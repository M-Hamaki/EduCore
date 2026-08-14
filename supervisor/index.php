<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';

if (!isset($_SESSION['user_id']) || !Utilities::isSupervisor() || ($_SESSION['active_mode'] ?? '') !== 'supervisor') {
    header('Location: select_mode.php');
    exit;
}

$db=(new Database())->getConnection();
$userId=(int)$_SESSION['user_id'];
$yearId=AcademicYear::currentId($db);
$stmt=$db->prepare("SELECT c.id,c.name,g.grade_name,COUNT(DISTINCT se.student_id) student_count
    FROM user_class_access uca
    JOIN classes c ON c.id=uca.class_id
    LEFT JOIN grades g ON g.id=c.grade_id
    LEFT JOIN student_enrollments se ON se.class_id=c.id AND se.academic_year_id=? AND se.enrollment_status='enrolled'
    WHERE uca.user_id=? GROUP BY c.id,c.name,g.grade_name,g.grade_order,c.display_order
    ORDER BY g.grade_order,c.display_order,c.name");
$stmt->execute([$yearId,$userId]);
$classes=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
$studentCount=array_sum(array_map(static fn($row)=>(int)$row['student_count'],$classes));
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$csrf=urlencode((string)($_SESSION['csrf_token']??''));
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بوابة المشرف</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/premium-dashboard.css"><link rel="stylesheet" href="../assets/css/buttons.css"><link rel="stylesheet" href="../assets/css/admin-unified.css"></head><body class="admin-page app-light-mode"><main class="container py-4">
<div class="d-flex justify-content-between flex-wrap align-items-center pb-3 mb-4 border-bottom"><div><h1 class="h2"><i class="fas fa-user-shield me-2 text-primary"></i>بوابة المشرف</h1><small class="text-muted">بوابة مستقلة لا تستخدم تعيينات أو صلاحيات الأخصائي</small></div><div class="btn-toolbar gap-2"><a class="btn btn-outline-primary" href="../staff_hr_portal.php"><i class="fas fa-people-roof me-1"></i>خدمات العاملين</a><a class="btn btn-outline-secondary" href="select_mode.php"><i class="fas fa-table-cells me-1"></i>اختيار الوضع</a><a class="btn btn-primary" href="select_mode.php?switch=teacher&amp;csrf_token=<?php echo $csrf;?>"><i class="fas fa-chalkboard-user me-1"></i>وضع المعلم</a></div></div>
<div class="row row-cols-2 g-3 mb-4"><div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#3b82f6,#2563eb);"><div class="stat-card-icon"><i class="fas fa-school"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo count($classes);?>">0</div><div class="stat-card-label">الفصول المرتبطة</div></div></div></div><div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#10b981,#059669);"><div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $studentCount;?>">0</div><div class="stat-card-label">الطلاب</div></div></div></div></div>
<div class="admin-list-surface"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>#</th><th>الصف</th><th>الفصل</th><th>الطلاب</th></tr></thead><tbody><?php foreach($classes as $i=>$row):?><tr><td><?php echo $i+1;?></td><td><?php echo $e($row['grade_name']??'—');?></td><td><?php echo $e($row['name']);?></td><td><?php echo (int)$row['student_count'];?></td></tr><?php endforeach;?><?php if(!$classes):?><tr><td colspan="4" class="text-center text-muted py-5">لا توجد فصول مرتبطة بحساب المشرف.</td></tr><?php endif;?></tbody></table></div></div>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/premium-dashboard.js"></script></body></html>
