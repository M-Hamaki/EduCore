<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financePage = [
    'title' => 'سجل عمليات المالية', 'icon' => 'fa-history', 'view' => 'audit_log',
    'columns' => [
        ['key' => 'created_at', 'label' => 'الوقت', 'type' => 'date'], ['key' => 'user_name', 'label' => 'المنفذ'],
        ['key' => 'action', 'label' => 'العملية'], ['key' => 'target_type', 'label' => 'نوع السجل'],
        ['key' => 'target_id', 'label' => 'المعرف'], ['key' => 'target_name', 'label' => 'البيان'],
        ['key' => 'result', 'label' => 'النتيجة', 'type' => 'status'],
    ],
];
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
