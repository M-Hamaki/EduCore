<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/env_loader.php';

const EDUCORE_UPLOAD_LIMIT_BYTES = 10 * 1024 * 1024;
const EDUCORE_RECOMMENDED_UPLOAD_MAX_BYTES = 12 * 1024 * 1024;
const EDUCORE_RECOMMENDED_POST_MAX_BYTES = 16 * 1024 * 1024;

/** @return int<0, max> */
function uploadRuntimeIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    $multiplier = match ($unit) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };

    return max(0, (int)floor($number * $multiplier));
}

function uploadRuntimeNearestExistingDirectory(string $path): string
{
    $candidate = $path;
    while (!is_dir($candidate)) {
        $parent = dirname($candidate);
        if ($parent === $candidate) {
            break;
        }
        $candidate = $parent;
    }
    return $candidate;
}

/** @param list<array{status:string,name:string,message:string}> $results */
function uploadRuntimeAdd(array &$results, string $status, string $name, string $message): void
{
    $results[] = ['status' => $status, 'name' => $name, 'message' => $message];
}

$results = [];
$errors = 0;
$warnings = 0;

$record = static function (bool $passed, string $name, string $success, string $failure) use (&$results, &$errors): void {
    if ($passed) {
        uploadRuntimeAdd($results, 'PASS', $name, $success);
        return;
    }
    $errors++;
    uploadRuntimeAdd($results, 'FAIL', $name, $failure);
};

$warn = static function (string $name, string $message) use (&$results, &$warnings): void {
    $warnings++;
    uploadRuntimeAdd($results, 'WARN', $name, $message);
};

$record(
    PHP_VERSION_ID >= 80000,
    'php.version',
    'PHP ' . PHP_VERSION . ' is supported.',
    'PHP 8.0 or newer is required; current version is ' . PHP_VERSION . '.'
);
$record(extension_loaded('fileinfo'), 'php.fileinfo', 'fileinfo is enabled.', 'Enable the PHP fileinfo extension.');
$record(extension_loaded('pdo_mysql'), 'php.pdo_mysql', 'pdo_mysql is enabled.', 'Enable the PHP pdo_mysql extension.');
$record((bool)filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN), 'php.file_uploads', 'PHP file uploads are enabled.', 'Set file_uploads=On.');

$uploadMaxRaw = (string)ini_get('upload_max_filesize');
$postMaxRaw = (string)ini_get('post_max_size');
$uploadMax = uploadRuntimeIniBytes($uploadMaxRaw);
$postMax = uploadRuntimeIniBytes($postMaxRaw);
$record(
    $uploadMax >= EDUCORE_RECOMMENDED_UPLOAD_MAX_BYTES,
    'php.upload_max_filesize',
    'upload_max_filesize=' . $uploadMaxRaw . '.',
    'Set upload_max_filesize to at least 12M; current value is ' . ($uploadMaxRaw !== '' ? $uploadMaxRaw : 'unknown') . '.'
);
$record(
    $postMax >= EDUCORE_RECOMMENDED_POST_MAX_BYTES && $postMax > EDUCORE_UPLOAD_LIMIT_BYTES,
    'php.post_max_size',
    'post_max_size=' . $postMaxRaw . '.',
    'Set post_max_size to at least 16M; current value is ' . ($postMaxRaw !== '' ? $postMaxRaw : 'unknown') . '.'
);

$temporaryDirectory = trim((string)ini_get('upload_tmp_dir'));
if ($temporaryDirectory === '') {
    $temporaryDirectory = sys_get_temp_dir();
}
$record(
    is_dir($temporaryDirectory) && is_writable($temporaryDirectory),
    'php.upload_tmp_dir',
    'The PHP upload temporary directory is writable.',
    'The PHP upload temporary directory is missing or not writable: ' . $temporaryDirectory
);

$root = dirname(__DIR__);
$requiredDirectories = [
    'storage/private/profile_attachments/student',
    'storage/private/profile_attachments/staff',
    'uploads/staff',
];
foreach ($requiredDirectories as $relativeDirectory) {
    $absoluteDirectory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (is_dir($absoluteDirectory)) {
        $record(
            is_readable($absoluteDirectory) && is_writable($absoluteDirectory),
            'directory.' . $relativeDirectory,
            $relativeDirectory . ' is readable and writable.',
            $relativeDirectory . ' exists but the PHP user cannot read and write it.'
        );
        continue;
    }

    $existingParent = uploadRuntimeNearestExistingDirectory($absoluteDirectory);
    $record(
        is_dir($existingParent) && is_writable($existingParent),
        'directory.' . $relativeDirectory,
        $relativeDirectory . ' can be created by the PHP user.',
        $relativeDirectory . ' is missing and its nearest existing parent is not writable: ' . $existingParent
    );
}

$appUrl = rtrim(trim((string)env('APP_URL', env('SITE_URL', ''))), '/');
$scheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
$record(
    filter_var($appUrl, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true),
    'app.url',
    'APP_URL is configured as ' . $appUrl . '.',
    'Set APP_URL to the deployed application URL without a trailing slash.'
);
$appEnvironment = strtolower(trim((string)env('APP_ENV', 'development')));
if ($appEnvironment === 'production' && $scheme !== 'https') {
    $errors++;
    uploadRuntimeAdd($results, 'FAIL', 'app.https', 'Production APP_URL must use HTTPS.');
} elseif ($scheme !== 'https') {
    $warn('app.https', 'HTTPS is expected for the production deployment.');
}

$warn('webserver.body_limit', 'Verify the reverse-proxy/web-server request-body limit is at least 16M; PHP CLI cannot inspect it.');
$warn('deployment.user_files', 'Deploy uploads/ and storage/private/ data separately from Git and keep them consistent with the database backup.');

foreach ($results as $result) {
    echo '[' . $result['status'] . '] ' . $result['name'] . ': ' . $result['message'] . PHP_EOL;
}
echo 'UPLOAD_RUNTIME_ERRORS=' . $errors . PHP_EOL;
echo 'UPLOAD_RUNTIME_WARNINGS=' . $warnings . PHP_EOL;

exit($errors === 0 ? 0 : 1);
