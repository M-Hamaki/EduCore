<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned metadata persistence for the one current private medical
 * document of an editable leave request.
 */
interface LeaveAttachmentRepository extends LeaveAttachmentVerificationQuery
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function requestForUpdate(int $requestId): ?array;

    public function lockStaffForRequest(int $staffUserId): bool;

    /**
     * @param array{storage_ref:string,original_name:string,mime:string,size:int,sha256:string,uploaded_by:int} $attachment
     * @return array{attachment_id:int,lock_version:int,previous_storage_ref:?string}
     */
    public function replaceDraftMedicalAttachment(
        int $requestId,
        int $expectedLockVersion,
        array $attachment,
        DateTimeImmutable $uploadedAt
    ): array;
}
