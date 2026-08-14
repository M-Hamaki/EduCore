<?php
/**
 * معالج AJAX موحّد لرفع مرفق واحد فوراً للطالب أو الموظف.
 *
 * المدخلات (POST):
 *   - entity_type : 'student' | 'staff'
 *   - entity_id   : معرّف الطالب/الموظف
 *   - label       : اسم/وصف المرفق (اختياري)
 *   - file        : ملف واحد (مرفق)
 *
 * الرد دائماً JSON:
 *   - نجاح: { success:true, attachment:{...}, url:'...' }
 *   - فشل : { success:false, message:'...' }
 */

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// تحويل أي خطأ PHP إلى JSON بدل HTML يكسر استجابة الـ AJAX
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("upload_attachment PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../classes/utilities.php';
require_once '../../config/database.php';
require_once '../../classes/ActivityLog.php';
require_once '../../classes/ProfileAttachmentStorage.php';
require_once '../../classes/FileUploadGuard.php';
require_once '../../classes/ProfileAttachmentLabelPolicy.php';
require_once '../../classes/StudentProfileRepository.php';
require_once '../../classes/StudentAttachmentService.php';
require_once '../../classes/StaffProfileRepository.php';
require_once '../../classes/StaffAttachmentService.php';

// التحقق من الجلسة والصلاحية
try {
    Utilities::validateSession('admin');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول.']);
    exit();
}

// التحقق من الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح.']);
    exit();
}

// التحقق من رمز الحماية CSRF (يقبل هيدر X-CSRF-Token)
requireCsrfPost();

// إغلاق الجلسة فوراً لمنع session lock عند الرفع المتزامن لعدة ملفات
session_write_close();

// التحقق من المدخلات الأساسية
$entityType = $_POST['entity_type'] ?? '';
$entityId = (int) ($_POST['entity_id'] ?? 0);
$label = trim((string) ($_POST['label'] ?? ''));
$attachmentAction = (string) ($_POST['attachment_action'] ?? 'upload');

if (!in_array($entityType, ['student', 'staff'], true)) {
    echo json_encode(['success' => false, 'message' => 'نوع الكيان غير صالح.']);
    exit();
}
if ($entityId <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرّف الكيان غير صالح.']);
    exit();
}

