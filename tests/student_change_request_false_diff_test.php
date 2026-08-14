<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Students/Presentation/StudentChangeRequestPresenter.php';

use EduCore\Modules\Students\Presentation\StudentChangeRequestPresenter;

$presenter = new StudentChangeRequestPresenter([
    'grade_id' => 'الصف الدراسي',
    'class_id' => 'الفصل',
    'second_name_ar' => 'اسم الأب بالعربية',
]);

$legacyHydration = $presenter->diffRows(
    ['display' => ['grade_id' => '', 'second_name_ar' => 'محمد']],
    [
        '__format' => 'full_profile_v1',
        'request' => ['grade_id' => '2', 'class_id' => '9'],
        'display' => ['grade_id' => '2', 'second_name_ar' => 'محمددددد'],
    ],
    ['current_grade_id' => '2', 'current_class_id' => '9']
);

if (count($legacyHydration) !== 1
    || ($legacyHydration[0]['label'] ?? '') !== 'اسم الأب بالعربية') {
    throw new RuntimeException('Legacy grade hydration must be hidden while the real name change remains visible.');
}

$realAcademicChange = $presenter->diffRows(
    ['display' => ['grade_id' => '', 'class_id' => '7']],
    [
        '__format' => 'full_profile_v1',
        'request' => ['grade_id' => '2', 'class_id' => '9'],
        'display' => ['grade_id' => '2', 'class_id' => '9'],
    ],
    ['current_grade_id' => '2', 'current_class_id' => '9']
);

if (count($realAcademicChange) !== 2) {
    throw new RuntimeException('A real class/grade change must remain visible.');
}

echo "Student change request false-diff test passed.\n";
