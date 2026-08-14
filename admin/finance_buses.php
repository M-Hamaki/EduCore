<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financePage = [
    'title' => 'ماليات الحافلات', 'icon' => 'fa-bus', 'view' => 'buses', 'money_total_field' => 'net_due',
    'columns' => [
        ['key' => 'charge_id', 'label' => 'المستحق'], ['key' => 'student_id', 'label' => 'الطالب'],
        ['key' => 'academic_year_id', 'label' => 'العام'], ['key' => 'charge_name', 'label' => 'نوع الاشتراك'],
        ['key' => 'net_due', 'label' => 'القيمة', 'type' => 'money'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
        ['key' => 'posted_at', 'label' => 'التاريخ', 'type' => 'date'],
    ],
];
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
