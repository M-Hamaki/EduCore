<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentRepository;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentStorage;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;
use InvalidArgumentException;

/**
 * Owns private medical evidence attached to an editable leave draft.
 *
 * The file is authenticated and authorized before its content is validated.
 * It moves into storage/private before the metadata write, deletes the new
 * file on any database/audit failure, and only attempts old-file cleanup
 * after the replacement metadata has committed.
 */
final class LeaveAttachmentService
{
    private LeaveRequestClock $clock;

    public function __construct(
        private LeaveAttachmentRepository $repository,
        private LeaveAttachmentStorage $storage,
        private LeaveRequestAuthorization $authorization,
        private AuditEventWriter $audit,
        ?LeaveRequestClock $clock = null
    ) {
        $this->clock = $clock ?? new SystemLeaveRequestClock();
    }

    /**
     * @param array<string,mixed> $command
     * @return array{attachment_id:int,request_id:int,lock_version:int,replaced:bool}
     */
    public function uploadMedicalAttachment(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'LEAVE_ATTACHMENT_ACTOR_INVALID');
        $requestId = $this->positiveId($command['request_id'] ?? null, 'LEAVE_ATTACHMENT_REQUEST_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'LEAVE_ATTACHMENT_LOCK_INVALID'
        );
        $file = $command['file'] ?? null;
        if (!is_array($file)) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_FILE_INVALID');
        }
        $now = $this->clock->now();
        $stored = null;
        $previousStorageRef = null;

        try {
            $receipt = $this->repository->transactional(function () use (
                $actorId,
                $requestId,
                $expectedLockVersion,
                $file,
                $now,
                &$stored,
                &$previousStorageRef
            ): array {
                $request = $this->repository->requestForUpdate($requestId);
                if ($request === null) {
                    throw new DomainException('LEAVE_ATTACHMENT_REQUEST_NOT_FOUND');
                }
                $staffUserId = $this->positiveId(
                    $request['staff_user_id'] ?? null,
                    'LEAVE_ATTACHMENT_REQUEST_INVALID'
                );
                $this->assertSelfActor($actorId, $staffUserId);
                $this->authorization->assertCanAct(
                    $actorId,
                    $staffUserId,
                    'attach_medical_document',
                    $now
                );
                if ((string) ($request['status'] ?? '') !== 'draft'
                    || (int) ($request['lock_version'] ?? 0) !== $expectedLockVersion) {
                    throw new DomainException('LEAVE_ATTACHMENT_STALE');
                }
                if (!$this->repository->lockStaffForRequest($staffUserId)) {
                    throw new DomainException('LEAVE_ATTACHMENT_STAFF_NOT_FOUND');
                }

                // FileUploadGuard runs inside the private storage adapter only
                // after authorization has succeeded for this request.
                $stored = $this->storage->storeUploadedFile($file);
                $this->assertStoredAttachment($stored);
                $replacement = $this->repository->replaceDraftMedicalAttachment(
                    $requestId,
                    $expectedLockVersion,
                    $stored + ['uploaded_by' => $actorId],
                    $now
                );
                $previousStorageRef = $replacement['previous_storage_ref'];
                $attachmentId = $this->positiveId(
                    $replacement['attachment_id'] ?? null,
                    'LEAVE_ATTACHMENT_PERSIST_FAILED'
                );
                $lockVersion = $this->positiveId(
                    $replacement['lock_version'] ?? null,
                    'LEAVE_ATTACHMENT_PERSIST_FAILED'
                );
                $this->audit->recordEvent(
                    'staff_leave_medical_attachment_uploaded',
                    'staff_leave_request_attachments',
                    $attachmentId,
                    null,
                    [
                        'request_id' => $requestId,
                        'attachment_kind' => 'medical',
                        'mime' => $stored['mime'],
                        'size' => $stored['size'],
                        'replaced_existing_attachment' => $previousStorageRef !== null,
                    ],
                    [
                        'user_id' => $actorId,
                        'occurred_at' => $this->databaseInstant($now),
                    ]
                );

                return [
                    'attachment_id' => $attachmentId,
                    'request_id' => $requestId,
                    'lock_version' => $lockVersion,
                    'replaced' => $previousStorageRef !== null,
                ];
            });
        } catch (\Throwable $exception) {
            if (is_array($stored) && isset($stored['storage_ref'])) {
                $this->cleanupNewFile((string) $stored['storage_ref']);
            }
            throw $exception;
        }

        if ($previousStorageRef !== null && $previousStorageRef !== $stored['storage_ref']) {
            $this->cleanupCommittedReplacement($previousStorageRef);
        }

        return $receipt;
    }

    /** @param array<string,mixed> $stored */
    private function assertStoredAttachment(array $stored): void
    {
        $storageRef = (string) ($stored['storage_ref'] ?? '');
        if (preg_match(
            '#^private:leave_attachments/[A-Za-z0-9_-]+\\.(pdf|jpg|jpeg|png)$#',
            $storageRef
        ) !== 1) {
            throw new DomainException('LEAVE_ATTACHMENT_STORAGE_REFERENCE_INVALID');
        }
        $originalName = trim((string) ($stored['original_name'] ?? ''));
        if ($originalName === '' || basename(str_replace('\\', '/', $originalName)) !== $originalName
            || mb_strlen($originalName, 'UTF-8') > 255) {
            throw new DomainException('LEAVE_ATTACHMENT_ORIGINAL_NAME_INVALID');
        }
        if (!in_array($stored['mime'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new DomainException('LEAVE_ATTACHMENT_MIME_INVALID');
        }
        $size = filter_var($stored['size'] ?? null, FILTER_VALIDATE_INT);
        if ($size === false || $size <= 0 || $size > 10485760) {
            throw new DomainException('LEAVE_ATTACHMENT_SIZE_INVALID');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($stored['sha256'] ?? ''))) !== 1) {
            throw new DomainException('LEAVE_ATTACHMENT_HASH_INVALID');
        }
    }

    private function assertSelfActor(int $actorId, int $staffUserId): void
    {
        if ($actorId !== $staffUserId) {
            throw new DomainException('LEAVE_ATTACHMENT_OWNER_ONLY');
        }
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function cleanupNewFile(string $storageRef): void
    {
        try {
            if (!$this->storage->delete($storageRef)) {
                error_log('Leave attachment rollback cleanup failed: ' . hash('sha256', $storageRef));
            }
        } catch (\Throwable $cleanupFailure) {
            error_log('Leave attachment rollback cleanup threw: ' . hash('sha256', $storageRef));
        }
    }

    private function cleanupCommittedReplacement(string $storageRef): void
    {
        try {
            if (!$this->storage->delete($storageRef)) {
                error_log('Leave attachment replacement cleanup failed: ' . hash('sha256', $storageRef));
            }
        } catch (\Throwable $cleanupFailure) {
            error_log('Leave attachment replacement cleanup threw: ' . hash('sha256', $storageRef));
        }
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
