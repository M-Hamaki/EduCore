<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';

Utilities::validateSession('admin');

$_SESSION['success_message'] = 'تمت أرشفة إعدادات التقارير القديمة. استخدم نوافذ التقارير في محرك الدرجات الجديد.';
header('Location: assessment_reports.php');
exit();
