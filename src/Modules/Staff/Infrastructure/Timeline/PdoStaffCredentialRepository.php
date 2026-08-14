<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Timeline;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffCredentialRepository;
use PDO;
use Throwable;

/** PDO adapter for immutable Staff credential evidence and expiry projections. */
final class PdoStaffCredentialRepository implements StaffCredentialRepository
{
    private int $savepointSequence = 0;

    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        } else {
            $savepoint = 'staff_credentials_' . (++$this->savepointSequence);
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $work();
            if ($ownsTransaction) {
                if (!$this->db->inTransaction()) {
                    throw new \RuntimeException('Staff credential transaction boundary was lost.');
                }
                $this->db->commit();
            } elseif ($savepoint !== null) {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif ($savepoint !== null && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function actorCanManageCredentials(int $actorUserId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM users
             WHERE id = :actor_user_id
               AND status = 'active'
               AND (
                    role IN ('admin', 'super_admin')
                    OR EXISTS (
                        SELECT 1
                        FROM user_role_assignments role_assignment
                        WHERE role_assignment.user_id = users.id
                          AND role_assignment.status = 'active'
                          AND role_assignment.role_key IN ('admin', 'super_admin')
                    )
               )
             LIMIT 1" . $this->forUpdate()
        );
        $statement->execute([':actor_user_id' => $actorUserId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function isStaffUser(int $staffUserId): bool
    {
        $statement = $this->db->prepare(
            'SELECT user_id FROM staff_profiles WHERE user_id = :staff_user_id LIMIT 1' . $this->forUpdate()
        );
        $statement->execute([':staff_user_id' => $staffUserId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function createCredential(array $credential): array
    {
        $existing = $this->credentialByIdempotencyKey((string) $credential['idempotency_key'], true);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['payload_hash'], (string) $credential['payload_hash'])) {
                throw new DomainException('Credential idempotency key conflicts with a different payload.');
            }

            return ['record' => $existing, 'replayed' => true];
        }

        $versionStatement = $this->db->prepare(
            'SELECT id, version, payload_hash
             FROM staff_credential_records
             WHERE staff_user_id = :staff_user_id
               AND credential_kind = :credential_kind
               AND credential_key = :credential_key
             ORDER BY version DESC
             LIMIT 1' . $this->forUpdate()
        );
        $versionStatement->execute([
            ':staff_user_id' => (int) $credential['staff_user_id'],
            ':credential_kind' => (string) $credential['credential_kind'],
            ':credential_key' => (string) $credential['credential_key'],
        ]);
        $previous = $versionStatement->fetch(PDO::FETCH_ASSOC);
        if ($previous !== false && hash_equals((string) $previous['payload_hash'], (string) $credential['payload_hash'])) {
            $record = $this->credentialById((int) $previous['id']);
            if ($record === null) {
                throw new \RuntimeException('The matching Staff credential could not be read.');
            }

            return ['record' => $record, 'replayed' => true];
        }
        $version = $previous === false ? 1 : ((int) $previous['version'] + 1);
        $supersedesId = $previous === false ? null : (int) $previous['id'];

        $statement = $this->db->prepare(
            'INSERT INTO staff_credential_records
                (staff_user_id, credential_kind, credential_key, title, issuer,
                 effective_on, issued_on, expires_on, attachment_id,
                 verification_status, lifecycle_status, supersedes_id, version,
                 source, payload_hash, idempotency_key, created_by_user_id)
             VALUES
                (:staff_user_id, :credential_kind, :credential_key, :title, :issuer,
                 :effective_on, :issued_on, :expires_on, :attachment_id,
                 :verification_status, \'active\', :supersedes_id, :version,
                 :source, :payload_hash, :idempotency_key, :created_by_user_id)'
        );
        $statement->execute([
            ':staff_user_id' => (int) $credential['staff_user_id'],
            ':credential_kind' => (string) $credential['credential_kind'],
            ':credential_key' => (string) $credential['credential_key'],
            ':title' => (string) $credential['title'],
            ':issuer' => $credential['issuer'],
            ':effective_on' => (string) $credential['effective_on'],
            ':issued_on' => $credential['issued_on'],
            ':expires_on' => $credential['expires_on'],
            ':attachment_id' => $credential['attachment_id'],
            ':verification_status' => (string) $credential['verification_status'],
            ':supersedes_id' => $supersedesId,
            ':version' => $version,
            ':source' => (string) $credential['source'],
            ':payload_hash' => (string) $credential['payload_hash'],
            ':idempotency_key' => (string) $credential['idempotency_key'],
            ':created_by_user_id' => (int) $credential['created_by_user_id'],
        ]);
        $id = (int) $this->db->lastInsertId();
        $record = $this->credentialById($id);
        if ($record === null) {
            throw new \RuntimeException('The newly created Staff credential could not be read.');
        }

        return ['record' => $record, 'replayed' => false];
    }

    public function expiringCredentials(DateTimeImmutable $asOf, DateTimeImmutable $through, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->db->prepare(
            "SELECT credential.id, credential.staff_user_id, credential.credential_kind,
                    credential.expires_on, credential.verification_status, credential.version
             FROM staff_credential_records credential
             INNER JOIN users staff_account
                ON staff_account.id = credential.staff_user_id
               AND staff_account.status = 'active'
             INNER JOIN staff_profiles profile
                ON profile.user_id = credential.staff_user_id
             WHERE credential.lifecycle_status = 'active'
               AND credential.verification_status IN ('unverified', 'verified')
               AND credential.expires_on IS NOT NULL
               AND credential.expires_on <= :through
               AND NOT EXISTS (
                    SELECT 1
                    FROM staff_credential_records successor
                    WHERE successor.supersedes_id = credential.id
               )
             ORDER BY credential.expires_on, credential.id
             LIMIT :limit"
        );
        $statement->bindValue(':through', $through->format('Y-m-d'));
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $record): array => [
                'id' => (int) $record['id'],
                'staff_user_id' => (int) $record['staff_user_id'],
                'credential_kind' => (string) $record['credential_kind'],
                'expires_on' => (string) $record['expires_on'],
                'verification_status' => (string) $record['verification_status'],
                'version' => (int) $record['version'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @return array<string,mixed>|null */
    private function credentialByIdempotencyKey(string $idempotencyKey, bool $lock): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_credential_records WHERE idempotency_key = :idempotency_key LIMIT 1'
            . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute([':idempotency_key' => $idempotencyKey]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        return $record === false ? null : $record;
    }

    /** @return array<string,mixed>|null */
    private function credentialById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_credential_records WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        return $record === false ? null : $record;
    }

    private function forUpdate(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
