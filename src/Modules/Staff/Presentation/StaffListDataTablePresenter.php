<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use StaffEmploymentLifecycleService;

final class StaffListDataTablePresenter
{
    public function rows(array $staff, int $offset): array
    {
        $rows = [];
        foreach ($staff as $index => $row) {
            $name = trim((string) ($row['full_name_ar'] ?? '')) ?: (string) ($row['name'] ?? '');
            $cells = [
                (string) ($offset + $index + 1),
                $this->text($row['biometric_id'] ?? '-', 'ltr'),
                $this->text($row['employee_code'] ?? '-', 'ltr'),
                '<a href="staff.php?action=view&id=' . (int) $row['id'] . '" class="text-decoration-none fw-bold" title="عرض الملف الشخصي">' . $this->e($name) . '</a>',
                $this->text(StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null) ?? '-'),
                $this->text($row['phone_mobile'] ?? '-', 'ltr'),
                $this->text($row['national_id'] ?? '-', 'ltr'),
                $this->text($row['passport_number'] ?? '-', 'ltr'),
                $this->text($row['birth_date'] ?? '-'),
                $this->text($row['birth_place'] ?? '-'),
                $this->text($this->label($row['gender'] ?? '', ['male' => 'ذكر', 'female' => 'أنثى'])),
                $this->text($this->label($row['religion'] ?? '', ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'])),
                $this->text($row['nationality'] ?? '-'),
                $this->text($row['ministry_code'] ?? '-', 'ltr'),
                $this->text($row['military_status'] ?? '-'),
                $this->text($row['marital_status'] ?? '-'),
                $this->text($row['number_of_children'] ?? '-'),
                $this->text($row['city_area'] ?? '-'),
                $this->detail($row['address_detail'] ?? '', 'العنوان التفصيلي', 'fa-map-marker-alt text-primary'),
                $this->text($row['phone_home'] ?? '-', 'ltr'),
                $this->text($row['phone_emergency'] ?? '-', 'ltr'),
                $this->text($row['email_personal'] ?? '-', 'ltr'),
                $this->text($row['emergency_contact_name'] ?? '-'),
                $this->text($row['qualification'] ?? '-'),
                $this->text($row['qualification_year'] ?? '-'),
                $this->text($row['qualification_university'] ?? '-'),
                $this->text($row['specialization'] ?? '-'),
                $this->text($row['years_of_experience'] ?? '-'),
                $this->contract($row['contract_type'] ?? ''),
                $this->text($row['blood_type'] ?? '-', 'ltr'),
                $this->text($row['insurance_number'] ?? '-', 'ltr'),
                $this->text($row['insurance_start_date'] ?? '-'),
                $this->text($row['insurance_end_date'] ?? '-'),
            ];
            foreach ([
                ['health_status', 'الحالة الصحية العامة', 'fa-notes-medical text-info'], ['chronic_diseases', 'أمراض مزمنة', 'fa-heart-pulse text-danger'], ['allergies', 'الحساسية', 'fa-allergies text-danger'], ['disabilities', 'الإعاقات', 'fa-wheelchair text-danger'], ['medications', 'الأدوية', 'fa-pills text-info'], ['treatment_plan', 'خطط علاجية', 'fa-clipboard-list text-info'], ['previous_medical_reports', 'تقارير طبية', 'fa-file-medical text-info'], ['emergency_medical_notes', 'ملاحظات طارئة', 'fa-triangle-exclamation text-danger'], ['psychological_notes', 'ملاحظات نفسية', 'fa-brain text-info'],
            ] as [$field, $title, $icon]) $cells[] = $this->detail($row[$field] ?? '', $title, $icon);
            $cells[] = $this->text($row['full_name_en'] ?? '-', 'ltr');
            $cells[] = $this->text($this->currentAge($row['birth_date'] ?? null));
            $cells[] = $this->text($row['public_service_status'] ?? '-');
            $cells[] = $this->detail($row['notes'] ?? '', 'ملاحظات اجتماعية', 'fa-note-sticky text-secondary');
            $cells[] = $this->structuredDetail($row['extra_phones'] ?? '', 'أرقام إضافية', 'fa-phone text-primary');
            $cells[] = $this->structuredDetail($row['extra_data'] ?? '', 'بيانات أساسية إضافية', 'fa-list text-primary');
            $cells[] = $this->detail($row['admin_notes'] ?? '', 'ملاحظة إدارية', 'fa-note-sticky text-warning');
            $cells[] = $this->text($row['department'] ?? '-');
            $cells[] = $this->text($row['job_grade'] ?? '-');
            $cells[] = $this->text($row['hire_date'] ?? '-');
            $cells[] = $this->text($row['contract_start'] ?? '-');
            $cells[] = $this->text($row['contract_end'] ?? '-');
            $cells[] = $this->detail($row['current_status_reason'] ?? '', 'سبب الحالة الوظيفية', 'fa-circle-info text-primary');
            $cells[] = $this->text($row['current_status_effective_date'] ?? '-');
            $cells[] = $this->text($row['first_hire_date'] ?? '-');
            $cells[] = $this->text($row['latest_hire_date'] ?? '-');
            $cells[] = $this->text($row['last_working_day'] ?? '-');
            $cells[] = $this->booleanBadge($row['can_rehire'] ?? null);
            $cells[] = $this->text($row['last_job_movement_date'] ?? '-');
            $cells[] = $this->countBadge((int) ($row['status_history_count'] ?? 0));
            $cells[] = $this->countBadge((int) ($row['job_movements_count'] ?? 0));
            $cells[] = $this->structuredDetail($row['extra_employment_data'] ?? '', 'بيانات وظيفية إضافية', 'fa-briefcase text-primary');
            $cells[] = $this->structuredDetail($row['other_qualifications'] ?? '', 'المؤهلات الأخرى', 'fa-graduation-cap text-primary');
            $cells[] = $this->structuredDetail($row['training_courses'] ?? '', 'الدورات والشهادات', 'fa-certificate text-primary');
            $cells[] = $this->structuredDetail($row['work_history'] ?? '', 'أماكن العمل السابقة', 'fa-building text-primary');
            $cells[] = $this->availabilityBadge($row['profile_image'] ?? null);
            $cells[] = $this->countBadge((int) ($row['attachment_count'] ?? 0));
            $onDuty = ($row['current_work_status'] ?? 'on_duty') !== 'off_duty';
            $cells[] = $onDuty ? '<span class="badge bg-success">على رأس العمل</span>' : '<span class="badge bg-secondary">ليس على رأس العمل</span>';
            $cells[] = '<a href="staff.php?action=edit&id=' . (int) $row['id'] . '" class="btn btn-action-pills btn-edit has-tooltip me-1" title="تعديل"><i class="fas fa-edit"></i></a><button type="button" class="btn btn-action-pills btn-delete delete-staff has-tooltip" data-id="' . (int) $row['id'] . '" data-name="' . $this->e($name) . '" title="حذف"><i class="fas fa-trash"></i></button>';
            $rows[] = $cells;
        }
        return $rows;
    }

    private function text($value, ?string $dir = null): string { $value = trim((string) $value) === '' ? '-' : (string) $value; return '<span' . ($dir ? ' dir="' . $dir . '"' : '') . '>' . $this->e($value) . '</span>'; }
    private function detail($value, string $title, string $icon): string { $value = trim((string) $value); return $value === '' ? '<span class="text-muted">-</span>' : '<button type="button" class="btn btn-sm btn-link p-0 view-cell-content" data-title="' . $this->e($title) . '" data-content="' . $this->e($value) . '" data-bs-toggle="tooltip" title="' . $this->e($title) . '"><i class="fas ' . $this->e($icon) . '"></i></button>'; }
    private function structuredDetail($value, string $title, string $icon): string
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') return '<span class="text-muted">-</span>';
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) return $this->detail($raw, $title, $icon);
        }
        if ($decoded === []) return '<span class="text-muted">-</span>';
        $formatted = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $this->detail($formatted === false ? '' : $formatted, $title, $icon);
    }
    private function currentAge($birthDate): string
    {
        $birthDate = trim((string) $birthDate);
        if ($birthDate === '') return '-';
        try {
            $birth = new \DateTimeImmutable($birthDate);
            $today = new \DateTimeImmutable('today');
        } catch (\Exception $exception) {
            return '-';
        }
        if ($birth > $today) return '-';
        return $birth->diff($today)->y . ' سنة';
    }
    private function booleanBadge($value): string
    {
        if ($value === null || $value === '') return '<span class="text-muted">-</span>';
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        return $enabled
            ? '<span class="badge bg-success">نعم</span>'
            : '<span class="badge bg-secondary">لا</span>';
    }
    private function availabilityBadge($value): string
    {
        return trim((string) $value) === ''
            ? '<span class="badge bg-light text-dark border">غير مرفقة</span>'
            : '<span class="badge bg-success">مرفقة</span>';
    }
    private function countBadge(int $count): string
    {
        return '<span class="badge bg-light text-dark border">' . $count . '</span>';
    }
    private function contract($value): string { $labels = ['permanent' => 'دائم', 'temporary' => 'مؤقت', 'parttime' => 'جزئي', 'other' => 'أخرى']; $value = (string) $value; $class = $value === 'permanent' ? 'success' : ($value === 'temporary' ? 'warning text-dark' : 'info'); return '<span class="badge bg-' . $class . '">' . $this->e($labels[$value] ?? ($value ?: '-')) . '</span>'; }
    private function label($value, array $labels): string { return $labels[$value] ?? ((string) $value ?: '-'); }
    private function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
