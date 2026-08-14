<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentNotificationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentNotificationRepository;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentStorage;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use InvalidArgumentException;
use JsonException;

/**
 * Owns Ertaq's private upload metadata and its neutral notification intent.
 *
 * File validation/movement is delegated to a private storage adapter only
 * after live authorization. This service never puts a filename, storage
 * reference, subject, message text, or reason in a notification or audit
 * payload. Notification recipients and route are resolved server-side from
 * the current confidential ticket scope.
 */
final class ErtaqAttachmentNotificationService
{
    /** @var list<string> */
    private const ATTACHABLE_STATES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'reopened', 'withdrawal_requested', 'urgent_protected',
    ];

    /** @var list<string> */
    private const VISIBILITY_SCOPES = ['requester', 'assigned_team', 'restricted', 'protection_team'];

    /** @var list<string> */
    private const CONFIDENTIALITY_LEVELS = ['normal', 'restricted', 'highly_restricted'];

    public function __construct(
        private ErtaqAttachmentNotificationRepository $repository,
        private ErtaqAttachmentStorage $storage,
        private ErtaqAttachmentNotificationAuthorization $authorization,
        private StaffNotificationPort $notifications,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function uploadPrivateAttachment(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ATTACHMENT_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_ATTACHMENT_TICKET_ID_INVALID');
        $messageId = $this->nullablePositiveId($command['message_id'] ?? null, 'ERTAQ_ATTACHMENT_MESSAGE_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_ATTACHMENT_IDEMPOTENCY_INVALID'
        );
        $file = $command['file'] ?? null;
        if (!is_array($file)) {
            throw new InvalidArgumentException('ERTAQ_ATTACHMENT_FILE_INVALID');
        }
        $now = $this->now();
        $stored = null;

        try {
            return $this->repository->transactional(function () use (
                $actorId,
                $ticketId,
                $messageId,
                $idempotencyKey,
                $file,
                $now,
                &$stored
            ): array {
                $ticket = $this->requiredTicket($ticketId);
                $this->authorization->assertCanAct($actorId, 'upload_private_attachment', $ticket, $now);
                $this->assertAttachableTicket($ticket);
                if (!$this->repository->lockUser($actorId)) {
                    throw new DomainException('ERTAQ_ATTACHMENT_ACTOR_NOT_FOUND');
                }
                $message = $this->messageForTicket($messageId, $ticketId);
                $existing = $this->repository->attachmentByIdempotencyForUpdate($idempotencyKey);
                if ($existing !== null) {
                    return $this->attachmentReceipt($existing, true, 'idempotent_replay', 0);
                }

                $visibility = $this->visibility(
                    $this->authorization->resolveAttachmentVisibility($actorId, $ticket, $message, $now),
                    'ERTAQ_ATTACHMENT_VISIBILITY_INVALID'
                );
                $stored = $this->storage->storeUploadedFile($file);
                $this->assertStoredFile($stored);
                $attachment = $this->attachmentInput(
                    $ticket,
                    $message,
                    $actorId,
                    $visibility,
                    $stored,
                    $idempotencyKey,
                    $now
                );
                $attachmentId = $this->repository->insertAttachment($attachment);
                if ($attachmentId <= 0) {
                    throw new DomainException('ERTAQ_ATTACHMENT_PERSIST_FAILED');
                }
                $storedAttachment = $attachment + [
                    'id' => $attachmentId,
                    'status' => 'active',
                    'lock_version' => 1,
                ];
                $notificationPlan = $this->notificationPlan(
                    $actorId,
                    $ticket,
                    $storedAttachment,
                    $now
                );
                $this->audit->recordEvent(
                    'staff_ertaq_private_attachment_uploaded',
                    'staff_resource_attachments',
                    $attachmentId,
                    null,
                    [
                        'ticket_id' => $ticketId,
                        'message_id' => $messageId,
                        'resource_type' => $attachment['resource_type'],
                        'visibility_scope' => $visibility,
                        'confidentiality_level' => $attachment['confidentiality_level'],
                        'mime_type' => $attachment['mime_type'],
                        'byte_size' => $attachment['byte_size'],
                        'attachment_hash' => $attachment['attachment_hash'],
                        'notification_requested' => $notificationPlan['recipient_user_ids'] !== [],
                        'notification_recipient_count' => count($notificationPlan['recipient_user_ids']),
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
                [$notificationStatus, $recipientCount] = $this->enqueueNeutralNotification(
                    $storedAttachment,
                    $notificationPlan
                );

                return $this->attachmentReceipt(
                    $storedAttachment,
                    false,
                    $notificationStatus,
                    $recipientCount
                );
            });
        } catch (\Throwable $exception) {
            if (is_array($stored) && isset($stored['storage_ref'])) {
                $this->cleanupNewFile((string) $stored['storage_ref']);
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $ticket @param array<string,mixed>|null $message @param array<string,mixed> $stored @return array<string,mixed> */
    private function attachmentInput(
        array $ticket,
        ?array $message,
        int $actorId,
        string $visibility,
        array $stored,
        string $idempotencyKey,
        DateTimeImmutable $now
    ): array {
        $ticketId = $this->positiveId($ticket['id'] ?? null, 'ERTAQ_ATTACHMENT_TICKET_ID_INVALID');
        $messageId = $message === null
            ? null
            : $this->positiveId($message['id'] ?? null, 'ERTAQ_ATTACHMENT_MESSAGE_ID_INVALID');
        $resourceType = $messageId === null ? 'ertaq_ticket' : 'ertaq_message';
        $resourceId = $messageId ?? $ticketId;
        $confidentiality = $this->enum(
            $ticket['confidentiality_level'] ?? null,
            self::CONFIDENTIALITY_LEVELS,
            'ERTAQ_ATTACHMENT_CONFIDENTIALITY_INVALID'
        );
        $attachmentHash = $this->hash([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'visibility_scope' => $visibility,
            'confidentiality_level' => $confidentiality,
            'content_sha256' => (string) $stored['sha256'],
            'uploaded_by_user_id' => $actorId,
        ]);

        return [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'visibility_scope' => $visibility,
            'confidentiality_level' => $confidentiality,
            'storage_ref' => (string) $stored['storage_ref'],
            'original_name' => (string) $stored['original_name'],
            'mime_type' => (string) $stored['mime'],
            'byte_size' => (int) $stored['size'],
            'content_sha256' => (string) $stored['sha256'],
            'uploaded_by_user_id' => $actorId,
            'uploaded_at' => $this->instant($now),
            'retention_until' => null,
            'legal_hold' => 0,
            'attachment_hash' => $attachmentHash,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /** @param array<string,mixed> $ticket @param array<string,mixed> $attachment @return array{recipient_user_ids:list<int>,secure_route:?string} */
    private function notificationPlan(
        int $actorId,
        array $ticket,
        array $attachment,
        DateTimeImmutable $now
    ): array {
        $context = [
            'attachment_id' => $this->positiveId($attachment['id'] ?? null, 'ERTAQ_ATTACHMENT_PERSIST_FAILED'),
            'ticket_id' => $this->positiveId($ticket['id'] ?? null, 'ERTAQ_ATTACHMENT_TICKET_ID_INVALID'),
            'message_id' => $this->nullablePositiveId(
                $attachment['message_id'] ?? null,
                'ERTAQ_ATTACHMENT_MESSAGE_ID_INVALID'
            ),
            'visibility_scope' => (string) $attachment['visibility_scope'],
            'confidentiality_level' => (string) $attachment['confidentiality_level'],
        ];
        $resolved = $this->authorization->resolveNeutralNotification($actorId, $ticket, $context, $now);
        if (!is_array($resolved)) {
            throw new InvalidArgumentException('ERTAQ_ATTACHMENT_NOTIFICATION_ROUTE_INVALID');
        }
        $recipients = $this->uniquePositiveIds(
            $resolved['recipient_user_ids'] ?? [],
            'ERTAQ_ATTACHMENT_NOTIFICATION_RECIPIENT_INVALID'
        );
        if ($recipients === []) {
            return ['recipient_user_ids' => [], 'secure_route' => null];
        }
        return [
            'recipient_user_ids' => $recipients,
            'secure_route' => $this->secureRoute(
            $resolved['secure_route'] ?? null,
            'ERTAQ_ATTACHMENT_NOTIFICATION_ROUTE_INVALID'
            ),
        ];
    }

    /** @param array<string,mixed> $attachment @param array{recipient_user_ids:list<int>,secure_route:?string} $plan @return array{0:string,1:int} */
    private function enqueueNeutralNotification(array $attachment, array $plan): array
    {
        $recipients = $plan['recipient_user_ids'];
        if ($recipients === []) {
            return ['not_required', 0];
        }
        $secureRoute = $plan['secure_route'];
        if (!is_string($secureRoute)) {
            throw new InvalidArgumentException('ERTAQ_ATTACHMENT_NOTIFICATION_ROUTE_INVALID');
        }
        $attachmentId = $this->positiveId($attachment['id'] ?? null, 'ERTAQ_ATTACHMENT_PERSIST_FAILED');
        $receipt = $this->notifications->notifyRecipients(
            'ertaq:attachment:' . $attachmentId . ':uploaded',
            $recipients,
            $secureRoute,
            'لديك تحديث جديد في منصة ارتق.',
            [
                'resource_type' => (string) $attachment['resource_type'],
                'attachment_id' => $attachmentId,
                'ticket_id' => $this->positiveId($attachment['ticket_id'] ?? null, 'ERTAQ_ATTACHMENT_TICKET_ID_INVALID'),
                'message_id' => $this->nullablePositiveId(
                    $attachment['message_id'] ?? null,
                    'ERTAQ_ATTACHMENT_MESSAGE_ID_INVALID'
                ),
                'visibility_scope' => (string) $attachment['visibility_scope'],
                'confidentiality_level' => (string) $attachment['confidentiality_level'],
            ],
            hash('sha256', 'ertaq-attachment-notification-v1:' . (string) $attachment['idempotency_key'])
        );
        if (($receipt['accepted'] ?? false) !== true) {
            throw new DomainException('ERTAQ_ATTACHMENT_NOTIFICATION_NOT_ACCEPTED');
        }

        return [
            $this->requiredText(
                $receipt['status'] ?? null,
                80,
                'ERTAQ_ATTACHMENT_NOTIFICATION_STATUS_INVALID'
            ),
            count($recipients),
        ];
    }

    /** @param array<string,mixed>|null $message */
    private function messageForTicket(?int $messageId, int $ticketId): ?array
    {
        if ($messageId === null) {
            return null;
        }
        $message = $this->repository->messageForUpdate($messageId);
        if ($message === null) {
            throw new DomainException('ERTAQ_ATTACHMENT_MESSAGE_NOT_FOUND');
        }
        if ((int) ($message['ticket_id'] ?? 0) !== $ticketId) {
            throw new DomainException('ERTAQ_ATTACHMENT_MESSAGE_TICKET_MISMATCH');
        }

        return $message;
    }

    /** @param array<string,mixed> $ticket */
    private function assertAttachableTicket(array $ticket): void
    {
        $status = $this->requiredText(
            $ticket['status'] ?? null,
            40,
            'ERTAQ_ATTACHMENT_TICKET_STATE_INVALID'
        );
        if (!in_array($status, self::ATTACHABLE_STATES, true)) {
            throw new DomainException('ERTAQ_ATTACHMENT_TICKET_STATE_FORBIDDEN');
        }
    }

    /** @param array<string,mixed> $stored */
    private function assertStoredFile(array $stored): void
    {
        $storageRef = (string) ($stored['storage_ref'] ?? '');
        if (preg_match(
            '#^private:ertaq_attachments/[A-Za-z0-9_-]+\.(pdf|jpg|jpeg|png)$#',
            $storageRef
        ) !== 1) {
            throw new DomainException('ERTAQ_ATTACHMENT_STORAGE_REFERENCE_INVALID');
        }
        $originalName = trim((string) ($stored['original_name'] ?? ''));
        if ($originalName === ''
            || basename(str_replace('\\', '/', $originalName)) !== $originalName
            || mb_strlen($originalName, 'UTF-8') > 255) {
            throw new DomainException('ERTAQ_ATTACHMENT_ORIGINAL_NAME_INVALID');
        }
        if (!in_array($stored['mime'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new DomainException('ERTAQ_ATTACHMENT_MIME_INVALID');
        }
        $size = filter_var($stored['size'] ?? null, FILTER_VALIDATE_INT);
        if ($size === false || $size <= 0 || $size > 10485760) {
            throw new DomainException('ERTAQ_ATTACHMENT_SIZE_INVALID');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($stored['sha256'] ?? ''))) !== 1) {
            throw new DomainException('ERTAQ_ATTACHMENT_HASH_INVALID');
        }
    }

    /** @param array<string,mixed> $attachment @return array<string,mixed> */
    private function attachmentReceipt(
        array $attachment,
        bool $replayed,
        string $notificationStatus,
        int $recipientCount
    ): array {
        return [
            'attachment_id' => $this->positiveId($attachment['id'] ?? null, 'ERTAQ_ATTACHMENT_PERSIST_FAILED'),
            'ticket_id' => $this->positiveId($attachment['ticket_id'] ?? null, 'ERTAQ_ATTACHMENT_TICKET_ID_INVALID'),
            'message_id' => $this->nullablePositiveId(
                $attachment['message_id'] ?? null,
                'ERTAQ_ATTACHMENT_MESSAGE_ID_INVALID'
            ),
            'resource_type' => $this->enum(
                $attachment['resource_type'] ?? null,
                ['ertaq_ticket', 'ertaq_message'],
                'ERTAQ_ATTACHMENT_RESOURCE_TYPE_INVALID'
            ),
            'visibility_scope' => $this->visibility(
                $attachment['visibility_scope'] ?? null,
                'ERTAQ_ATTACHMENT_VISIBILITY_INVALID'
            ),
            'confidentiality_level' => $this->enum(
                $attachment['confidentiality_level'] ?? null,
                self::CONFIDENTIALITY_LEVELS,
                'ERTAQ_ATTACHMENT_CONFIDENTIALITY_INVALID'
            ),
            'mime_type' => (string) ($attachment['mime_type'] ?? $attachment['mime'] ?? ''),
            'byte_size' => (int) ($attachment['byte_size'] ?? $attachment['size'] ?? 0),
            'notification_status' => $notificationStatus,
            'notification_recipient_count' => $recipientCount,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string,mixed> */
    private function requiredTicket(int $ticketId): array
    {
        $ticket = $this->repository->ticketForUpdate($ticketId);
        if ($ticket === null) {
            throw new DomainException('ERTAQ_TICKET_NOT_FOUND');
        }

        return $ticket;
    }

    /** @return list<int> */
    private function uniquePositiveIds(mixed $value, string $error): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($error);
        }
        $ids = [];
        foreach ($value as $item) {
            $id = $this->positiveId($item, $error);
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    private function secureRoute(mixed $value, string $error): string
    {
        $route = $this->requiredText($value, 500, $error);
        if (!str_starts_with($route, '/')
            || str_starts_with($route, '//')
            || str_contains($route, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $route)) {
            throw new InvalidArgumentException($error);
        }
        $path = parse_url($route, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException($error);
        }
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException($error);
            }
        }

        return $route;
    }

    private function visibility(mixed $value, string $error): string
    {
        return $this->enum($value, self::VISIBILITY_SCOPES, $error);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $error): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $error);
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function requiredText(mixed $value, int $maxBytes, string $error): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $text = trim($value);
        if ($text === '' || strlen($text) > $maxBytes) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function instant(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException) {
            throw new InvalidArgumentException('ERTAQ_ATTACHMENT_SERIALIZATION_INVALID');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function cleanupNewFile(string $storageRef): void
    {
        try {
            if (!$this->storage->delete($storageRef)) {
                error_log('Ertaq attachment rollback cleanup failed: ' . hash('sha256', $storageRef));
            }
        } catch (\Throwable) {
            error_log('Ertaq attachment rollback cleanup threw: ' . hash('sha256', $storageRef));
        }
    }
}
