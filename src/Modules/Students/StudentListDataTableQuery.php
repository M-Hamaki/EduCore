<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use EduCore\Modules\Students\Presentation\StudentListColumnCatalog;
use EduCore\Modules\Students\Presentation\StudentListDataTablePresenter;

final class StudentListDataTableQuery
{
    private const COLUMN_FIELDS = [
        'col-birth-date' => ['birth_date'], 'col-current-age' => ['birth_date'],
        'col-gender' => ['gender'], 'col-religion' => ['religion'], 'col-city-area' => ['city_area'],
        'col-phone-emergency' => ['phone_emergency'], 'col-enrollment-date' => ['enrollment_date'],
        'col-passport' => ['passport_number'], 'col-nationality' => ['nationality'],
        'col-birth-place' => ['birth_place'], 'col-ministry-code' => ['ministry_code'],
        'col-previous-school' => ['previous_school'],
        'col-name-en' => ['first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en'],
        'col-age-october' => ['age_years', 'age_months', 'age_days'],
        'col-guardianship' => ['extra_data'], 'col-notes' => ['notes'],
        'col-phone-mobile' => ['phone_mobile'], 'col-phone-home' => ['phone_home'],
        'col-address' => ['address_current'], 'col-blood-type' => ['blood_type'],
        'col-insurance-number' => ['insurance_number'], 'col-insurance-start' => ['insurance_start_date'],
        'col-insurance-end' => ['insurance_end_date'], 'col-health-status' => ['health_status'],
        'col-chronic' => ['chronic_diseases'], 'col-allergies' => ['allergies'],
        'col-disabilities' => ['disabilities'], 'col-medications' => ['medications'],
        'col-treatment' => ['treatment_plan'], 'col-medical-reports' => ['previous_medical_reports'],
        'col-emergency-notes' => ['emergency_medical_notes'], 'col-psychological' => ['psychological_notes'],
        'col-father-name' => ['father_name'], 'col-father-mobile' => ['father_mobile'],
        'col-father-landline' => ['father_landline'], 'col-father-email' => ['father_email'],
        'col-father-address' => ['father_address'], 'col-father-national-id' => ['father_national_id'],
        'col-father-qualification' => ['father_qualification'], 'col-father-job' => ['father_job'],
        'col-father-employer' => ['father_employer'], 'col-father-work-phone' => ['father_work_phone'],
        'col-father-birth-date' => ['father_birth_date'], 'col-father-religion' => ['father_religion'],
        'col-father-nationality' => ['father_nationality'], 'col-father-passport' => ['father_passport'],
        'col-mother-name' => ['mother_name'], 'col-mother-mobile' => ['mother_mobile'],
        'col-mother-landline' => ['mother_landline'], 'col-mother-email' => ['mother_email'],
        'col-mother-address' => ['mother_address'], 'col-mother-national-id' => ['mother_national_id'],
        'col-mother-qualification' => ['mother_qualification'], 'col-mother-job' => ['mother_job'],
        'col-mother-employer' => ['mother_employer'], 'col-mother-work-phone' => ['mother_work_phone'],
        'col-mother-birth-date' => ['mother_birth_date'], 'col-mother-religion' => ['mother_religion'],
        'col-mother-nationality' => ['mother_nationality'], 'col-mother-passport' => ['mother_passport'],
        'col-siblings' => ['siblings_count', 'siblings_info'],
        'col-profile-image' => ['profile_image_id'],
    ];

    public function __construct(
        private StudentListReadRepository $students,
        private StudentListDataTablePresenter $presenter
    ) {}

    public function load(
        array $request,
        string $scope,
        string $basePage,
        ?array $allowedClassIds = null,
        bool $canArchive = true
    ): array
    {
        $draw = max(0, (int) ($request['draw'] ?? 0));
        $requestedLength = (int) ($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : min(500, max(10, $requestedLength));
        $start = max(0, (int) ($request['start'] ?? 0));
        $ids = static fn(string $name): array => isset($request[$name]) && is_array($request[$name])
            ? array_values(array_filter(array_map('intval', $request[$name])))
            : [];
        $classes = $ids('class_ids');
        $grades = $ids('grade_ids');
        $stages = $ids('stage_ids');
        $search = trim((string) ($request['search']['value'] ?? ''));
        $visibleColumns = isset($request['visible_columns']) && is_array($request['visible_columns'])
            ? array_values(array_filter(array_map('strval', $request['visible_columns']), static fn(string $column): bool => preg_match('/^col-[a-z0-9-]+$/', $column) === 1))
            : [];
        $column = (int) ($request['order'][0]['column'] ?? 3);
        $direction = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($allowedClassIds !== null) {
            $allowedClassIds = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds))));
            $classes = $classes === []
                ? []
                : array_values(array_intersect($classes, $allowedClassIds));
        }

        $total = 0;
        $students = $this->students->fetch(
            $classes ?: null,
            $allowedClassIds,
            $length,
            $start,
            $total,
            $grades ?: null,
            $stages ?: null,
            $scope,
            null,
            $search,
            $this->order($column, $scope),
            $direction,
            $this->selectedFields($visibleColumns)
        );
        $unfiltered = $total;
        if ($search !== '') {
            $unused = 0;
            $this->students->fetch($classes ?: null, $allowedClassIds, 1, 0, $unused, $grades ?: null, $stages ?: null, $scope, null, null, 'name', 'asc', []);
            $unfiltered = $unused;
        }
        $back = '&' . http_build_query(['student_scope' => $scope, 'stage_ids' => $stages, 'grade_ids' => $grades, 'class_ids' => $classes]);

        return [
            'draw' => $draw,
            'recordsTotal' => $unfiltered,
            'recordsFiltered' => $total,
            'data' => $this->presenter->rows($students, $start, $basePage, $back, $scope, $visibleColumns, $canArchive),
        ];
    }

    private function selectedFields(array $visibleColumns): array
    {
        $fields = [];
        foreach (array_unique($visibleColumns) as $column) {
            if (isset(self::COLUMN_FIELDS[$column])) {
                array_push($fields, ...self::COLUMN_FIELDS[$column]);
            }
        }
        array_push(
            $fields,
            ...StudentListColumnCatalog::queryFieldsForClasses(
                array_intersect($visibleColumns, StudentListColumnCatalog::additionalClasses())
            )
        );
        return array_values(array_unique($fields));
    }

    private function order(int $column, string $scope): string
    {
        $map = [0 => 'id', 1 => 'student_code', 2 => 'national_id', 3 => 'name', 4 => 'class_name', 5 => 'birth_date', 6 => 'birth_date', 7 => 'gender', 8 => 'religion', 9 => 'city_area', 10 => 'phone_emergency', 11 => 'enrollment_date'];
        if ($scope === 'transferred') {
            $map[65] = 'transfer_destination';
            $map[66] = 'external_transfer_date';
        }
        return $map[$column] ?? 'name';
    }
}
