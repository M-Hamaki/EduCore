<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\LeaveAttachmentRepository;
use InvalidArgumentException;
use PDO;

/**
 * Persists Staff-owned private leave-attachment metadata only.
 *
 * The surrounding application service owns authorization, audit evidence,
 * and filesystem rollback. This adapter never exposes an absolute path and
 * locks the leave request before replacing its active medical attachment.
 */
final class PdoLeaveAttachmentRepository implements LeaveAttachmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $work();
            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function requestForUpdate(int $requestId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, staff_user_id, status, lock_version, supporting_document_ref
             FROM staff_leave_requests
             WHERE id = ?
             FOR UPDATE'
        );
        $statement->execute([$requestId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);

        return $request === false ? null : $request;
    }

    public function lockStaffForRequest(int $staffUserId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$staffUserId]);

        return $statement->fetchColumn() !== false;
    }

    public function currentAttachmentForRequestForUpdate(int $requestId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, leave_request_id, attachment_kind, storage_ref, status
             FROM staff_leave_request_attachments
             WHERE leave_request_id = ?
               AND attachment_kind = 'medical'
               AND status = 'active'
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute([$requestId]);
        $attachment = $statement->fetch(PDO::FETCH_ASSOC);
        if ($attachment === false) {
            return null;
        }

        return [
            'attachment_id' => (int) $attachment['id'],
            'request_id' => (int) $attachment['leave_request_id'],
            'attachment_kind' => (string) $attachment['attachment_kind'],
            'storage_ref' => (string) $attachment['storage_ref'],
            'status' => (string) $attachment['status'],
        ];
    }

    public function replaceDraftMedicalAttachment(
        int $requestId,
        int $expectedLockVersion,
        array $attachment,
        DateTimeImmutable $uploadedAt
    ): array {
        $this->positiveId($requestId, 'LEAVE_ATTACHMENT_REQUEST_ID_INVALID');
        $this->positiveId($expectedLockVersion, 'LEAVE_ATTACHMENT_LOCK_INVALID');
        $stored = $this->normalizeAttachment($attachment);
        $existing = $this->currentAttachmentForRequestForUpdate($requestId);

        $requestUpdate = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET supporting_document_ref = :storage_ref,
                 lock_version = lock_version + 1
             WHERE id = :request_id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $requestUpdate->execute([
            'storage_ref' => $stored['storage_ref'],
            'request_id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);
        if ($requestUpdate->rowCount() !== 1) {
            throw new DomainException('LEAVE_ATTACHMENT_STALE');
        }

        if ($existing !== null) {
            $supersede = $this->db->prepare(
                "UPDATE staff_leave_request_attachments
                 SET status = 'superseded',
                     superseded_at = :superseded_at
                 WHERE id = :id
                   AND status = 'active'"
            );
            $supersede->execute([
                'superseded_at' => $this->instant($uploadedAt),
                'id' => $existing['attachment_id'],
            ]);
            if ($supersede->rowCount() !== 1) {
                throw new DomainException('LEAVE_ATTACHMENT_STATE_CONFLICT');
            }
        }

        $insert = $this->db->prepare(
            "INSERT INTO staff_leave_request_attachments
                (leave_request_id, attachment_kind, storage_ref, original_name,
                 detected_mime, byte_size, sha256, status, supersedes_attachment_id,
                 uploaded_by, uploaded_at)
             VALUES
                (:request_id, 'medical', :storage_ref, :original_name,
                 :detected_mime, :byte_size, :sha256, 'active', :supersedes_attachment_id,
                 :uploaded_by, :uploaded_at)"
        );
        $insert->execute([
            'request_id' => $requestId,
            'storage_ref' => $stored['storage_ref'],
            'original_name' => $stored['original_name'],
            'detected_mime' => $stored['mime'],
            'byte_size' => $stored['size'],
            'sha256' => $stored['sha256'],
            'supersedes_attachment_id' => $existing['attachment_id'] ?? null,
            'uploaded_by' => $stored['uploaded_by'],
            'uploaded_at' => $this->instant($uploadedAt),
        ]);
        $attachmentId = (int) $this->db->lastInsertId();
        if ($attachmentId <= 0) {
            throw new DomainException('LEAVE_ATTACHMENT_PERSIST_FAILED');
        }

        return [
            'attachment_id' => $attachmentId,
            'lock_version' => $expectedLockVersion + 1,
            'previous_storage_ref' => $existing['storage_ref'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $attachment
     * @return array{storage_ref:string,original_name:string,mime:string,size:int,sha256:string,uploaded_by:int}
     */
    private function normalizeAttachment(array $attachment): array
    {
        $storageRef = trim((string) ($attachment['storage_ref'] ?? ''));
        if (preg_match(
            '#^private:leave_attachments/[A-Za-z0-9_-]+\\.(pdf|jpg|jpeg|png)$#',
            $storageRef
        ) !== 1) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_STORAGE_REFERENCE_INVALID');
        }
        $originalName = basename(str_replace('\\', '/', trim((string) ($attachment['original_name'] ?? ''))));
        if ($originalName === '' || $originalName !== trim((string) ($attachment['original_name'] ?? ''))
            || mb_strlen($originalName, 'UTF-8') > 255) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_ORIGINAL_NAME_INVALID');
        }
        $mime = trim((string) ($attachment['mime'] ?? ''));
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_MIME_INVALID');
        }
        $size = filter_var($attachment['size'] ?? null, FILTER_VALIDATE_INT);
        if ($size === false || $size <= 0 || $size > 10485760) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_SIZE_INVALID');
        }
        $sha256 = strtolower(trim((string) ($attachment['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('LEAVE_ATTACHMENT_HASH_INVALID');
        }

        return [
            'storage_ref' => $storageRef,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'uploaded_by' => $this->positiveId($attachment['uploaded_by'] ?? null, 'LEAVE_ATTACHMENT_ACTOR_INVALID'),
        ];
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $value;
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
