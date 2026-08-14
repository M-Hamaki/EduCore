<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

final class StudentCompletenessPresenter
{
    private const ENROLLMENT_LABELS = [
        'enrolled' => 'مقيد',
        'transferred' => 'منقول',
        'discontinued' => 'منقطع',
        'graduated' => 'خريج',
        'withdrawn' => 'منسحب',
    ];
    private const ACADEMIC_LABELS = [
        'new' => 'مستجد',
        'promoted' => 'ناجح ومنقول',
        'retained' => 'راسب',
        'graduated' => 'خريج',
    ];
    private const ANNUAL_STATE_META = [
        'ready' => ['label' => 'سجل سنوي سليم', 'badge' => 'success', 'icon' => 'fa-check-circle'],
        'missing_enrollment' => ['label' => 'لا يوجد سجل للعام', 'badge' => 'danger', 'icon' => 'fa-file-circle-xmark'],
        'missing_structure' => ['label' => 'بيانات دراسية ناقصة', 'badge' => 'danger', 'icon' => 'fa-triangle-exclamation'],
        'inconsistent_structure' => ['label' => 'تسكين غير متسق', 'badge' => 'danger', 'icon' => 'fa-link-slash'],
        'awaiting_placement' => ['label' => 'بانتظار التسكين', 'badge' => 'warning', 'icon' => 'fa-clock'],
    ];

    /** @return array<string,mixed> */
    public function dataTableRow(array $record, int $rowNumber): array
    {
        $profilePct = (int) $record['profile_pct'];
        $profileMeta = $this->profileMeta((string) $record['profile_level']);
        $stateMeta = self::ANNUAL_STATE_META[$record['annual_state']] ?? self::ANNUAL_STATE_META['missing_structure'];
        $studentCode = trim((string) ($record['student_code'] ?? ''));
        $name = self::h((string) $record['name']);
        $code = $studentCode !== '' ? self::h($studentCode) : 'بدون كود';
        $experimentalBadge = (int) ($record['effective_is_experimental'] ?? 0) === 1
            ? '<span class="badge bg-secondary ms-1">تجريبي</span>'
            : '';

        $stage = self::h((string) ($record['stage_name'] ?: 'مرحلة غير محددة'));
        $grade = self::h((string) ($record['grade_name'] ?: 'صف غير محدد'));
        $class = self::h((string) ($record['class_name'] ?: 'بدون فصل'));
        $placement = '<div class="fw-semibold text-dark">' . $stage . '</div>'
            . '<div class="small text-muted">' . $grade . ' · ' . $class . '</div>';

        $effectiveEnrollment = !empty($record['enrollment_id'])
            ? (string) $record['enrollment_status']
            : (string) ($record['profile_enrollment_status'] ?? '');
        $enrollmentLabel = self::ENROLLMENT_LABELS[$effectiveEnrollment] ?? 'غير محددة';
        $academicLabel = self::ACADEMIC_LABELS[(string) ($record['academic_status'] ?? '')] ?? 'غير محددة';
        $status = '<div><span class="badge bg-primary-subtle text-primary-emphasis">' . self::h($enrollmentLabel) . '</span></div>'
            . '<div class="small text-muted mt-1">' . self::h($academicLabel) . '</div>';

        $profile = '<div class="d-flex align-items-center gap-2">'
            . '<div class="progress flex-grow-1" role="progressbar" aria-label="اكتمال ملف الطالب" aria-valuenow="' . $profilePct . '" aria-valuemin="0" aria-valuemax="100">'
            . '<div class="progress-bar bg-' . $profileMeta['badge'] . '" style="width:' . $profilePct . '%"></div></div>'
            . '<span class="fw-bold text-' . $profileMeta['badge'] . '">' . $profilePct . '%</span></div>'
            . '<div class="small text-muted mt-1">' . $profileMeta['label'] . '</div>';

        $annual = '<span class="badge bg-' . $stateMeta['badge'] . '-subtle text-' . $stateMeta['badge'] . '-emphasis">'
            . '<i class="fas ' . $stateMeta['icon'] . ' me-1"></i>' . $stateMeta['label'] . '</span>';

        $essential = array_values((array) ($record['missing_essential'] ?? []));
        if ($essential === []) {
            $missing = '<span class="text-success small"><i class="fas fa-check me-1"></i>لا توجد نواقص أساسية</span>';
        } else {
            $visible = array_slice($essential, 0, 3);
            $chips = array_map(
                static fn(string $label): string => '<span class="badge bg-light text-dark border me-1 mb-1">' . self::h($label) . '</span>',
                $visible
            );
            $remaining = count($essential) - count($visible);
            if ($remaining > 0) {
                $chips[] = '<span class="badge bg-warning-subtle text-warning-emphasis">+' . $remaining . '</span>';
            }
            $missing = implode('', $chips);
        }

        $studentId = (int) $record['id'];
        $actions = '<button type="button" class="btn btn-action-pills btn-edit me-1 js-completeness-details" '
            . 'data-bs-toggle="tooltip" title="عرض تفاصيل الاكتمال"><i class="fas fa-eye"></i></button>'
            . '<a class="btn btn-action-pills btn-edit" href="students.php?action=edit&amp;id=' . $studentId . '" '
            . 'data-bs-toggle="tooltip" title="تعديل بيانات الطالب"><i class="fas fa-edit"></i></a>';

        return [
            'DT_RowId' => 'student_' . $studentId,
            'num' => $rowNumber,
            'student' => '<div class="fw-bold text-dark">' . $name . $experimentalBadge . '</div><div class="small text-muted">' . $code . '</div>',
            'placement' => $placement,
            'annual_status' => $status,
            'profile' => $profile,
            'annual_readiness' => $annual,
            'missing' => $missing,
            'actions' => $actions,
            'details' => [
                'student_id' => $studentId,
                'name' => (string) $record['name'],
                'student_code' => $studentCode,
                'profile_pct' => $profilePct,
                'profile_level_label' => $profileMeta['label'],
                'annual_state_label' => $stateMeta['label'],
                'stage_name' => (string) ($record['stage_name'] ?? ''),
                'grade_name' => (string) ($record['grade_name'] ?? ''),
                'class_name' => (string) ($record['class_name'] ?? ''),
                'enrollment_status_label' => $enrollmentLabel,
                'academic_status_label' => $academicLabel,
                'section_percentages' => (array) ($record['section_percentages'] ?? []),
                'missing_fields' => (array) ($record['missing_fields'] ?? []),
            ],
        ];
    }

    /** @return array{label:string,badge:string} */
    private function profileMeta(string $level): array
    {
        if ($level === 'complete') {
            return ['label' => 'مكتمل وفق الإعدادات', 'badge' => 'success'];
        }
        if ($level === 'partial') {
            return ['label' => 'يحتاج استكمالاً', 'badge' => 'warning'];
        }
        return ['label' => 'نواقص جوهرية', 'badge' => 'danger'];
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