if (in_array($attachmentAction, ['rename', 'delete'], true)) {
    $attachmentId = (int) ($_POST['attachment_id'] ?? 0);
    if ($attachmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرّف المرفق غير صالح.']);
        exit();
    }

    try {
        $database = new Database();
        $db = $database->getConnection();
        ActivityLog::setDb($db);
        if ($entityType === 'student') {
            $service = new StudentAttachmentService(
                $db,
                new StudentProfileRepository($db),
                new ProfileAttachmentStorage()
            );
        } else {
            $service = new StaffAttachmentService(
                $db,
                new StaffProfileRepository($db),
                new ProfileAttachmentStorage(),
                dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff'
            );
        }

        if ($attachmentAction === 'rename') {
            $finalLabel = $service->renameAttachment($entityId, $attachmentId, $label);
            echo json_encode([
                'success' => true,
                'message' => 'تم تعديل اسم المرفق بنجاح.',
                'attachment' => ['id' => $attachmentId, 'label' => $finalLabel],
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $deleted = $entityType === 'student'
            ? $service->delete($entityId, $attachmentId)
            : $service->deleteAttachment($entityId, $attachmentId);
        echo json_encode([
            'success' => $deleted,
            'message' => $deleted ? 'تم حذف المرفق بنجاح.' : 'المرفق غير موجود.',
            'attachment_id' => $attachmentId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('Attachment metadata action failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'تعذر تنفيذ العملية على المرفق.'], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

if ($attachmentAction !== 'upload') {
    echo json_encode(['success' => false, 'message' => 'عملية المرفق غير صالحة.']);
    exit();
}

// التحقق من وجود الملف وسلامته
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'يرجى اختيار ملف صالح للرفع.';
    if (!empty($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'حجم الملف يتجاوز الحد المسموح به من الخادم.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = 'تم رفع جزء من الملف فقط. أعد المحاولة.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'لم يتم اختيار أي ملف.';
                break;
            default:
                $errorMsg = 'حدث خطأ أثناء رفع الملف (رمز ' . $_FILES['file']['error'] . ').';
        }
    }
    echo json_encode(['success' => false, 'message' => $errorMsg]);
    exit();
}

$file = $_FILES['file'];
$allowedMimes = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'webp' => ['image/webp'],
];
try {
    $validatedFile = FileUploadGuard::validate($file, $allowedMimes, 10 * 1024 * 1024);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
$originalName = $validatedFile['original_name'];
$fileSize = $validatedFile['size'];
$tmpPath = $validatedFile['tmp_name'];
$ext = $validatedFile['extension'];
$detectedMime = $validatedFile['mime'];
$isProfileImageUpload = $label === ProfileAttachmentLabelPolicy::PROFILE_IMAGE_LABEL;
try {
    $finalLabel = $isProfileImageUpload
        ? ProfileAttachmentLabelPolicy::PROFILE_IMAGE_LABEL
        : ProfileAttachmentLabelPolicy::labelForUpload($label, $originalName);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($entityType === 'student') {
    $table = 'student_attachments';
    $activityTargetType = 'student';
} else {
    $table = 'staff_attachments';
    $activityTargetType = 'staff';
}

// اسم فريد للملف
$fileName = 'att_' . $entityId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

// الإدراج في قاعدة البيانات
try {
    $database = new Database();
    $db = $database->getConnection();

    $expectedRole = $entityType === 'student' ? 'student' : 'staff';
    $entityStmt = $db->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
    $entityStmt->execute([$entityId]);
    $entity = $entityStmt->fetch(PDO::FETCH_ASSOC);
    $isExpectedEntity = $entity && ($expectedRole === 'student'
        ? $entity['role'] === 'student'
        : $entity['role'] !== 'student');
    if (!$isExpectedEntity) {
        throw new RuntimeException('الطالب أو الموظف المطلوب غير موجود.');
    }

    // إذا كان الموظف يرفع صورة شخصية، يتم حفظها كملف للملف الشخصي وليس كمرفق عادي
    if ($entityType === 'staff' && $isProfileImageUpload) {
        $destFolder = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff';
        if (!is_dir($destFolder) && !mkdir($destFolder, 0755, true) && !is_dir($destFolder)) {
            throw new RuntimeException('فشل في تجهيز مجلد الصورة الشخصية.');
        }

        $fileName = 'staff_' . $entityId . '_' . time() . '.' . $ext;
        $destination = $destFolder . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('فشل في رفع الصورة الشخصية للموظف.');
        }

        $db->beginTransaction();
        try {
            $oldStmt = $db->prepare('SELECT profile_image FROM staff_profiles WHERE user_id = ? FOR UPDATE');
            $oldStmt->execute([$entityId]);
            $oldImage = (string)($oldStmt->fetchColumn() ?: '');

            $db->prepare('UPDATE staff_profiles SET profile_image = ? WHERE user_id = ?')->execute([$fileName, $entityId]);

            ActivityLog::log('update', 'staff', $entityId, (string)$entity['name'], [
                'summary' => 'تم تحديث الصورة الشخصية للموظف',
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($destination);
            throw $exception;
        }

        if ($oldImage !== '') {
            $oldPath = $destFolder . DIRECTORY_SEPARATOR . basename($oldImage);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'تم رفع الصورة الشخصية بنجاح.',
            'attachment' => [
                'id' => 0,
                'label' => 'الصورة الشخصية',
                'original_name' => basename($originalName),
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'file_type' => $detectedMime,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'ext' => $ext,
            ],
            'url' => '../uploads/staff/' . $fileName,
        ]);
        exit();
    }

    $storage = new ProfileAttachmentStorage();
    $storedName = $storage->storeUploadedFile($tmpPath, $entityType, $fileName);

    $stmt = $db->prepare("INSERT INTO {$table} (user_id, file_name, original_name, label, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$entityId, $storedName, basename($originalName), $finalLabel, $fileSize, $detectedMime]);
    $attachmentId = (int) $db->lastInsertId();

    // إذا كان المرفق هو الصورة الشخصية، احذف الصورة القديمة من قاعدة البيانات والقرص
    if ($isProfileImageUpload) {
        $oldStmt = $db->prepare("SELECT id, file_name FROM {$table} WHERE user_id = ? AND label = 'الصورة الشخصية' AND id != ?");
        $oldStmt->execute([$entityId, $attachmentId]);
        $oldPhotos = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($oldPhotos as $old) {
            $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$old['id']]);
            $storage->delete($entityType, $old['file_name']);
        }
    }

    // جلب اسم الكيان لسجل النشاط
    $entityName = (string)$entity['name'];

    // تسجيل العملية في سجل النشاط
    ActivityLog::log('update', $activityTargetType, $entityId, $entityName, [
        'summary' => 'تم رفع مرفق جديد إلى ملف ' . ($entityType === 'student' ? 'الطالب' : 'الموظف'),
        'description' => $finalLabel,
        'type' => strtoupper($ext),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'تم رفع المرفق بنجاح.',
        'attachment' => [
            'id' => $attachmentId,
            'label' => $finalLabel,
            'original_name' => basename($originalName),
            'file_name' => $storedName,
            'file_size' => $fileSize,
            'file_type' => $detectedMime,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'ext' => $ext,
        ],
        'url' => ProfileAttachmentStorage::adminDownloadUrl($entityType, $attachmentId),
    ]);
} catch (Throwable $e) {
    // في حال فشل الإدراج، احذف الملف المرفوع لتفادي الملفات اليتيمة
    if (isset($storage, $storedName)) {
        $storage->delete($entityType, $storedName);
    }
    error_log('upload_attachment DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء حفظ بيانات المرفق.']);
}
