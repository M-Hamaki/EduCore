<?php
declare(strict_types=1);
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AccountListDataTableQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();
header('Content-Type: application/json; charset=utf-8');
try { $db=(new Database())->getConnection(); echo json_encode((new AccountListDataTableQuery($db))->loadStudents($_POST, AcademicYear::currentId($db)), JSON_UNESCAPED_UNICODE); }
catch (Throwable $e) { error_log('Student account DataTables endpoint: '.$e->getMessage()); echo json_encode(['draw'=>(int)($_POST['draw']??0),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
