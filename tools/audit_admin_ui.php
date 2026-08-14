<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminFiles = glob($root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . '*.php') ?: [];
$issues = [];

function addIssue(array &$issues, string $file, string $type, int $count): void
{
    if ($count > 0) {
        $issues[] = basename($file) . ': ' . $type . '=' . $count;
    }
}

foreach ($adminFiles as $file) {
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $exitCode);
    if ($exitCode !== 0) {
        $issues[] = basename($file) . ': php_lint_failed';
    }

    $source = file_get_contents($file);
    if ($source === false) {
        $issues[] = basename($file) . ': unreadable';
        continue;
    }

    preg_match_all('/<div\s+class="modal-content(?=[\s"])(?![^"]*\badmin-modal\b)/i', $source, $matches);
    addIssue($issues, $file, 'legacy_modals', count($matches[0]));

    preg_match_all('/class="modal-header[^"]*\b(?:bg-(?:primary|success|danger|warning|info|dark|light)|text-(?:white|dark))\b/i', $source, $matches);
    addIssue($issues, $file, 'legacy_modal_headers', count($matches[0]));

    preg_match_all('/<button\b(?=[^>]*data-bs-dismiss="modal")(?=[^>]*class="[^"]*\b(?:btn-danger|btn-outline-danger|btn-primary|btn-outline-primary|btn-warning|btn-success)\b)[^>]*>/i', $source, $matches);
    addIssue($issues, $file, 'legacy_modal_cancel_buttons', count($matches[0]));

    preg_match_all('/\b(?:confirm\s*\(|Swal\.|SweetAlert|header\.className|modalHeader[^\r\n]*className)/', $source, $matches);
    addIssue($issues, $file, 'legacy_confirmation_or_modal_js', count($matches[0]));

    preg_match_all('/<style\b[^>]*>.*?^[\t ]*\.(?:stat-card|btn)(?:[-_:.[\]a-zA-Z0-9 ]*)\{/ms', $source, $matches);
    addIssue($issues, $file, 'local_shared_component_css', count($matches[0]));
}

$buttonOwner = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'buttons.css';
foreach (['style.css', 'premium-dashboard.css', 'admin-unified.css'] as $cssFile) {
    $path = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . $cssFile;
    $source = file_get_contents($path) ?: '';
    if (strpos($source, 'btn-action-pills') !== false) {
        $issues[] = $cssFile . ': owns_btn_action_pills';
    }
}
if (strpos(file_get_contents($buttonOwner) ?: '', 'btn-action-pills') === false) {
    $issues[] = 'buttons.css: missing_btn_action_pills';
}

echo 'ADMIN_PHP_FILES=' . count($adminFiles) . PHP_EOL;
echo 'UI_AUDIT_ISSUES=' . count($issues) . PHP_EOL;
foreach ($issues as $issue) {
    echo $issue . PHP_EOL;
}

exit($issues ? 1 : 0);

