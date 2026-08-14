<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/ProfileAttachmentLabelPolicy.php';

function expectAttachmentLabelException(callable $callback): bool
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return true;
    }
    return false;
}

$expectations = [
    'normalizes_whitespace' => ProfileAttachmentLabelPolicy::normalizeEditableLabel("  شهادة   الميلاد  ") === 'شهادة الميلاد',
    'rejects_empty_label' => expectAttachmentLabelException(static function (): void {
        ProfileAttachmentLabelPolicy::normalizeEditableLabel('   ');
    }),
    'rejects_long_label' => expectAttachmentLabelException(static function (): void {
        ProfileAttachmentLabelPolicy::normalizeEditableLabel(str_repeat('أ', ProfileAttachmentLabelPolicy::MAX_LENGTH + 1));
    }),
    'rejects_reserved_new_label' => expectAttachmentLabelException(static function (): void {
        ProfileAttachmentLabelPolicy::normalizeEditableLabel(ProfileAttachmentLabelPolicy::PROFILE_IMAGE_LABEL);
    }),
    'rejects_reserved_current_label' => expectAttachmentLabelException(static function (): void {
        ProfileAttachmentLabelPolicy::assertCurrentLabelIsEditable(ProfileAttachmentLabelPolicy::PROFILE_IMAGE_LABEL);
    }),
    'default_upload_label_comes_from_filename' => ProfileAttachmentLabelPolicy::labelForUpload('', 'شهادة قيد.pdf') === 'شهادة قيد',
    'filename_cannot_impersonate_profile_image' => ProfileAttachmentLabelPolicy::labelForUpload('', 'الصورة الشخصية.pdf') === 'مرفق - الصورة الشخصية',
    'download_uses_label_and_original_extension' => ProfileAttachmentLabelPolicy::downloadName('شهادة الميلاد', 'scan.PDF') === 'شهادة الميلاد.pdf',
    'download_does_not_duplicate_extension' => ProfileAttachmentLabelPolicy::downloadName('شهادة الميلاد.pdf', 'scan.pdf') === 'شهادة الميلاد.pdf',
    'download_name_removes_path_characters' => ProfileAttachmentLabelPolicy::downloadName('شهادة/قيد', 'record.pdf') === 'شهادة-قيد.pdf',
];

$failed = [];
foreach ($expectations as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
