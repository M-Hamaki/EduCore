<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/SchemaReadinessGuard.php';

$db = educoreTestDatabase();
$guard = new SchemaReadinessGuard($db);
$guard->assertColumns('canva_templates', ['template_type', 'dataset_json', 'last_error']);
$guard->assertTable('lesson_ppt_templates');
$guard->assertColumns('ai_lessons', [
    'class_activities',
    'educational_stories',
    'mind_maps',
    'lesson_summary',
    'custom_content',
]);

echo "ai_content_schema_ready:PASS\n";
