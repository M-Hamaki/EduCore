<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Private file storage boundary for leave medical evidence.
 */
interface LeaveAttachmentStorage
{
    /**
     * @param array<string,mixed> $file
     * @return array{storage_ref:string,original_name:string,mime:string,size:int,sha256:string}
     */
    public function storeUploadedFile(array $file): array;

    /**
     * Cleanup is intentionally best effort after a committed replacement.
     * A failed cleanup never restores an obsolete database reference.
     */
    public function delete(string $storageRef): bool;
}
