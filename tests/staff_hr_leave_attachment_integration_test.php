<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Leave\LeaveAttachmentService;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentRepository;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use EduCore\Modules\Staff\Infrastructure\LocalLeaveAttachmentStorage;

final class LeaveAttachmentTestRepository implements LeaveAttachmentRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $requests = [
        10 => [
            'id' => 10,
            'staff_user_id' => 55,
            'status' => 'draft',
            'lock_version' => 1,
            'supporting_document_ref' => null,
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $attachments = [];
    public bool $failPersistence = false;
    private int $nextAttachmentId = 1;

    public function transactional(callable $work): mixed
    {
        $requests = $this->requests;
        $attachments = $this->attachments;
        $nextAttachmentId = $this->nextAttachmentId;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->requests = $requests;
            $this->attachments = $attachments;
            $this->nextAttachmentId = $nextAttachmentId;
            throw $exception;
        }
    }

    public function requestForUpdate(int $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function lockStaffForRequest(int $staffUserId): bool
    {
        return $staffUserId === 55;
    }

    public function currentAttachmentForRequestForUpdate(int $requestId): ?array
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment['request_id'] === $requestId
                && $attachment['attachment_kind'] === 'medical'
                && $attachment['status'] === 'active') {
                return [
                    'attachment_id' => $attachment['id'],
                    'request_id' => $attachment['request_id'],
                    'attachment_kind' => $attachment['attachment_kind'],
                    'storage_ref' => $attachment['storage_ref'],
                    'status' => $attachment['status'],
                ];
            }
        }

        return null;
    }

    public function replaceDraftMedicalAttachment(
        int $requestId,
        int $expectedLockVersion,
        array $attachment,
        DateTimeImmutable $uploadedAt
    ): array {
        if ($this->failPersistence) {
            throw new DomainException('LEAVE_ATTACHMENT_DATABASE_FAILURE');
        }
        $request = $this->requests[$requestId] ?? null;
        if (!is_array($request)
            || $request['status'] !== 'draft'
            || $request['lock_version'] !== $expectedLockVersion) {
            throw new DomainException('LEAVE_ATTACHMENT_STALE');
        }
        $previous = $this->currentAttachmentForRequestForUpdate($requestId);
        if ($previous !== null) {
            $previousId = $previous['attachment_id'];
            $this->attachments[$previousId]['status'] = 'superseded';
            $this->attachments[$previousId]['superseded_at'] = $uploadedAt->format('Y-m-d H:i:s.u');
        }

        $attachmentId = $this->nextAttachmentId++;
        $this->attachments[$attachmentId] = [
            'id' => $attachmentId,
            'request_id' => $requestId,
            'attachment_kind' => 'medical',
            'storage_ref' => $attachment['storage_ref'],
            'original_name' => $attachment['original_name'],
            'mime' => $attachment['mime'],
            'size' => $attachment['size'],
            'sha256' => $attachment['sha256'],
            'status' => 'active',
            'supersedes_attachment_id' => $previous['attachment_id'] ?? null,
        ];
        $request['supporting_document_ref'] = $attachment['storage_ref'];
        ++$request['lock_version'];
        $this->requests[$requestId] = $request;

        return [
            'attachment_id' => $attachmentId,
            'lock_version' => $request['lock_version'],
            'previous_storage_ref' => $previous['storage_ref'] ?? null,
        ];
    }
}

final class LeaveAttachmentTestAuthorization implements LeaveRequestAuthorization
{
    public function assertCanAct(int $actorId, int $staffUserId, string $action, DateTimeImmutable $atInstant): void
    {
        if ($actorId !== 55 || $staffUserId !== 55 || $action !== 'attach_medical_document') {
            throw new DomainException('LEAVE_ATTACHMENT_ACCESS_DENIED');
        }
    }
}

