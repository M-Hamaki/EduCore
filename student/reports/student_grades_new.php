<?php
require_once '../../includes/session_config.php';
require_once '../../classes/utilities.php';

Utilities::validateSession('student');

// عرض الدرجات القديم مؤرشف؛ published_reports.php يعرض التقارير المنشورة بنفس روح التصميم القديم.
header('Location: published_reports.php');
exit();
