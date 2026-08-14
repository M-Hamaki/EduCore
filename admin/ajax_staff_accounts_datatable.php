<?php
declare(strict_types=1);
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/StaffAccountSchemaGuard.php';
require_once '../classes/AccountListDataTableQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');
try {
    $db=(new Database())->getConnection(); (new StaffAccountSchemaGuard($db))->assertReady();
    $portalRoles=['employee'=>'موظف','teacher'=>'معلم','specialist'=>'أخصائي','doctor'=>'طبيب','librarian'=>'أمين مكتبة'];
    $roles=$portalRoles;
    $colors=['employee'=>'secondary','teacher'=>'primary','specialist'=>'success','doctor'=>'danger','librarian'=>'warning text-dark','admin'=>'purple','super_admin'=>'dark'];
    $palette=['purple','dark','success','danger','warning text-dark','info text-dark','primary']; $idx=0;
    $stmt=$db->query("SELECT role_key, role_name FROM staff_roles WHERE status = 'active' ORDER BY role_name");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $role) { $key=(string)$role['role_key']; if (isset($portalRoles[$key]) || in_array($key, ['employee','student','external_teacher','admin','super_admin','supervisor'], true)) continue; $portalRoles[$key]=(string)$role['role_name']; $roles[$key]=(string)$role['role_name']; $colors[$key]=$palette[$idx%count($palette)]; $idx++; }
    if ((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '') === 'super_admin') {
        $portalRoles['admin']='مدير نظام'; $portalRoles['super_admin']='مدير النظام الأعلى';
        $roles['admin']='مدير نظام'; $roles['super_admin']='مدير النظام الأعلى';
    }
    $accountListQuery = new AccountListDataTableQuery($db);
    $response = $accountListQuery->loadStaff(
        $_POST,
        array_keys($portalRoles),
        $roles,
        $colors,
        (int)($_SESSION['user_id']??0),
        (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '') === 'super_admin'
    );
    $response['summary'] = $accountListQuery->staffSummary(array_keys($portalRoles), $_POST);
    echo json_encode($response,JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { error_log('Staff account DataTables endpoint: '.$e->getMessage()); http_response_code(500); echo json_encode(['draw'=>(int)($_POST['draw']??0),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]],JSON_UNESCAPED_UNICODE); }
