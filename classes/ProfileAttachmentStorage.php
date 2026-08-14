<?php

declare(strict_types=1);

final class ProfileAttachmentStorage
{
    private const PRIVATE_PREFIX = 'private:';

    public function storeUploadedFile(string $temporaryPath, string $entityType, string $fileName): string
    {
        $directory = $this->privateDirectory($entityType);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('تعذر تجهيز مساحة التخزين الخاصة.');
        }

        $safeName = $this->safeFileName($fileName);
        $destination = $directory . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('فشل في حفظ الملف في مساحة التخزين الخاصة.');
        }

        @chmod($destination, 0640);
        return self::PRIVATE_PREFIX . $safeName;
    }

    public function absolutePath(string $entityType, string $storedName): ?string
    {
        if (str_starts_with($storedName, self::PRIVATE_PREFIX)) {
            $fileName = substr($storedName, strlen(self::PRIVATE_PREFIX));
            $path = $this->privateDirectory($entityType) . DIRECTORY_SEPARATOR . $this->safeFileName($fileName);
        } else {
            $legacyFolder = $entityType === 'student' ? 'students' : 'staff';
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
                . $legacyFolder . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR
                . $this->safeFileName($storedName);
        }

        return is_file($path) ? $path : null;
    }

    public function delete(string $entityType, string $storedName): bool
    {
        $path = $this->absolutePath($entityType, $storedName);
        return $path === null || @unlink($path);
    }

    /**
     * Copy a legacy public attachment into private storage without deleting the
     * source. Keeping the source makes the database update independently
     * reversible during the dual-read migration window.
     *
     * @return array{stored_name:string,sha256:string,size:int}
     */
    public function copyLegacyToPrivate(string $entityType, string $storedName): array
    {
        if (str_starts_with($storedName, self::PRIVATE_PREFIX)) {
            throw new InvalidArgumentException('المرفق موجود بالفعل في التخزين الخاص.');
        }
        $source = $this->absolutePath($entityType, $storedName);
        if ($source === null) {
            throw new RuntimeException('ملف المرفق القديم غير موجود.');
        }

        $directory = $this->privateDirectory($entityType);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('تعذر تجهيز مساحة التخزين الخاصة.');
        }

        $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        $newName = 'migrated_' . bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $destination = $directory . DIRECTORY_SEPARATOR . $newName;
        if (!copy($source, $destination)) {
            throw new RuntimeException('تعذر نسخ المرفق إلى التخزين الخاص.');
        }

        $sourceHash = hash_file('sha256', $source);
        $destinationHash = hash_file('sha256', $destination);
        if ($sourceHash === false || $destinationHash === false || !hash_equals($sourceHash, $destinationHash)) {
            @unlink($destination);
            throw new RuntimeException('فشل تحقق checksum بعد نسخ المرفق.');
        }

        @chmod($destination, 0640);
        return [
            'stored_name' => self::PRIVATE_PREFIX . $newName,
            'sha256' => $destinationHash,
            'size' => (int)filesize($destination),
        ];
    }

    public static function adminDownloadUrl(string $entityType, int $attachmentId): string
    {
        return 'profile_attachment.php?entity=' . rawurlencode($entityType) . '&id=' . $attachmentId;
    }

    private function privateDirectory(string $entityType): string
    {
        if (!in_array($entityType, ['student', 'staff'], true)) {
            throw new InvalidArgumentException('نوع المرفق غير صالح.');
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'profile_attachments' . DIRECTORY_SEPARATOR . $entityType;
    }

    private function safeFileName(string $fileName): string
    {
        $baseName = basename(str_replace('\\', '/', $fileName));
        if ($baseName === '' || $baseName === '.' || $baseName === '..' || $baseName !== $fileName) {
            throw new InvalidArgumentException('اسم الملف المخزن غير صالح.');
        }
        return $baseName;
    }
}