final class LeaveAttachmentTestAudit implements AuditEventWriter
{
    public bool $fail = false;
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new DomainException('LEAVE_ATTACHMENT_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class LeaveAttachmentTestClock implements LeaveRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-08 09:00:00', new DateTimeZone('Africa/Cairo'));
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $operation, string $expectedCode, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $operation();
    } catch (DomainException|InvalidArgumentException $exception) {
        if ($exception->getMessage() === $expectedCode) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedCode . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'educore_leave_attachment_' . bin2hex(random_bytes(8));
if (!mkdir($sandbox, 0750, true) && !is_dir($sandbox)) {
    throw new RuntimeException('Unable to create attachment test sandbox.');
}
$temporaryFiles = [];
$makeUpload = static function (string $name, string $content, ?int $reportedSize = null) use (&$temporaryFiles): array {
    $path = tempnam(sys_get_temp_dir(), 'educore_leave_upload_');
    if ($path === false) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    file_put_contents($path, $content);
    $temporaryFiles[] = $path;

    return [
        'name' => $name,
        'tmp_name' => $path,
        'size' => $reportedSize ?? (int) filesize($path),
        'error' => UPLOAD_ERR_OK,
    ];
};
$storedFiles = static function () use ($sandbox): array {
    $paths = glob($sandbox . '/storage/private/leave_attachments/*');

    return $paths === false ? [] : $paths;
};

$repository = new LeaveAttachmentTestRepository();
$audit = new LeaveAttachmentTestAudit();
$storage = new LocalLeaveAttachmentStorage(
    $sandbox,
    static fn (string $source, string $destination): bool => copy($source, $destination)
);
$service = new LeaveAttachmentService(
    $repository,
    $storage,
    new LeaveAttachmentTestAuthorization(),
    $audit,
    new LeaveAttachmentTestClock()
);
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
$attachmentMigration = (string) file_get_contents(
    $root . '/database/migrations/20260808_staff_hr_leave_private_attachments.php'
);
$assert(
    str_contains($attachmentMigration, 'CREATE TABLE staff_leave_request_attachments')
        && str_contains($attachmentMigration, 'UNIQUE KEY uk_staff_leave_attachment_current')
        && str_contains($attachmentMigration, 'ON DELETE RESTRICT'),
    'attachment metadata keeps one current record and restricts destructive parent deletion'
);
$assert(
    str_contains($attachmentMigration, 'trg_staff_leave_attachment_guard_insert')
        && str_contains($attachmentMigration, 'trg_staff_leave_attachment_guard_update')
        && str_contains($attachmentMigration, 'trg_staff_leave_attachment_no_delete'),
    'attachment schema protects draft-only writes, immutable evidence, and metadata retention'
);
$assert(
    str_contains($attachmentMigration, 'storage_ref VARCHAR(500)')
        && !str_contains($attachmentMigration, 'uploads/'),
    'attachment schema persists a private reference rather than a web-accessible upload path'
);

$first = $service->uploadMedicalAttachment([
    'actor_id' => 55,
    'request_id' => 10,
    'expected_lock_version' => 1,
    'file' => $makeUpload('medical.pdf', $pdf),
]);
$firstRef = $repository->requests[10]['supporting_document_ref'];
$assert($first['lock_version'] === 2 && $first['replaced'] === false, 'first medical upload updates only the draft version');
$assert(
    is_string($firstRef) && str_starts_with($firstRef, 'private:leave_attachments/'),
    'database stores a normalized private reference instead of a public URL or filesystem path'
);
$assert(is_file($storage->absolutePath((string) $firstRef)), 'validated medical file is stored below the private directory');
$assert(count($audit->events) === 1, 'medical attachment write records a shared audit event');

$filesBeforeUnauthorized = count($storedFiles());
$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 56,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('not-read.pdf', $pdf),
    ]),
    'LEAVE_ATTACHMENT_OWNER_ONLY',
    'another worker cannot cause medical file validation or storage'
);
$assert(count($storedFiles()) === $filesBeforeUnauthorized, 'authorization runs before the upload storage is touched');

$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 55,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('medical.php.pdf', $pdf),
    ]),
    'اسم الملف يحتوي على امتداد مزدوج خطر.',
    'dangerous double extensions are rejected by FileUploadGuard'
);
$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 55,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('medical.pdf', '<?php echo "not a pdf";'),
    ]),
    'محتوى الملف لا يطابق نوعه المسموح.',
    'spoofed MIME content is rejected before database persistence'
);
$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 55,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('medical.pdf', $pdf, 10485761),
    ]),
    'حجم الملف يتجاوز الحد الأقصى المسموح به.',
    'oversized medical documents are rejected before storage'
);
$assert(
    $repository->requests[10]['supporting_document_ref'] === $firstRef && count($repository->attachments) === 1,
    'rejected uploads cannot replace the existing attachment metadata'
);

$repository->failPersistence = true;
$filesBeforeDatabaseFailure = count($storedFiles());
$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 55,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('database-failure.pdf', $pdf),
    ]),
    'LEAVE_ATTACHMENT_DATABASE_FAILURE',
    'database failures are returned without a partial attachment record'
);
$repository->failPersistence = false;
$assert(count($storedFiles()) === $filesBeforeDatabaseFailure, 'database rollback removes the newly moved private file');

$audit->fail = true;
$filesBeforeAuditFailure = count($storedFiles());
$assertThrows(
    static fn (): array => $service->uploadMedicalAttachment([
        'actor_id' => 55,
        'request_id' => 10,
        'expected_lock_version' => 2,
        'file' => $makeUpload('audit-failure.pdf', $pdf),
    ]),
    'LEAVE_ATTACHMENT_AUDIT_FAILURE',
    'mandatory audit failure aborts the attachment replacement'
);
$audit->fail = false;
$assert(
    count($storedFiles()) === $filesBeforeAuditFailure
        && $repository->requests[10]['supporting_document_ref'] === $firstRef
        && count($repository->attachments) === 1,
    'audit rollback leaves neither a new file nor a changed database reference'
);

$replacement = $service->uploadMedicalAttachment([
    'actor_id' => 55,
    'request_id' => 10,
    'expected_lock_version' => 2,
    'file' => $makeUpload('replacement.pdf', $pdf),
]);
$secondRef = $repository->requests[10]['supporting_document_ref'];
$assert($replacement['replaced'] === true && $replacement['lock_version'] === 3, 'replacement advances the draft version once');
$assert($secondRef !== $firstRef, 'replacement receives a new collision-resistant private reference');
$assert(!is_file($storage->absolutePath((string) $firstRef)), 'old draft-only file is removed after committed metadata replacement');
$assert(
    $repository->attachments[$first['attachment_id']]['status'] === 'superseded'
        && $repository->attachments[$replacement['attachment_id']]['supersedes_attachment_id'] === $first['attachment_id'],
    'replacement retains immutable metadata lineage without retaining a live old reference'
);
$current = $repository->currentAttachmentForRequestForUpdate(10);
$assert(
    $current !== null && $current['storage_ref'] === $secondRef && $current['attachment_kind'] === 'medical',
    'only the current medical record can prove a draft reference at submission time'
);

foreach ($temporaryFiles as $temporaryFile) {
    @unlink($temporaryFile);
}
foreach ($storedFiles() as $storedFile) {
    @unlink($storedFile);
}
@rmdir($sandbox . '/storage/private/leave_attachments');
@rmdir($sandbox . '/storage/private');
@rmdir($sandbox . '/storage');
@rmdir($sandbox);

echo 'staff_hr_leave_attachment_integration_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
