<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Minimal evidence check consumed by the leave submission owner.
 *
 * It exposes no filesystem path, original name, or health content. The
 * request service only needs to prove that its stored reference still maps
 * to one active, private attachment while the request row is locked.
 */
interface LeaveAttachmentVerificationQuery
{
    /**
     * @return array{attachment_id:int,request_id:int,attachment_kind:string,storage_ref:string,status:string}|null
     */
    public function currentAttachmentForRequestForUpdate(int $requestId): ?array;
}
