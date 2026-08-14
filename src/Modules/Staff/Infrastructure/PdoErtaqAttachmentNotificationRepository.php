<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqAttachmentNotificationRepository;
use PDO;
use PDOException;

/**
 * PDO metadata adapter for private Ertaq attachments. It owns neither the
 * private bytes nor notification inbox/outbox rows, which remain behind their
 * dedicated contracts.
 */
final class PdoErtaqAttachmentNotificationRepository implements ErtaqAttachmentNotificationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $attempt = 0;
        do {
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
                if (!$ownsTransaction || !$this->isRetryableConcurrencyFailure($exception) || ++$attempt >= 4) {
                    throw $exception;
                }
                usleep(5000 * $attempt);
            }
        } while (true);
    }

    public function lockUser(int $userId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$userId]);

        return $statement->fetchColumn() !== false;
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->oneForUpdate(
            'SELECT id, requester_user_id, type, classification, confidentiality_level,
                    priority, risk_level, status, lock_version, created_at, updated_at
             FROM staff_ertaq_tickets
             WHERE id = ? FOR UPDATE',
            [$ticketId]
        );
    }

    public function messageForUpdate(int $messageId): ?array
    {
        return $this->oneForUpdate(
            'SELECT id, ticket_id, sender_user_id, message_type, visibility, reply_to_message_id, sent_at
             FROM staff_ertaq_messages
             WHERE id = ? FOR UPDATE',
            [$messageId]
        );
    }

    public function attachmentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT id, resource_type, resource_id, ticket_id, message_id,
                    visibility_scope, confidentiality_level, mime_type, byte_size,
                    status, lock_version, idempotency_key
             FROM staff_resource_attachments
             WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function insertAttachment(array $attachment): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_resource_attachments (
                resource_type, resource_id, ticket_id, message_id,
                visibility_scope, confidentiality_level, storage_ref, original_name,
                mime_type, byte_size, content_sha256, uploaded_by_user_id, uploaded_at,
                retention_until, legal_hold, attachment_hash, idempotency_key
            ) VALUES (
                :resource_type, :resource_id, :ticket_id, :message_id,
                :visibility_scope, :confidentiality_level, :storage_ref, :original_name,
                :mime_type, :byte_size, :content_sha256, :uploaded_by_user_id, :uploaded_at,
                :retention_until, :legal_hold, :attachment_hash, :idempotency_key
            )'
        );
        $statement->execute([
            'resource_type' => (string) $attachment['resource_type'],
            'resource_id' => (int) $attachment['resource_id'],
            'ticket_id' => (int) $attachment['ticket_id'],
            'message_id' => $attachment['message_id'] ?? null,
            'visibility_scope' => (string) $attachment['visibility_scope'],
            'confidentiality_level' => (string) $attachment['confidentiality_level'],
            'storage_ref' => (string) $attachment['storage_ref'],
            'original_name' => (string) $attachment['original_name'],
            'mime_type' => (string) $attachment['mime_type'],
            'byte_size' => (int) $attachment['byte_size'],
            'content_sha256' => (string) $attachment['content_sha256'],
            'uploaded_by_user_id' => (int) $attachment['uploaded_by_user_id'],
            'uploaded_at' => (string) $attachment['uploaded_at'],
            'retention_until' => $attachment['retention_until'] ?? null,
            'legal_hold' => (int) ($attachment['legal_hold'] ?? 0),
            'attachment_hash' => (string) $attachment['attachment_hash'],
            'idempotency_key' => (string) $attachment['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function oneForUpdate(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function isRetryableConcurrencyFailure(\Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $code = (string) $exception->getCode();
        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'serialization failure');
    }
}
