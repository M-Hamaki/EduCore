<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqAttachmentStorage;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/classes/FileUploadGuard.php';

/**
 * Stores Ertaq attachment bytes outside the web root.
 *
 * The application receives only a `private:` reference. Absolute paths remain
 * inside this adapter for a future authorized download controller and are
 * never persisted or shown in a notification/receipt.
 */
final class LocalErtaqAttachmentStorage implements ErtaqAttachmentStorage
{
    private const MAX_BYTES = 10485760;

    /** @var array<string,list<string>> */
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    private string $projectRoot;
    private string $directory;
    /** @var callable(string,string):bool */
    private $uploadMover;

    public function __construct(string $projectRoot, ?callable $uploadMover = null)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $this->directory = $this->projectRoot . '/storage/private/ertaq_attachments';
        $this->uploadMover = $uploadMover ?? static fn (string $source, string $destination): bool =>
            move_uploaded_file($source, $destination);
    }

    public function storeUploadedFile(array $file): array
    {
        $validated = \FileUploadGuard::validate($file, self::MIME_MAP, self::MAX_BYTES);
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0750, true)
            && !is_dir($this->directory)) {
            throw new RuntimeException('تعذر تجهيز مساحة مرفقات ارتق الخاصة.');
        }

        $storedName = \FileUploadGuard::randomFileName('ertaq_attachment', $validated['extension']);
        $absolutePath = $this->directory . '/' . $storedName;
        if (!(($this->uploadMover)($validated['tmp_name'], $absolutePath))) {
            throw new RuntimeException('تعذر حفظ مرفق ارتق في المساحة الخاصة.');
        }
        @chmod($absolutePath, 0640);

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            @unlink($absolutePath);
            throw new RuntimeException('تعذر التحقق من مرفق ارتق المرفوع.');
        }

        return [
            'storage_ref' => 'private:ertaq_attachments/' . $storedName,
            'original_name' => $validated['original_name'],
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'sha256' => $sha256,
        ];
    }

    public function delete(string $storageRef): bool
    {
        $path = $this->absolutePath($storageRef);

        return !is_file($path) || @unlink($path);
    }

    /** Kept in storage for a future authorized stream controller. */
    public function absolutePath(string $storageRef): string
    {
        if (preg_match(
            '#^private:ertaq_attachments/[A-Za-z0-9_-]+\.(pdf|jpg|jpeg|png)$#',
            $storageRef
        ) !== 1) {
            throw new RuntimeException('مرجع مرفق ارتق غير صالح.');
        }

        return $this->projectRoot . '/storage/private/' . substr($storageRef, strlen('private:'));
    }
}
