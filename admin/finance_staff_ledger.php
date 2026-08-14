<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();
$financePage = [
    'title' => 'السجل المالي للعاملين', 'icon' => 'fa-address-book', 'view' => 'staff_ledger',
    'money_total_field' => 'amount_delta', 'filters' => ['staff_id'],
    'columns' => [
        ['key' => 'staff_id', 'label' => 'العامل'], ['key' => 'transaction_id', 'label' => 'العملية'],
        ['key' => 'source_type', 'label' => 'المصدر'], ['key' => 'bucket_code', 'label' => 'البند'],
        ['key' => 'amount_delta', 'label' => 'الحركة', 'type' => 'money'], ['key' => 'description', 'label' => 'البيان'],
        ['key' => 'posted_at', 'label' => 'وقت التثبيت', 'type' => 'date'], ['key' => 'reversal_of', 'label' => 'عكس العملية'],
    ],
];
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
