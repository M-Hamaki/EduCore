<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqConversationRepository;
use PDO;
use PDOException;

/**
 * PDO persistence for Staff-owned Ertaq message, party, link, and withdrawal
 * evidence. It does not query or mutate a discipline, notification, file, or
 * organization table.
 */
final class PdoErtaqConversationRepository implements ErtaqConversationRepository
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
            'SELECT * FROM staff_ertaq_tickets WHERE id = ? FOR UPDATE',
            [$ticketId]
        );
    }

    public function transitionTicket(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool {
        $allowedColumns = [
            'withdrawal_requested_at' => 'withdrawal_requested_at',
            'withdrawal_requested_by_user_id' => 'withdrawal_requested_by_user_id',
            'closure_reason' => 'closure_reason',
            'closed_at' => 'closed_at',
            'closed_by_user_id' => 'closed_by_user_id',
        ];
        $sets = ['status = :to_status', 'lock_version = lock_version + 1'];
        $params = [
            'id' => $ticketId,
            'lock_version' => $expectedLockVersion,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ];
        foreach ($allowedColumns as $input => $column) {
            if (!array_key_exists($input, $changes)) {
                continue;
            }
            $sets[] = $column . ' = :' . $input;
            $params[$input] = $changes[$input];
        }
        $statement = $this->db->prepare(
            'UPDATE staff_ertaq_tickets
             SET ' . implode(', ', $sets) . '
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version'
        );
        $statement->execute($params);

        return $statement->rowCount() === 1;
    }

    public function messageByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_messages WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function messageForUpdate(int $messageId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_messages WHERE id = ? FOR UPDATE',
            [$messageId]
        );
    }

    public function insertMessage(array $message): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_ertaq_messages (
                ticket_id, sender_user_id, message_type, visibility,
                body_cipher_or_text, body_hash, reply_to_message_id,
                idempotency_key, sent_at
            ) VALUES (
                :ticket_id, :sender_user_id, :message_type, :visibility,
                :body_cipher_or_text, :body_hash, :reply_to_message_id,
                :idempotency_key, :sent_at
            )'
        );
        $statement->execute([
            'ticket_id' => (int) $message['ticket_id'],
            'sender_user_id' => $message['sender_user_id'] ?? null,
            'message_type' => (string) $message['message_type'],
            'visibility' => (string) $message['visibility'],
            'body_cipher_or_text' => (string) $message['body_cipher_or_text'],
            'body_hash' => (string) $message['body_hash'],
            'reply_to_message_id' => $message['reply_to_message_id'] ?? null,
            'idempotency_key' => (string) $message['idempotency_key'],
            'sent_at' => (string) $message['sent_at'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_parties WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function insertParty(array $party): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_ertaq_parties (
                ticket_id, party_user_id, external_party_label, party_role,
                visibility_scope, conflict_status, added_by_user_id,
                idempotency_key, party_hash
            ) VALUES (
                :ticket_id, :party_user_id, :external_party_label, :party_role,
                :visibility_scope, \'unknown\', :added_by_user_id,
                :idempotency_key, :party_hash
            )'
        );
        $statement->execute([
            'ticket_id' => (int) $party['ticket_id'],
            'party_user_id' => $party['party_user_id'] ?? null,
            'external_party_label' => $party['external_party_label'] ?? null,
            'party_role' => (string) $party['party_role'],
            'visibility_scope' => (string) $party['visibility_scope'],
            'added_by_user_id' => (int) $party['added_by_user_id'],
            'idempotency_key' => (string) $party['idempotency_key'],
            'party_hash' => (string) $party['party_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function linkByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_ticket_links WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function insertLink(array $link): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_ertaq_ticket_links (
                ticket_id, related_ticket_id, target_resource_type,
                target_resource_id, link_type, visibility_scope, link_reason,
                linked_by_user_id, link_hash, idempotency_key
            ) VALUES (
                :ticket_id, :related_ticket_id, :target_resource_type,
                :target_resource_id, :link_type, :visibility_scope, :link_reason,
                :linked_by_user_id, :link_hash, :idempotency_key
            )'
        );
        $statement->execute([
            'ticket_id' => (int) $link['ticket_id'],
            'related_ticket_id' => $link['related_ticket_id'] ?? null,
            'target_resource_type' => $link['target_resource_type'] ?? null,
            'target_resource_id' => $link['target_resource_id'] ?? null,
            'link_type' => (string) $link['link_type'],
            'visibility_scope' => (string) $link['visibility_scope'],
            'link_reason' => $link['link_reason'] ?? null,
            'linked_by_user_id' => (int) $link['linked_by_user_id'],
            'link_hash' => (string) $link['link_hash'],
            'idempotency_key' => (string) $link['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function withdrawalByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_withdrawal_events WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function withdrawalEventForUpdate(int $withdrawalEventId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_withdrawal_events WHERE id = ? FOR UPDATE',
            [$withdrawalEventId]
        );
    }

    public function withdrawalDecisionForRequestForUpdate(int $requestEventId): ?array
    {
        return $this->oneForUpdate(
            "SELECT *
             FROM staff_ertaq_withdrawal_events
             WHERE request_event_id = ?
               AND event_type = 'decided'
             FOR UPDATE",
            [$requestEventId]
        );
    }

    public function insertWithdrawalEvent(array $event): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_ertaq_withdrawal_events (
                ticket_id, event_type, request_event_id, prior_ticket_status,
                requested_by_user_id, requested_at, withdrawal_reason,
                decided_by_user_id, decided_at, outcome, decision_reason,
                event_hash, idempotency_key
            ) VALUES (
                :ticket_id, :event_type, :request_event_id, :prior_ticket_status,
                :requested_by_user_id, :requested_at, :withdrawal_reason,
                :decided_by_user_id, :decided_at, :outcome, :decision_reason,
                :event_hash, :idempotency_key
            )'
        );
        $statement->execute([
            'ticket_id' => (int) $event['ticket_id'],
            'event_type' => (string) $event['event_type'],
            'request_event_id' => $event['request_event_id'] ?? null,
            'prior_ticket_status' => $event['prior_ticket_status'] ?? null,
            'requested_by_user_id' => $event['requested_by_user_id'] ?? null,
            'requested_at' => $event['requested_at'] ?? null,
            'withdrawal_reason' => $event['withdrawal_reason'] ?? null,
            'decided_by_user_id' => $event['decided_by_user_id'] ?? null,
            'decided_at' => $event['decided_at'] ?? null,
            'outcome' => $event['outcome'] ?? null,
            'decision_reason' => $event['decision_reason'] ?? null,
            'event_hash' => (string) $event['event_hash'],
            'idempotency_key' => (string) $event['idempotency_key'],
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
