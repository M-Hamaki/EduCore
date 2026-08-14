<?php
require_once '../../includes/session_config.php';
require_once '../../classes/utilities.php';

Utilities::validateSession('student');

// اختيار الشهر القديم لم يعد مصدر البيانات؛ التحكم في الفترة يتم من نافذة التقرير عند الأدمن.
header('Location: published_reports.php');
exit();
