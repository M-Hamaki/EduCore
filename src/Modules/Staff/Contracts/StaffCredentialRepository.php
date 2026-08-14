<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Persistence port for immutable Staff qualification/training/document evidence. */
interface StaffCredentialRepository
{
    public function transactional(callable $work): mixed;

    /** Reads current identity evidence; role claims from HTTP are never accepted. */
    public function actorCanManageCredentials(int $actorUserId): bool;

    /** Historical credentials may belong to a staff profile whose service later ended. */
    public function isStaffUser(int $staffUserId): bool;

    /**
     * @param array{
     *     staff_user_id:int,
     *     credential_kind:string,
     *     credential_key:string,
     *     title:string,
     *     issuer:?string,
     *     effective_on:string,
     *     issued_on:?string,
     *     expires_on:?string,
     *     attachment_id:?int,
     *     verification_status:string,
     *     source:string,
     *     payload_hash:string,
     *     idempotency_key:string,
     *     created_by_user_id:int
     * } $credential
     * @return array{record:array<string,mixed>,replayed:bool}
     */
    public function createCredential(array $credential): array;

    /**
     * @return list<array{
     *     id:int,
     *     staff_user_id:int,
     *     credential_kind:string,
     *     expires_on:string,
     *     verification_status:string,
     *     version:int
     * }>
     */
    public function expiringCredentials(DateTimeImmutable $asOf, DateTimeImmutable $through, int $limit): array;
}
