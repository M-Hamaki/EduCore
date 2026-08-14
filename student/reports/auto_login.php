<?php
require_once '../../includes/session_config.php';
require_once '../../classes/utilities.php';

Utilities::validateSession('student');

// نظام التقارير القديم مؤرشف؛ صفحة التقارير المنشورة تستخدم الشكل القديم مع بيانات النظام الجديد.
header('Location: published_reports.php');
exit();
