<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financePage = [
    'title' => 'الحسابات المالية للطلاب', 'icon' => 'fa-user-graduate', 'view' => 'student_accounts',
    'money_total_field' => 'net_account_position', 'filters' => ['student_id', 'academic_year_id'],
    'columns' => [
        ['key' => 'student_id', 'label' => 'الطالب'], ['key' => 'academic_year_id', 'label' => 'العام الدراسي'],
        ['key' => 'outstanding_due', 'label' => 'المستحق', 'type' => 'money'], ['key' => 'unapplied_credit', 'label' => 'رصيد مقدم', 'type' => 'money'],
        ['key' => 'net_account_position', 'label' => 'صافي الموقف', 'type' => 'money'],
    ],
    'toolbar_links' => [['href' => 'finance_student_ledger.php', 'label' => 'سجل طالب', 'icon' => 'fa-book-open']],
];
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
