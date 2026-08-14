<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/FileUploadGuard.php';
require_once dirname(__DIR__) . '/src/Modules/Students/bootstrap.php';

if (!class_exists('StudentAttachmentService', false)) {
    class_alias(\EduCore\Modules\Students\StudentAttachmentService::class, 'StudentAttachmentService');
}
