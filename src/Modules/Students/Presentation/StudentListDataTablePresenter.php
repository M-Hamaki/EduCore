<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

use ProfileAttachmentStorage;
use User;

final class StudentListDataTablePresenter
{
    private const OPTIONAL_COLUMN_CLASSES = [
        'col-birth-date', 'col-current-age', 'col-gender', 'col-religion',
        'col-city-area', 'col-phone-emergency', 'col-enrollment-date', 'col-passport',
        'col-nationality', 'col-birth-place', 'col-ministry-code', 'col-previous-school',
        'col-name-en', 'col-age-october', 'col-guardianship', 'col-notes',
        'col-phone-mobile', 'col-phone-home', 'col-address', 'col-blood-type',
        'col-insurance-number', 'col-insurance-start', 'col-insurance-end', 'col-health-status',
        'col-chronic', 'col-allergies', 'col-disabilities', 'col-medications',
        'col-treatment', 'col-medical-reports', 'col-emergency-notes', 'col-psychological',
        'col-father-name', 'col-father-mobile', 'col-father-landline', 'col-father-email',
        'col-father-address', 'col-father-national-id', 'col-father-qualification', 'col-father-job',
        'col-father-employer', 'col-father-work-phone', 'col-father-birth-date', 'col-father-religion',
        'col-father-nationality', 'col-father-passport', 'col-mother-name', 'col-mother-mobile',
        'col-mother-landline', 'col-mother-email', 'col-mother-address', 'col-mother-national-id',
        'col-mother-qualification', 'col-mother-job', 'col-mother-employer', 'col-mother-work-phone',
        'col-mother-birth-date', 'col-mother-religion', 'col-mother-nationality', 'col-mother-passport',
    ];

