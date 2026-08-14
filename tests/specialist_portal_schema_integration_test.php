<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
$db=educoreTestDatabase();
$databaseName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
if($databaseName==='educore'||!preg_match('/_test$/',$databaseName))throw new RuntimeException('Specialist portal schema test database guard failed.');

$scopeMigration=require dirname(__DIR__).'/database/migrations/20260719_specialist_academic_scope.php';
$scopeMigration($db);
$requestMigration=require dirname(__DIR__).'/database/migrations/20260719_student_change_requests.php';
$requestMigration($db);

$objectType=static function(PDO $db,string $name):string{
    $stmt=$db->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$name]);return(string)($stmt->fetchColumn()?:'');
};
$columns=static function(PDO $db,string $table):array{
    $stmt=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $stmt->execute([$table]);return $stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
};
$checks=[
    'specialist_grade_table_created'=>$objectType($db,'specialist_grade_assignments')==='BASE TABLE',
    'specialist_class_table_created'=>$objectType($db,'specialist_class_assignments')==='BASE TABLE',
    'active_scope_view_created'=>$objectType($db,'specialist_active_classes')==='VIEW',
    'student_change_request_table_created'=>$objectType($db,'student_change_requests')==='BASE TABLE',
    'scope_tables_are_annual'=>in_array('academic_year_id',$columns($db,'specialist_grade_assignments'),true)
        && in_array('academic_year_id',$columns($db,'specialist_class_assignments'),true),
    'request_workflow_columns_exist'=>count(array_intersect(['before_payload','proposed_payload','status','reviewed_by','rejection_reason'],$columns($db,'student_change_requests')))===5,
];
$failed=false;foreach($checks as $name=>$pass){echo $name.':'.($pass?'PASS':'FAIL').PHP_EOL;$failed=$failed||!$pass;}exit($failed?1:0);
