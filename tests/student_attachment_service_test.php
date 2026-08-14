<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/StudentAttachmentService.php';

$reflection = new ReflectionClass(StudentAttachmentService::class);
$service = $reflection->newInstanceWithoutConstructor();
$checks = [];

foreach ([
    'label_required' => ['', ['name' => 'a.pdf', 'error' => UPLOAD_ERR_OK], 'يرجى إدخال اسم المرفق.'],
    'file_required' => ['شهادة', [], 'يرجى اختيار ملف للرفع.'],
    'extension_rejected' => ['شهادة', ['name' => 'a.exe', 'error' => UPLOAD_ERR_OK], 'نوع الملف غير مسموح. الأنواع المسموحة: pdf, doc, docx, xls, xlsx, jpg, jpeg, png, webp'],
    'size_rejected' => ['شهادة', ['name' => 'a.pdf', 'error' => UPLOAD_ERR_OK, 'size' => 10485761], 'حجم الملف يتجاوز الحد الأقصى (10MB).'],
] as $name => [$label, $file, $message]) {
    try {
        $service->upload(1, $label, $file);
        $checks[$name] = false;
    } catch (InvalidArgumentException $e) {
        $checks[$name] = $e->getMessage() === $message;
    }
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
