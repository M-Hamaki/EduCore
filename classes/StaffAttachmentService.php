<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/FileUploadGuard.php';
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';

if (!class_exists('StaffAttachmentService', false)) {
    class_alias(\EduCore\Modules\Staff\StaffAttachmentService::class, 'StaffAttachmentService');
}
