<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StudentProfilePayload.php';

$checks = [];
$checks['full_name'] = StudentProfilePayload::fullName(['first_name_ar' => ' محمد ', 'second_name_ar' => 'علي']) === 'محمد علي';
$checks['bulk_name'] = StudentProfilePayload::splitBulkName('أ ب ج د هـ و')['family_name_ar'] === 'هـ و';
$parents = StudentProfilePayload::normalizeFixedParents([], ['second_name_ar' => 'أحمد', 'third_name_ar' => 'علي']);
$checks['fixed_parents'] = count($parents) === 2 && $parents[0]['relationship'] === 'father'
    && $parents[0]['guardian_name'] === 'أحمد علي' && $parents[1]['relationship'] === 'mother';
$relationships = StudentProfilePayload::normalizeRelationships([['relationship' => 'عم خاص', 'relationship_other' => '']]);
$checks['custom_relationship'] = $relationships[0]['relationship'] === 'other' && $relationships[0]['relationship_other'] === 'عم خاص';
$json = json_encode([['label' => 'هواية', 'value' => 'رسم'], ['label' => '__educational_guardianship', 'value' => 'mother']], JSON_UNESCAPED_UNICODE);
$filtered = [];
$guardianship = StudentProfilePayload::extractEducationalGuardianship($json, $filtered);
$checks['guardianship_extract'] = $guardianship === 'mother' && $filtered === [['label' => 'هواية', 'value' => 'رسم']];
$checks['guardianship_merge'] = json_decode(StudentProfilePayload::mergeEducationalGuardianship(json_encode($filtered), 'father') ?? '', true)[1]['value'] === 'father';
$checks['phones'] = json_decode(StudentProfilePayload::studentExtraPhones(['student_mobile_numbers' => ['010'], 'student_mobile_notes' => ['أساسي']]) ?? '', true)[0]['number'] === '010';
$checks['activity_guardian_count'] = isset(StudentProfilePayload::activityDetails(['guardian_count' => 1], ['guardian_count' => 2])['related_changes']['guardian_count']);

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
