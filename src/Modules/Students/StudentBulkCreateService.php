<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;

final class StudentBulkCreateService
{
    private PDO $db;
    private StudentEnrollmentService $enrollmentService;

    public function __construct(PDO $db, StudentEnrollmentService $enrollmentService)
    {
        $this->db = $db;
        $this->enrollmentService = $enrollmentService;
    }

    public function create(array $bulkInput, int $defaultClassId, string $scope): array
    {
        if ($scope !== 'current') {
            throw new InvalidArgumentException('الإضافة الجماعية اليدوية متاحة للطلاب المقيدين فقط.');
        }

        $rows = $this->submittedRows($bulkInput);
        if (count($rows) < 2) {
            throw new InvalidArgumentException('أدخل بيانات طالبين على الأقل للإضافة الجماعية.');
        }
        if (count($rows) > 20) {
            throw new InvalidArgumentException('الحد الأقصى للإضافة الجماعية اليدوية هو 20 طالبًا. استخدم استيراد Excel للأعداد الأكبر.');
        }

        $validatedRows = $this->validateRows($rows, $defaultClassId);
        $createdStudents = [];
        $currentEnrollmentYearId = AcademicYear::currentId($this->db);

        $this->db->beginTransaction();
        try {
            foreach ($validatedRows as $row) {
                $user = new User($this->db);
                $user->name = $row['name'];
                $user->role = 'student';
                $user->class_id = $row['class_id'];
                if (!$user->create()) {
                    throw new RuntimeException('تعذر إنشاء الطالب ' . $row['name'] . '.');
                }

                $nameParts = StudentProfilePayload::splitBulkName($row['name']);
                $profileData = array_merge($nameParts, [
                    'student_code' => $user->generateStudentCode(),
                    'grade_id' => $row['grade_id'],
                    'national_id' => $row['national_id'],
                    'gender' => $row['gender'],
                    'phone_mobile' => $row['phone_mobile'],
                    'enrollment_date' => $row['enrollment_date'] !== '' ? $row['enrollment_date'] : date('Y-m-d'),
                    'enrollment_status' => 'enrolled',
                    'search_key_ar' => User::buildSearchKey(
                        $nameParts['first_name_ar'],
                        $nameParts['second_name_ar'],
                        $nameParts['third_name_ar'],
                        $nameParts['fourth_name_ar'],
                        $nameParts['family_name_ar']
                    ),
                ]);
                if (!$user->saveStudentProfile((int) $user->id, $profileData)) {
                    throw new RuntimeException('تعذر حفظ ملف الطالب ' . $row['name'] . '.');
                }

                $this->enrollmentService->syncEnrollmentStatus(
                    (int) $user->id,
                    $currentEnrollmentYearId,
                    $row['class_id'],
                    'enrolled'
                );

                $createdStudents[] = [
                    'id' => (int) $user->id,
                    'name' => $row['name'],
                    'class_id' => $row['class_id'],
                    'class_name' => $row['class_name'],
                    'student_code' => $profileData['student_code'],
                ];

                ActivityLog::logCreate('student', (int) $user->id, $row['name'], [
                    'summary' => 'تم إنشاء طالب ضمن إضافة جماعية يدوية',
                    'student_code' => $profileData['student_code'],
                    'class' => $row['class_name'],
                ]);
                UndoManager::logInsert(
                    'users',
                    (int) $user->id,
                    ['name' => $row['name'], 'class_id' => $row['class_id']],
                    'إضافة جماعية لطالب: ' . $row['name']
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $createdStudents;
    }

    private function submittedRows(array $bulkInput): array
    {
        $rows = [];
        foreach ($bulkInput as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [
                trim((string) ($row['name'] ?? '')),
                trim((string) ($row['class_id'] ?? '')),
                trim((string) ($row['national_id'] ?? '')),
                trim((string) ($row['gender'] ?? '')),
                trim((string) ($row['phone_mobile'] ?? '')),
            ];
            if (implode('', $values) === '') {
                continue;
            }
            $row['_source_index'] = (int) $rowIndex;
            $rows[] = $row;
        }
        return $rows;
    }

    private function validateRows(array $rows, int $defaultClassId): array
    {
        $classStmt = $this->db->prepare('SELECT c.id, c.name, c.grade_id FROM classes c WHERE c.id = ? LIMIT 1');
        $nationalIdStmt = $this->db->prepare("SELECT u.name FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE sp.national_id = ? AND sp.national_id <> '' LIMIT 1");
        $seenNationalIds = [];
        $validatedRows = [];

        foreach ($rows as $position => $row) {
            $rowNumber = $position + 1;
            $name = trim((string) ($row['name'] ?? ''));
            $classId = (int) ($row['class_id'] ?? 0);
            $classId = $classId > 0 ? $classId : $defaultClassId;
            $nationalId = trim((string) ($row['national_id'] ?? ''));
            $gender = trim((string) ($row['gender'] ?? ''));
            $phoneMobile = trim((string) ($row['phone_mobile'] ?? ''));
            $enrollmentDate = trim((string) ($row['enrollment_date'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException("الصف {$rowNumber}: اسم الطالب مطلوب.");
            }
            if ($classId <= 0) {
                throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): اختيار الفصل مطلوب.");
            }

            $classStmt->execute([$classId]);
            $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
            if (!$classInfo) {
                throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): الفصل المحدد غير موجود.");
            }

            ProfileInputValidator::nationalId($nationalId, "الصف {$rowNumber} - الرقم القومي للطالب {$name}");
            ProfileInputValidator::mobile($phoneMobile, "الصف {$rowNumber} - رقم موبايل الطالب {$name}");
            if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
                throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): النوع المحدد غير صالح.");
            }
            if ($enrollmentDate !== '') {
                $date = DateTime::createFromFormat('Y-m-d', $enrollmentDate);
                if (!$date || $date->format('Y-m-d') !== $enrollmentDate) {
                    throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): تاريخ القيد غير صالح.");
                }
            }

            if ($nationalId !== '') {
                if (isset($seenNationalIds[$nationalId])) {
                    throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): الرقم القومي مكرر داخل الدفعة مع الصف {$seenNationalIds[$nationalId]}.");
                }
                $seenNationalIds[$nationalId] = $rowNumber;
                $nationalIdStmt->execute([$nationalId]);
                $existingStudentName = $nationalIdStmt->fetchColumn();
                if ($existingStudentName) {
                    throw new InvalidArgumentException("الصف {$rowNumber} ({$name}): الرقم القومي مسجل بالفعل للطالب {$existingStudentName}.");
                }
            }

            $validatedRows[] = [
                'name' => $name,
                'class_id' => $classId,
                'class_name' => (string) $classInfo['name'],
                'grade_id' => !empty($classInfo['grade_id']) ? (int) $classInfo['grade_id'] : null,
                'national_id' => $nationalId,
                'gender' => $gender,
                'phone_mobile' => $phoneMobile,
                'enrollment_date' => $enrollmentDate,
            ];
        }

        return $validatedRows;
    }
}