    public function rows(
        array $students,
        int $offset,
        string $basePage,
        string $backQueryAmp,
        string $scope,
        ?array $visibleColumns = null,
        bool $canArchive = true
    ): array
    {
        $rows = [];
        $additionalColumns = StudentListColumnCatalog::additionalColumns();
        foreach ($students as $index => $student) {
            $cells = [];
            $cells[] = (string) ($offset + $index + 1);
            $cells[] = $this->text($student['student_code'] ?? '-', 'col-student-code', 'ltr');
            $cells[] = $this->text($student['national_id'] ?? '-', 'col-national-id');
            $cells[] = '<a href="' . $this->e($basePage) . '?action=view&id=' . (int) $student['id'] . $this->e($backQueryAmp) . '" class="text-decoration-none fw-bold" title="عرض الملف الشخصي">' . $this->e($student['name'] ?? '') . '</a>';
            $cells[] = $this->text($student['class_name'] ?? '', 'col-class', null, 'غير مسند لفصل');
            $cells[] = $this->text($student['birth_date'] ?? '-', 'col-birth-date d-none');
            $age = User::calculateCurrentAge($student['birth_date'] ?? null);
            $cells[] = $this->text(($age && empty($age['is_future'])) ? (int) $age['years'] . ' سنة' : '-', 'col-current-age d-none');
            $cells[] = $this->text($this->label($student['gender'] ?? '', ['male' => 'ذكر', 'female' => 'أنثى']), 'col-gender d-none');
            $cells[] = $this->text($this->label($student['religion'] ?? '', ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى']), 'col-religion d-none');
            foreach ([['city_area', null], ['phone_emergency', 'ltr'], ['enrollment_date', null], ['passport_number', 'ltr'], ['nationality', null], ['birth_place', null], ['ministry_code', 'ltr'], ['previous_school', null]] as $item) {
                $cells[] = $this->text($student[$item[0]] ?? '-', 'col-' . str_replace('_', '-', $item[0]) . ' d-none', $item[1]);
            }
            $english = trim(implode(' ', array_filter([$student['first_name_en'] ?? '', $student['second_name_en'] ?? '', $student['third_name_en'] ?? '', $student['fourth_name_en'] ?? '', $student['family_name_en'] ?? ''])));
            $cells[] = $this->text($english ?: '-', 'col-name-en d-none');
            $october = !empty($student['age_years']) ? (int) $student['age_years'] . ' سنة' . (isset($student['age_months']) ? ' و ' . (int) $student['age_months'] . ' شهر' : '') : '-';
            $cells[] = $this->text($october, 'col-age-october d-none');
            $cells[] = $this->text($this->guardianship($student['extra_data'] ?? ''), 'col-guardianship d-none');
            $cells[] = $this->detail($student['notes'] ?? '', 'ملاحظات عامة', 'fa-sticky-note text-warning', 'col-notes d-none');
            $cells[] = $this->text($student['phone_mobile'] ?? '-', 'col-phone-mobile d-none', 'ltr');
            $cells[] = $this->text($student['phone_home'] ?? '-', 'col-phone-home d-none', 'ltr');
            $cells[] = $this->detail($student['address_current'] ?? '', 'العنوان التفصيلي', 'fa-map-marker-alt text-primary', 'col-address d-none');
            foreach ([['blood_type', 'ltr'], ['insurance_number', 'ltr'], ['insurance_start_date', null], ['insurance_end_date', null]] as [$field, $direction]) {
                $cells[] = $this->text($student[$field] ?? '-', 'col-' . str_replace('_', '-', $field) . ' d-none', $direction);
            }
            foreach ([
                ['health_status', 'الحالة الصحية العامة', 'fa-notes-medical text-info'], ['chronic_diseases', 'الأمراض المزمنة', 'fa-heart-pulse text-danger'],
                ['allergies', 'الحساسية', 'fa-allergies text-danger'], ['disabilities', 'الإعاقات', 'fa-wheelchair text-danger'], ['medications', 'العلاج / الأدوية', 'fa-pills text-info'], ['treatment_plan', 'خطط علاجية متبعة', 'fa-clipboard-list text-info'],
                ['previous_medical_reports', 'تقارير طبية سابقة', 'fa-file-medical text-info'], ['emergency_medical_notes', 'ملاحظات طبية طارئة', 'fa-triangle-exclamation text-danger'],
                ['psychological_notes', 'ملاحظات نفسية وسلوكية', 'fa-brain text-info'],
            ] as [$field, $title, $icon]) {
                $class = 'col-' . str_replace(['_diseases', '_notes', '_reports'], ['', '', ''], str_replace('_', '-', $field)) . ' d-none';
                $class = $this->healthClass($field);
                $cells[] = $this->detail($student[$field] ?? '', $title, $icon, $class);
            }
            foreach (['father' => 'الأب', 'mother' => 'الأم'] as $parent => $parentLabel) {
                foreach ([['name', null], ['mobile', 'ltr'], ['landline', 'ltr'], ['email', 'ltr'], ['address', 'detail'], ['national_id', 'ltr'], ['qualification', null], ['job', null], ['employer', null], ['work_phone', 'ltr'], ['birth_date', null], ['religion', 'religion'], ['nationality', null], ['passport', 'ltr']] as [$field, $type]) {
                    $value = $student[$parent . '_' . $field] ?? '';
                    $class = 'col-' . $parent . '-' . str_replace('_', '-', $field) . ' d-none';
                    $cells[] = $type === 'detail' ? $this->detail($value, 'عنوان ' . $parentLabel, 'fa-map-marker-alt text-primary', $class) : $this->text($type === 'religion' ? $this->label($value, ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى']) : ($value ?: '-'), $class, $type === 'ltr' ? 'ltr' : null);
                }
            }
            foreach ($additionalColumns as $column) {
                $cells[] = $this->additionalColumnCell($column, $student);
            }
            if ($scope === 'transferred') {
                $cells[] = $this->text($student['transfer_destination'] ?? '-');
                $cells[] = $this->text($student['external_transfer_date'] ?? '-', 'text-nowrap');
            }
            $enrollmentStatus = $student['enrollment_status'] ?? 'enrolled';
            $academicStatus = $student['academic_status'] ?? (($student['status'] ?? '') === 'graduated' ? 'graduated' : 'new');
            $enrollmentLabels = ['enrolled' => 'مقيد', 'transferred' => 'منقول', 'discontinued' => 'منقطع', 'withdrawn' => 'منقطع'];
            $enrollmentClasses = ['enrolled' => 'success', 'transferred' => 'warning text-dark', 'discontinued' => 'secondary', 'withdrawn' => 'secondary'];
            $academicLabels = ['new' => 'مستجد', 'promoted' => 'ناجح ومنقول', 'retained' => 'راسب', 'graduated' => 'خريج'];
            $academicClasses = ['new' => 'info', 'promoted' => 'success', 'retained' => 'warning text-dark', 'graduated' => 'primary'];
            $cells[] = '<div class="d-flex flex-wrap gap-1">'
                . '<span class="badge bg-' . ($enrollmentClasses[$enrollmentStatus] ?? 'secondary') . '">' . $this->e($enrollmentLabels[$enrollmentStatus] ?? $enrollmentStatus) . '</span>'
                . '<span class="badge bg-' . ($academicClasses[$academicStatus] ?? 'secondary') . '">' . $this->e($academicLabels[$academicStatus] ?? $academicStatus) . '</span>'
                . '</div>';
            $cells[] = $this->siblings($student);
            $cells[] = !empty($student['profile_image_id']) ? '<img src="' . $this->e(ProfileAttachmentStorage::adminDownloadUrl('student', (int) $student['profile_image_id'])) . '" class="rounded-circle shadow-sm" style="width:36px;height:36px;object-fit:cover;" alt="صورة">' : '<i class="fas fa-user-graduate text-primary"></i>';
            $actions = '<a href="' . $this->e($basePage) . '?action=edit&id=' . (int) $student['id'] . $this->e($backQueryAmp) . '" class="btn btn-action-pills btn-edit has-tooltip me-1" data-student-id="' . (int) $student['id'] . '" title="تعديل البيانات"><i class="fas fa-edit"></i></a>';
            if ($canArchive) {
                $actions .= '<button type="button" class="btn btn-action-pills btn-deactivate archive-student has-tooltip" data-id="' . (int) $student['id'] . '" data-name="' . $this->e($student['name'] ?? '') . '" data-bs-toggle="modal" data-bs-target="#archiveStudentModal" title="أرشفة"><i class="fas fa-box-archive"></i></button>';
            }
            $cells[] = $actions;
            $rows[] = $this->projectVisibleCells($cells, $scope, $visibleColumns);
        }
        return $rows;
    }

    private function text($value, string $class = '', ?string $dir = null, string $empty = '-'): string { $value = trim((string) $value) === '' ? $empty : (string) $value; return '<span' . ($class ? ' class="' . $this->e($class) . '"' : '') . ($dir ? ' dir="' . $dir . '"' : '') . '>' . $this->e($value) . '</span>'; }
    private function detail($value, string $title, string $icon, string $class): string { $value = trim((string) $value); return $value === '' ? '<span class="' . $this->e($class) . ' text-muted">-</span>' : '<button type="button" class="' . $this->e($class) . ' btn btn-sm btn-link p-0 view-cell-content" data-title="' . $this->e($title) . '" data-content="' . $this->e($value) . '" data-bs-toggle="tooltip" title="' . $this->e($title) . '"><i class="fas ' . $this->e($icon) . '"></i></button>'; }
    private function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    private function label($value, array $labels): string { return $labels[$value] ?? ((string) $value !== '' ? (string) $value : '-'); }
    private function guardianship($json): string { $items = json_decode((string) $json, true); if (!is_array($items)) return '-'; foreach ($items as $item) if (in_array($item['label'] ?? '', ['__educational_guardianship', 'الوصاية التعليمية'], true)) return (string) ($item['value'] ?? '-'); return '-'; }
    private function healthClass(string $field): string { return ['health_status' => 'col-health-status d-none', 'chronic_diseases' => 'col-chronic d-none', 'allergies' => 'col-allergies d-none', 'disabilities' => 'col-disabilities d-none', 'medications' => 'col-medications d-none', 'treatment_plan' => 'col-treatment d-none', 'previous_medical_reports' => 'col-medical-reports d-none', 'emergency_medical_notes' => 'col-emergency-notes d-none', 'psychological_notes' => 'col-psychological d-none'][$field]; }
    private function additionalColumnCell(array $column, array $student): string
    {
        $field = (string) $column['field'];
        $class = (string) $column['class'] . ' d-none';
        $value = StudentExportValueFormatter::format(
            $field,
            $student,
            $this->guardianRows($student),
            isset($student['age_reference_date']) ? (string) $student['age_reference_date'] : null
        );
        if (StudentListColumnCatalog::isDetail($field)) {
            return $this->detail($value === '-' ? '' : $value, (string) $column['label'], $this->additionalIcon($field), $class);
        }
        return $this->text($value, $class, StudentListColumnCatalog::direction($field));
    }
    private function guardianRows(array $student): array
    {
        $guardians = ['father' => [], 'mother' => [], 'others' => []];
        $fieldMap = [
            'name' => 'guardian_name', 'relationship' => 'relationship', 'birth_date' => 'birth_date',
            'birth_place' => 'birth_place', 'religion' => 'religion', 'nationality' => 'nationality',
            'national_id' => 'national_id', 'passport' => 'passport_number', 'mobile' => 'phone_primary',
            'landline' => 'phone_landline', 'email' => 'email', 'address' => 'address',
            'extra_phones' => 'extra_phones', 'qualification' => 'qualification', 'job' => 'job_title',
            'employer' => 'employer', 'work_phone' => 'work_phone', 'extra_data' => 'extra_data',
        ];
        foreach (['father', 'mother'] as $parent) {
            foreach ($fieldMap as $suffix => $target) {
                $field = $parent . '_' . $suffix;
                if (array_key_exists($field, $student)) {
                    $guardians[$parent][$target] = $student[$field];
                }
            }
        }
        if (isset($student['other_guardians_rows']) && is_array($student['other_guardians_rows'])) {
            $guardians['others'] = array_values(array_filter($student['other_guardians_rows'], 'is_array'));
        }
        return $guardians;
    }
    private function additionalIcon(string $field): string
    {
        if (str_contains($field, 'medical') || in_array($field, ['health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications', 'treatment_plan'], true)) return 'fa-notes-medical text-danger';
        if ($field === 'academic_history') return 'fa-graduation-cap text-primary';
        if ($field === 'attachments') return 'fa-paperclip text-secondary';
        if ($field === 'kinships' || $field === 'other_guardians') return 'fa-people-roof text-info';
        return 'fa-circle-info text-primary';
    }
    private function projectVisibleCells(array $cells, string $scope, ?array $visibleColumns): array
    {
        if ($visibleColumns === null) {
            return $cells;
        }

        $additionalColumns = StudentListColumnCatalog::additionalColumns();
        $additionalClasses = array_values(array_map(static fn(array $column): string => (string) $column['class'], $additionalColumns));
        $allowedColumns = array_merge(self::OPTIONAL_COLUMN_CLASSES, $additionalClasses, ['col-siblings', 'col-profile-image']);
        $visible = array_fill_keys(array_intersect($visibleColumns, $allowedColumns), true);
        foreach (self::OPTIONAL_COLUMN_CLASSES as $offset => $columnClass) {
            if (!isset($visible[$columnClass])) {
                $cells[5 + $offset] = '';
            }
        }

        $additionalStart = 5 + count(self::OPTIONAL_COLUMN_CLASSES);
        foreach ($additionalColumns as $offset => $column) {
            if (!isset($visible[(string) $column['class']])) {
                $cells[$additionalStart + $offset] = '';
            }
        }

        $statusIndex = $additionalStart + count($additionalColumns);
        if ($scope === 'transferred') {
            $statusIndex = 67;
        }
        if (!isset($visible['col-siblings'])) {
            $cells[$statusIndex + 1] = '';
        }
        if (!isset($visible['col-profile-image'])) {
            $cells[$statusIndex + 2] = '';
        }

        return $cells;
    }
    private function siblings(array $student): string
    {
        $count = (int) ($student['siblings_count'] ?? 0);
        $encoded = trim((string) ($student['siblings_info'] ?? ''));
        if ($count <= 0 || $encoded === '') {
            return '<span class="col-siblings d-none text-muted">—</span>';
        }

        $items = [];
        foreach (explode(';;', $encoded) as $row) {
            [$name, $class] = array_pad(explode('||', $row, 2), 2, '—');
            $items[] = '<li><strong>' . $this->e($name) . '</strong> — ' . $this->e($class) . '</li>';
        }
        $content = '<ul class="mb-0 ps-3 text-start" style="min-width:160px;">' . implode('', $items) . '</ul>';

        return '<span class="col-siblings d-none badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold" style="cursor:pointer;font-size:.8rem;" data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-html="true" data-bs-title="الإخوة والأشقاء" data-bs-content="' . $this->e($content) . '"><i class="fas fa-users me-1"></i>' . $count . '</span>';
    }
}
