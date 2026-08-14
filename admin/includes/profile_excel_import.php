<?php

require_once __DIR__ . '/../../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/../../classes/StaffEmploymentLifecycleService.php';
/**
 * Safe, profile-only Excel import for students and staff.
 *
 * Account credentials, staff financial data, teaching assignments and files are
 * deliberately excluded: each has an authoritative administration page.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function profile_import_student_columns(): array
{
    return [
        'student_code', 'class_name', 'ministry_code',
        'first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar',
        'first_name_en', 'second_name_en', 'third_name_en', 'fourth_name_en', 'family_name_en',
        'birth_date', 'birth_place', 'national_id', 'nationality', 'passport_number', 'religion', 'gender',
        'city_area', 'address_current', 'phone_mobile', 'phone_home', 'phone_emergency',
        'enrollment_date', 'enrollment_status', 'previous_school', 'notes',
        'health_status', 'chronic_diseases', 'allergies', 'blood_type', 'disabilities', 'medications',
        'psychological_notes', 'previous_medical_reports', 'insurance_number', 'insurance_start_date',
        'insurance_end_date', 'treatment_plan', 'emergency_medical_notes',
    ];
}

function profile_import_staff_columns(): array
{
    return [
        'employee_code', 'biometric_id', 'ministry_code', 'full_name_ar', 'full_name_en',
        'national_id', 'passport_number', 'birth_date', 'birth_place', 'gender', 'religion', 'nationality',
        'address_detail', 'city_area', 'phone_mobile', 'phone_home', 'phone_emergency',
        'emergency_contact_name', 'email_personal', 'marital_status', 'military_status',
        'public_service_status', 'number_of_children', 'blood_type', 'hire_date', 'job_title',
        'department', 'job_grade', 'contract_type', 'contract_start', 'contract_end', 'admin_notes',
        'qualification', 'qualification_year', 'qualification_university', 'specialization',
        'other_qualifications', 'training_courses', 'years_of_experience', 'work_history',
        'insurance_number', 'insurance_start_date', 'insurance_end_date', 'treatment_plan',
        'health_issues', 'health_status', 'chronic_diseases', 'allergies', 'disabilities', 'medications',
        'previous_medical_reports', 'emergency_medical_notes', 'psychological_notes', 'notes',
    ];
}

function profile_import_template_sheets(string $kind): array
{
    if ($kind === 'student') {
        return [
            'الطلاب' => profile_import_student_columns(),
            'أولياء_الأمور' => [
                'student_code', 'guardian_name', 'national_id', 'birth_date', 'birth_place', 'passport_number',
                'nationality', 'religion', 'qualification', 'address', 'email', 'phone_primary',
                'phone_secondary', 'phone_landline', 'phone_emergency', 'relationship',
                'relationship_other', 'job_title', 'employer', 'work_phone', 'is_primary',
            ],
            'هواتف_إضافية' => ['student_code', 'phone_type', 'phone_number', 'note'],
        ];
    }

    return [
        'العاملون' => profile_import_staff_columns(),
        'هواتف_إضافية' => ['employee_code', 'phone_type', 'phone_number', 'note'],
        'بيانات_إضافية' => ['employee_code', 'section', 'label', 'value'],
        'الحالات_الوظيفية' => [
            'employee_code', 'movement_type', 'status_after', 'status_label', 'status_reason', 'effective_date',
            'decision_date', 'decision_no', 'issuer', 'contract_type', 'contract_start', 'contract_end',
            'job_title', 'job_grade', 'department', 'last_working_day', 'can_rehire', 'notes',
        ],
        'الحركات_الوظيفية' => [
            'employee_code', 'movement_type', 'previous_job_title', 'new_job_title', 'previous_job_grade',
            'new_job_grade', 'previous_department', 'new_department', 'previous_contract_type',
            'new_contract_type', 'decision_date', 'effective_date', 'decision_no', 'issuer', 'reason', 'notes',
        ],
    ];
}

function profile_import_download_template(string $kind): void
{
    if (!in_array($kind, ['student', 'staff'], true)) {
        throw new InvalidArgumentException('نوع النموذج غير صالح.');
    }

    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    foreach (profile_import_template_sheets($kind) as $title => $headers) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->setRightToLeft(true);
        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF315BEA');
        $sheet->getStyle('A1:' . $lastColumn . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . '1');
        for ($index = 1; $index <= count($headers); $index++) {
            $sheet->getColumnDimensionByColumn($index)->setWidth(20);
        }
    }

    $spreadsheet->setActiveSheetIndex(0);
    $fileName = $kind === 'student' ? 'student_profile_import_template.xlsx' : 'staff_profile_import_template.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0, no-store');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
    exit();
}

function profile_import_normalize_header(string $value): string
{
    return strtolower(trim(preg_replace('/[\s\-]+/', '_', $value) ?? ''));
}

function profile_import_value(array $row, string $key): string
{
    return trim((string) ($row[$key] ?? ''));
}

function profile_import_rows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
{
    $values = $sheet->toArray('', true, true, false);
    if (empty($values)) {
        return [];
    }
    $headers = [];
    foreach (($values[0] ?? []) as $column => $header) {
        $normalized = profile_import_normalize_header((string) $header);
        if ($normalized === '') {
            continue;
        }
        if (isset($headers[$normalized])) {
            throw new RuntimeException('يوجد عنوان عمود مكرر في ورقة «' . $sheet->getTitle() . '».');
        }
        $headers[$normalized] = $column;
    }
    $rows = [];
    foreach (array_slice($values, 1, null, true) as $offset => $raw) {
        $row = ['_row_number' => $offset + 1];
        $hasValue = false;
        foreach ($headers as $header => $column) {
            $value = trim((string) ($raw[$column] ?? ''));
            $row[$header] = $value;
            $hasValue = $hasValue || $value !== '';
        }
        if ($hasValue) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function profile_import_load_sheets(array $file, array $requiredSheets): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('حدث خطأ أثناء رفع ملف Excel.');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('الحد الأقصى لحجم ملف الاستيراد هو 10 ميجابايت.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        throw new RuntimeException('يجب أن يكون الملف بصيغة Excel (.xlsx أو .xls).');
    }

    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheets = [];
    foreach ($requiredSheets as $sheetName) {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet === null) {
            throw new RuntimeException('الملف لا يحتوي على الورقة المطلوبة «' . $sheetName . '». حمّل النموذج الفارغ واستخدمه كما هو.');
        }
        $sheets[$sheetName] = profile_import_rows($sheet);
    }
    return $sheets;
}

function profile_import_add_error(array &$errors, string $message): void
{
    if (count($errors) < 50) {
        $errors[] = $message;
    }
}

function profile_import_date(string $value, string $label, int $rowNumber, array &$errors, bool $allowFuture = true): ?string
{
    if ($value === '') {
        return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = \DateTimeImmutable::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $value) {
        profile_import_add_error($errors, 'السطر ' . $rowNumber . ': ' . $label . ' يجب أن يكون بصيغة YYYY-MM-DD.');
        return null;
    }
    if (!$allowFuture && $date > new \DateTimeImmutable('today')) {
        profile_import_add_error($errors, 'السطر ' . $rowNumber . ': ' . $label . ' لا يمكن أن يكون في المستقبل.');
    }
    return $value;
}

function profile_import_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function profile_import_validate_mobile(string $value, string $label, int $rowNumber, array &$errors): string
{
    $digits = profile_import_digits($value);
    if ($value !== '' && !preg_match('/^01\d{9}$/', $digits)) {
        profile_import_add_error($errors, 'السطر ' . $rowNumber . ': ' . $label . ' يجب أن يكون رقم موبايل مصرياً من 11 رقماً.');
    }
    return $digits;
}

function profile_import_validate_national_id(string $value, string $label, int $rowNumber, array &$errors): string
{
    $digits = profile_import_digits($value);
    if ($value !== '' && !preg_match('/^\d{14}$/', $digits)) {
        profile_import_add_error($errors, 'السطر ' . $rowNumber . ': ' . $label . ' يجب أن يكون 14 رقماً.');
    }
    return $digits;
}

function profile_import_unique_values(PDO $db, string $table, string $column): array
{
    $statement = $db->query("SELECT `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''");
    $values = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
        $values[(string) $value] = true;
    }
    return $values;
}

function profile_import_student(array $file, PDO $db, User $user, string $scope): array
{
    if ($scope !== 'current') {
        throw new RuntimeException('يسمح بالاستيراد إلى قائمة الطلاب المقيدين فقط.');
    }
    $sheets = profile_import_load_sheets($file, ['الطلاب', 'أولياء_الأمور', 'هواتف_إضافية']);
    if (empty($sheets['الطلاب'])) {
        throw new RuntimeException('ورقة الطلاب لا تحتوي على صفوف بيانات.');
    }

    $errors = [];
    $classMap = [];
    foreach ($db->query('SELECT id, name, grade_id FROM classes')->fetchAll(PDO::FETCH_ASSOC) as $classRow) {
        $classMap[trim((string) $classRow['name'])] = $classRow;
    }
    $existingCodes = profile_import_unique_values($db, 'student_profiles', 'student_code');
    $existingNationalIds = profile_import_unique_values($db, 'student_profiles', 'national_id');
    $seenCodes = [];
    $seenNationalIds = [];
    $students = [];
    $dateFields = ['birth_date' => false, 'enrollment_date' => true, 'insurance_start_date' => true, 'insurance_end_date' => true];

    foreach ($sheets['الطلاب'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'student_code');
        $className = profile_import_value($row, 'class_name');
        $firstName = profile_import_value($row, 'first_name_ar');
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', $code)) {
            profile_import_add_error($errors, 'السطر ' . $line . ': كود الطالب مطلوب ويحتوي حروفاً أو أرقاماً أو - أو _ فقط.');
        }
        if ($firstName === '') {
            profile_import_add_error($errors, 'السطر ' . $line . ': الاسم الأول بالعربية مطلوب.');
        }
        if (!isset($classMap[$className])) {
            profile_import_add_error($errors, 'السطر ' . $line . ': الفصل «' . $className . '» غير موجود في النظام.');
        }
        if (isset($seenCodes[$code]) || isset($existingCodes[$code])) {
            profile_import_add_error($errors, 'السطر ' . $line . ': كود الطالب «' . $code . '» مستخدم بالفعل أو مكرر داخل الملف.');
        }
        $seenCodes[$code] = true;
        $nationalId = profile_import_validate_national_id(profile_import_value($row, 'national_id'), 'الرقم القومي للطالب', $line, $errors);
        if ($nationalId !== '' && (isset($seenNationalIds[$nationalId]) || isset($existingNationalIds[$nationalId]))) {
            profile_import_add_error($errors, 'السطر ' . $line . ': الرقم القومي للطالب مكرر.');
        }
        if ($nationalId !== '') {
            $seenNationalIds[$nationalId] = true;
        }
        $data = [];
        foreach (profile_import_student_columns() as $field) {
            if (!in_array($field, ['student_code', 'class_name'], true)) {
                $data[$field] = profile_import_value($row, $field);
            }
        }
        $data['student_code'] = $code;
        $data['national_id'] = $nationalId;
        foreach ($dateFields as $field => $allowFuture) {
            $data[$field] = profile_import_date($data[$field], $field, $line, $errors, $allowFuture) ?? '';
        }
        foreach (['phone_mobile' => 'رقم موبايل الطالب', 'phone_emergency' => 'رقم الطوارئ'] as $field => $label) {
            $data[$field] = profile_import_validate_mobile($data[$field], $label, $line, $errors);
        }
        $data['grade_id'] = $classMap[$className]['grade_id'] ?? null;
        $data['enrollment_status'] = $data['enrollment_status'] === '' ? 'enrolled' : $data['enrollment_status'];
        if ($data['enrollment_status'] !== 'enrolled') {
            profile_import_add_error($errors, 'السطر ' . $line . ': حالة قيد الطالب في الاستيراد الحالي يجب أن تكون enrolled.');
        }
        $students[$code] = ['line' => $line, 'class' => $classMap[$className] ?? null, 'data' => $data];
    }

    $guardians = [];
    $primaryCount = [];
    foreach ($sheets['أولياء_الأمور'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'student_code');
        if (!isset($students[$code])) {
            profile_import_add_error($errors, 'ورقة أولياء الأمور، السطر ' . $line . ': كود الطالب غير موجود في ورقة الطلاب.');
            continue;
        }
        $name = profile_import_value($row, 'guardian_name');
        if ($name === '') {
            profile_import_add_error($errors, 'ورقة أولياء الأمور، السطر ' . $line . ': اسم ولي الأمر مطلوب.');
            continue;
        }
        $guardian = [];
        foreach (array_slice(profile_import_template_sheets('student')['أولياء_الأمور'], 1) as $field) {
            $guardian[$field] = profile_import_value($row, $field);
        }
        $guardian['national_id'] = profile_import_validate_national_id($guardian['national_id'], 'الرقم القومي لولي الأمر', $line, $errors);
        $guardian['birth_date'] = profile_import_date($guardian['birth_date'], 'تاريخ ميلاد ولي الأمر', $line, $errors, false) ?? '';
        $guardian['phone_primary'] = profile_import_validate_mobile($guardian['phone_primary'], 'موبايل ولي الأمر', $line, $errors);
        $guardian['phone_secondary'] = profile_import_validate_mobile($guardian['phone_secondary'], 'الموبايل الإضافي لولي الأمر', $line, $errors);
        $guardian['phone_emergency'] = profile_import_validate_mobile($guardian['phone_emergency'], 'هاتف طوارئ ولي الأمر', $line, $errors);
        $guardian['is_primary'] = in_array(strtolower($guardian['is_primary']), ['1', 'yes', 'true', 'نعم'], true) ? 1 : 0;
        if ($guardian['is_primary']) {
            $primaryCount[$code] = ($primaryCount[$code] ?? 0) + 1;
        }
        $guardians[$code][] = $guardian;
    }
    foreach ($primaryCount as $code => $count) {
        if ($count > 1) {
            profile_import_add_error($errors, 'للطالب «' . $code . '» أكثر من ولي أمر أساسي واحد.');
        }
    }

    $phones = [];
    foreach ($sheets['هواتف_إضافية'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'student_code');
        $type = strtolower(profile_import_value($row, 'phone_type'));
        $number = profile_import_value($row, 'phone_number');
        if (!isset($students[$code])) {
            profile_import_add_error($errors, 'ورقة الهواتف الإضافية، السطر ' . $line . ': كود الطالب غير موجود في ورقة الطلاب.');
            continue;
        }
        if (!in_array($type, ['mobile', 'landline'], true) || $number === '') {
            profile_import_add_error($errors, 'ورقة الهواتف الإضافية، السطر ' . $line . ': نوع الهاتف mobile أو landline والرقم مطلوبان.');
            continue;
        }
        if ($type === 'mobile') {
            $number = profile_import_validate_mobile($number, 'الموبايل الإضافي', $line, $errors);
        }
        $phones[$code][] = ['type' => $type, 'number' => $number, 'note' => profile_import_value($row, 'note')];
    }

    if (!empty($errors)) {
        throw new RuntimeException("لم يتم حفظ أي بيانات.\n" . implode("\n", $errors));
    }

    $db->beginTransaction();
    try {
        $createdUserIds = [];
        foreach ($students as $code => $student) {
            $data = $student['data'];
            $nameParts = [];
            foreach (['first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'] as $field) {
                if ($data[$field] !== '') {
                    $nameParts[] = $data[$field];
                }
            }
            $user->name = implode(' ', $nameParts);
            $user->username = null;
            $user->password = null;
            $user->role = 'student';
            $user->class_id = (int) $student['class']['id'];
            $user->status = 'active';
            if (!$user->create() || !$user->saveStudentProfile((int) $user->id, $data)) {
                throw new RuntimeException('تعذر إنشاء ملف الطالب «' . $code . '».');
            }
            $createdUserIds[] = (int)$user->id;
            if (!empty($phones[$code])) {
                if (!$user->saveStudentProfile((int) $user->id, ['extra_phones' => json_encode($phones[$code], JSON_UNESCAPED_UNICODE)])) {
                    throw new RuntimeException('تعذر حفظ هواتف الطالب «' . $code . '».');
                }
            }
            foreach ($guardians[$code] ?? [] as $guardian) {
                $guardian['student_id'] = (int) $user->id;
                if (!$user->saveStudentGuardian($guardian)) {
                    throw new RuntimeException('تعذر حفظ بيانات ولي أمر الطالب «' . $code . '».');
                }
            }
        }
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'import', 'student_profile_batch', null, 'استيراد ملفات الطلاب من Excel',
            [
                'count' => count($students),
                'created_user_ids' => $createdUserIds,
                'source_keys' => array_keys($students),
                'related_rows' => count($guardians) + count($phones),
                'undo_policy' => 'bulk_profile_import_restore_not_enabled',
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['count' => count($students), 'children' => count($guardians) + count($phones)];
}

function profile_import_staff(array $file, PDO $db, User $user): array
{
    $sheets = profile_import_load_sheets($file, ['العاملون', 'هواتف_إضافية', 'بيانات_إضافية', 'الحالات_الوظيفية', 'الحركات_الوظيفية']);
    if (empty($sheets['العاملون'])) {
        throw new RuntimeException('ورقة العاملون لا تحتوي على صفوف بيانات.');
    }
    $errors = [];
    $existingCodes = profile_import_unique_values($db, 'staff_profiles', 'employee_code');
    $existingNationalIds = profile_import_unique_values($db, 'staff_profiles', 'national_id');
    $seenCodes = [];
    $seenNationalIds = [];
    $staff = [];
    $dateFields = ['birth_date' => false, 'hire_date' => true, 'contract_start' => true, 'contract_end' => true, 'insurance_start_date' => true, 'insurance_end_date' => true];
    foreach ($sheets['العاملون'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'employee_code');
        $name = profile_import_value($row, 'full_name_ar');
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', $code)) {
            profile_import_add_error($errors, 'السطر ' . $line . ': كود الموظف مطلوب ويحتوي حروفاً أو أرقاماً أو - أو _ فقط.');
        }
        if ($name === '') {
            profile_import_add_error($errors, 'السطر ' . $line . ': الاسم الكامل بالعربية مطلوب.');
        }
        if (isset($seenCodes[$code]) || isset($existingCodes[$code])) {
            profile_import_add_error($errors, 'السطر ' . $line . ': كود الموظف «' . $code . '» مستخدم بالفعل أو مكرر داخل الملف.');
        }
        $seenCodes[$code] = true;
        $data = [];
        foreach (profile_import_staff_columns() as $field) {
            $data[$field] = profile_import_value($row, $field);
        }
        $data['national_id'] = profile_import_validate_national_id($data['national_id'], 'الرقم القومي للموظف', $line, $errors);
        if ($data['national_id'] !== '' && (isset($seenNationalIds[$data['national_id']]) || isset($existingNationalIds[$data['national_id']]))) {
            profile_import_add_error($errors, 'السطر ' . $line . ': الرقم القومي للموظف مكرر.');
        }
        if ($data['national_id'] !== '') {
            $seenNationalIds[$data['national_id']] = true;
        }
        foreach ($dateFields as $field => $allowFuture) {
            $data[$field] = profile_import_date($data[$field], $field, $line, $errors, $allowFuture) ?? '';
        }
        foreach (['phone_mobile' => 'رقم موبايل الموظف', 'phone_emergency' => 'رقم الطوارئ'] as $field => $label) {
            $data[$field] = profile_import_validate_mobile($data[$field], $label, $line, $errors);
        }
        $data['number_of_children'] = $data['number_of_children'] === '' ? 0 : $data['number_of_children'];
        $staff[$code] = ['line' => $line, 'data' => $data];
    }

    $phones = [];
    foreach ($sheets['هواتف_إضافية'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'employee_code');
        $type = strtolower(profile_import_value($row, 'phone_type'));
        $number = profile_import_value($row, 'phone_number');
        if (!isset($staff[$code])) {
            profile_import_add_error($errors, 'ورقة الهواتف الإضافية، السطر ' . $line . ': كود الموظف غير موجود في ورقة العاملون.');
            continue;
        }
        if (!in_array($type, ['mobile', 'landline'], true) || $number === '') {
            profile_import_add_error($errors, 'ورقة الهواتف الإضافية، السطر ' . $line . ': نوع الهاتف mobile أو landline والرقم مطلوبان.');
            continue;
        }
        if ($type === 'mobile') {
            $number = profile_import_validate_mobile($number, 'الموبايل الإضافي', $line, $errors);
        }
        $phones[$code][] = ['type' => $type, 'number' => $number, 'note' => profile_import_value($row, 'note')];
    }

    $extraData = [];
    foreach ($sheets['بيانات_إضافية'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'employee_code');
        $section = strtolower(profile_import_value($row, 'section'));
        $label = profile_import_value($row, 'label');
        if (!isset($staff[$code]) || !in_array($section, ['general', 'employment'], true) || $label === '') {
            profile_import_add_error($errors, 'ورقة البيانات الإضافية، السطر ' . $line . ': كود موظف صالح وsection (general أو employment) وlabel مطلوبة.');
            continue;
        }
        $extraData[$code][$section][] = ['label' => $label, 'value' => profile_import_value($row, 'value')];
    }

    $statusEvents = [];
    foreach ($sheets['الحالات_الوظيفية'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'employee_code');
        $movement = profile_import_value($row, 'movement_type');
        $statusAfter = strtolower(profile_import_value($row, 'status_after'));
        if (!isset($staff[$code]) || $movement === '' || !in_array($statusAfter, ['on_duty', 'off_duty'], true)) {
            profile_import_add_error($errors, 'ورقة الحالات الوظيفية، السطر ' . $line . ': كود موظف ونوع حركة وحالة on_duty أو off_duty مطلوبة.');
            continue;
        }
        $event = [];
        foreach (array_slice(profile_import_template_sheets('staff')['الحالات_الوظيفية'], 1) as $field) {
            $event[$field] = profile_import_value($row, $field);
        }
        foreach (['effective_date', 'decision_date', 'contract_start', 'contract_end', 'last_working_day'] as $field) {
            $event[$field] = profile_import_date($event[$field], $field, $line, $errors) ?? null;
        }
        if ($event['effective_date'] === null) {
            profile_import_add_error($errors, 'ورقة الحالات الوظيفية، السطر ' . $line . ': تاريخ السريان مطلوب.');
        }
        $canRehireValue = strtolower(trim((string) $event['can_rehire']));
        $event['can_rehire'] = $canRehireValue === ''
            ? null
            : (in_array($canRehireValue, ['1', 'yes', 'true', 'نعم'], true) ? 1 : 0);
        $statusEvents[$code][] = $event;
    }

    $movements = [];
    foreach ($sheets['الحركات_الوظيفية'] as $row) {
        $line = (int) $row['_row_number'];
        $code = profile_import_value($row, 'employee_code');
        $movement = profile_import_value($row, 'movement_type');
        if (!isset($staff[$code]) || $movement === '') {
            profile_import_add_error($errors, 'ورقة الحركات الوظيفية، السطر ' . $line . ': كود موظف ونوع حركة مطلوبان.');
            continue;
        }
        $event = [];
        foreach (array_slice(profile_import_template_sheets('staff')['الحركات_الوظيفية'], 1) as $field) {
            $event[$field] = profile_import_value($row, $field);
        }
        foreach (['decision_date', 'effective_date'] as $field) {
            $event[$field] = profile_import_date($event[$field], $field, $line, $errors) ?? null;
        }
        $movements[$code][] = $event;
    }

    if (!empty($errors)) {
        throw new RuntimeException("لم يتم حفظ أي بيانات.\n" . implode("\n", $errors));
    }

    $db->beginTransaction();
    try {
        $createdUserIds = [];
        foreach ($staff as $code => $staffMember) {
            $data = $staffMember['data'];
            $data['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                $data['job_title'] ?? null
            );
            $data['extra_phones'] = empty($phones[$code]) ? null : json_encode($phones[$code], JSON_UNESCAPED_UNICODE);
            $data['extra_data'] = empty($extraData[$code]['general']) ? null : json_encode($extraData[$code]['general'], JSON_UNESCAPED_UNICODE);
            $data['extra_employment_data'] = empty($extraData[$code]['employment']) ? null : json_encode($extraData[$code]['employment'], JSON_UNESCAPED_UNICODE);
            $events = $statusEvents[$code] ?? [[
                'movement_type' => 'تعيين', 'status_after' => 'on_duty', 'status_label' => 'على رأس العمل',
                'status_reason' => 'استيراد ملف وظيفي', 'effective_date' => $data['hire_date'] ?: date('Y-m-d'),
                'decision_date' => null, 'decision_no' => null, 'issuer' => null, 'contract_type' => $data['contract_type'],
                'contract_start' => $data['contract_start'] ?: null, 'contract_end' => $data['contract_end'] ?: null,
                'job_title' => $data['job_title'], 'job_grade' => $data['job_grade'], 'department' => $data['department'],
                'last_working_day' => null, 'can_rehire' => null, 'notes' => null,
            ]];
            foreach ($events as &$event) {
                $event['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                    $event['job_title'] ?? null
                );
            }
            unset($event);
            if (!empty($movements[$code])) {
                foreach ($movements[$code] as &$movement) {
                    $movement['previous_job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                        $movement['previous_job_title'] ?? null
                    );
                    $movement['new_job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                        $movement['new_job_title'] ?? null
                    );
                }
                unset($movement);
            }
            usort($events, static function ($a, $b) { return strcmp((string) $a['effective_date'], (string) $b['effective_date']); });
            $current = end($events);
            $data['current_work_status'] = $current['status_after'];
            $data['current_status_reason'] = $current['status_reason'] ?: $current['status_label'];
            $data['current_status_effective_date'] = $current['effective_date'];
            $onDutyDates = [];
            foreach ($events as $event) {
                if ($event['status_after'] === 'on_duty' && !empty($event['effective_date'])) {
                    $onDutyDates[] = $event['effective_date'];
                }
            }
            $data['first_hire_date'] = $data['hire_date'] ?: ($onDutyDates[0] ?? $events[0]['effective_date']);
            $data['latest_hire_date'] = !empty($onDutyDates) ? end($onDutyDates) : $data['first_hire_date'];
            $data['last_working_day'] = $current['status_after'] === 'off_duty' ? $current['last_working_day'] : null;
            $data['can_rehire'] = $current['can_rehire'];
            $data['status_history'] = json_encode($events, JSON_UNESCAPED_UNICODE);
            $data['promotions'] = json_encode($movements[$code] ?? [], JSON_UNESCAPED_UNICODE);

            $insert = $db->prepare("INSERT INTO users (name, username, password, role, class_id, status) VALUES (?, NULL, NULL, NULL, NULL, 'active')");
            if (!$insert->execute([$data['full_name_ar']])) {
                throw new RuntimeException('تعذر إنشاء ملف الموظف «' . $code . '».');
            }
            $userId = (int) $db->lastInsertId();
            $createdUserIds[] = $userId;
            if (!$user->saveStaffProfile($userId, $data)) {
                throw new RuntimeException('تعذر حفظ ملف الموظف «' . $code . '».');
            }
            $statusInsert = $db->prepare('INSERT INTO staff_status_history (user_id, movement_type, status_after, status_label, status_reason, effective_date, decision_date, decision_no, issuer, contract_type, contract_start, contract_end, job_title, job_grade, department, last_working_day, can_rehire, notes, source, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'excel_import\', ?)');
            foreach ($events as $event) {
                $statusInsert->execute([$userId, $event['movement_type'], $event['status_after'], $event['status_label'], $event['status_reason'], $event['effective_date'], $event['decision_date'], $event['decision_no'], $event['issuer'], $event['contract_type'], $event['contract_start'], $event['contract_end'], $event['job_title'], $event['job_grade'], $event['department'], $event['last_working_day'], $event['can_rehire'], $event['notes'], $_SESSION['user_id'] ?? null]);
            }
            if (!empty($movements[$code])) {
                $movementInsert = $db->prepare('INSERT INTO staff_job_movements (user_id, movement_type, previous_job_title, new_job_title, previous_job_grade, new_job_grade, previous_department, new_department, previous_contract_type, new_contract_type, decision_date, effective_date, decision_no, issuer, reason, notes, source, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'excel_import\', ?)');
                foreach ($movements[$code] as $movement) {
                    $movementInsert->execute([$userId, $movement['movement_type'], $movement['previous_job_title'], $movement['new_job_title'], $movement['previous_job_grade'], $movement['new_job_grade'], $movement['previous_department'], $movement['new_department'], $movement['previous_contract_type'], $movement['new_contract_type'], $movement['decision_date'], $movement['effective_date'], $movement['decision_no'], $movement['issuer'], $movement['reason'], $movement['notes'], $_SESSION['user_id'] ?? null]);
                }
            }
        }
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'import', 'staff_profile_batch', null, 'استيراد ملفات العاملين من Excel',
            [
                'count' => count($staff),
                'created_user_ids' => $createdUserIds,
                'source_keys' => array_keys($staff),
                'related_rows' => count($phones) + count($extraData) + count($statusEvents) + count($movements),
                'undo_policy' => 'bulk_profile_import_restore_not_enabled',
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['count' => count($staff), 'children' => count($phones) + count($extraData) + count($statusEvents) + count($movements)];
}
