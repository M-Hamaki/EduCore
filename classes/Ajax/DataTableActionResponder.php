<?php

require_once __DIR__ . '/../../includes/http_helpers.php';

/**
 * Negotiates JSON for progressive DataTable actions while preserving PRG fallback.
 */
final class DataTableActionResponder
{
    private Closure $summaryProvider;
    private bool $ajaxRequest;

    public function __construct(bool $ajaxRequest, callable $summaryProvider)
    {
        $this->summaryProvider = Closure::fromCallable($summaryProvider);
        $this->ajaxRequest = $ajaxRequest;
    }

    public static function accepts(array $server, array $requestData): bool
    {
        return (string)($requestData['datatable_ajax'] ?? '') === '1'
            && strtolower((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            && str_contains(strtolower((string)($server['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    public function reject(string $message, int $statusCode, string $redirectUrl): void
    {
        if ($this->ajaxRequest) {
            jsonError($message, $statusCode);
        }

        $_SESSION['error_message'] = $message;
        $this->redirect($redirectUrl);
    }

    public function finish(string $redirectUrl, string $rowKey): void
    {
        if (!$this->ajaxRequest) {
            $this->redirect($redirectUrl);
        }

        $message = trim((string)($_SESSION['success_message'] ?? ''));
        $error = trim((string)($_SESSION['error_message'] ?? ''));
        unset($_SESSION['success_message'], $_SESSION['error_message']);
        if ($error !== '') {
            jsonResponse(['success' => false, 'message' => $error, 'row_key' => $rowKey], 422);
        }

        $summary = [];
        try {
            $providedSummary = ($this->summaryProvider)();
            if (is_array($providedSummary)) {
                $summary = $providedSummary;
            }
        } catch (Throwable $summaryError) {
            error_log('DataTable action summary refresh failed: ' . $summaryError->getMessage());
        }

        jsonResponse([
            'success' => true,
            'message' => $message !== '' ? $message : 'تم حفظ التغييرات بنجاح.',
            'row_key' => $rowKey,
            'summary' => $summary,
        ]);
    }

    private function redirect(string $redirectUrl): void
    {
        header('Location: ' . $redirectUrl);
        exit();
    }
}
