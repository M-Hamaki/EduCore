<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/UndoManager.php';

final class FailingUndoConnection
{
    /** @var string */
    private $primaryError;
    /** @var string */
    private $mode;
    /** @var string */
    private $secondaryError;

    public function __construct(string $primaryError, string $mode, string $secondaryError = '')
    {
        $this->primaryError = $primaryError;
        $this->mode = $mode;
        $this->secondaryError = $secondaryError;
    }

    public function exec(string $sql): int
    {
        if ($this->mode === 'exec') {
            throw new RuntimeException($this->primaryError);
        }
        return 0;
    }

    public function prepare(string $sql): void
    {
        throw new RuntimeException($this->primaryError);
    }

    public function inTransaction(): bool
    {
        if ($this->mode === 'in_transaction') {
            throw new RuntimeException($this->secondaryError);
        }
        return $this->mode === 'rollback';
    }

    public function rollBack(): bool
    {
        if ($this->mode === 'rollback') {
            throw new RuntimeException($this->secondaryError);
        }
        return true;
    }
}

function setUndoManagerState(FailingUndoConnection $connection, bool $tableCreated): void
{
    $reflection = new ReflectionClass(UndoManager::class);
    $dbProperty = $reflection->getProperty('db');
    $dbProperty->setAccessible(true);
    $dbProperty->setValue(null, $connection);
    $tableProperty = $reflection->getProperty('tableCreated');
    $tableProperty->setAccessible(true);
    $tableProperty->setValue(null, $tableCreated);
}

function isGenericUndoFailure(array $result): bool
{
    return ($result['success'] ?? null) === false
        && array_keys($result) === ['success', 'message']
        && ($result['message'] ?? null) === 'تعذر إتمام عملية التراجع. يرجى المحاولة مرة أخرى.';
}

$sensitiveError = 'SQLSTATE[HY000] password=secret C:\\private\\schema.sql';
$preConnectionError = 'DDL bootstrap failed at C:\\private\\undo.sql';
$transactionStateError = 'transaction-state password=secondary';
$rollbackError = 'rollback failed SQLSTATE[99999]';
$logPath = tempnam(sys_get_temp_dir(), 'educore_undo_');
$originalErrorLog = (string) ini_get('error_log');
$result = [];
$preConnectionResult = [];
$transactionStateResult = [];
$rollbackResult = [];
$logged = '';

if (is_string($logPath)) {
    ini_set('error_log', $logPath);
    try {
        setUndoManagerState(new FailingUndoConnection($sensitiveError, 'prepare'), true);
        $result = UndoManager::undo(123, 999);

        setUndoManagerState(new FailingUndoConnection($preConnectionError, 'exec'), false);
        $preConnectionResult = UndoManager::undo(123, 999);

        setUndoManagerState(
            new FailingUndoConnection($sensitiveError, 'in_transaction', $transactionStateError),
            true
        );
        $transactionStateResult = UndoManager::undo(123, 999);

        setUndoManagerState(new FailingUndoConnection($sensitiveError, 'rollback', $rollbackError), true);
        $rollbackResult = UndoManager::undo(123, 999);
    } finally {
        ini_set('error_log', $originalErrorLog);
        $logged = (string) file_get_contents($logPath);
        unlink($logPath);
    }
}

$source = (string) file_get_contents($root . '/classes/UndoManager.php');
$apiSource = (string) file_get_contents($root . '/api/undo.php');
$encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$checks = [
    'failure_contract_preserved' => ($result['success'] ?? null) === false
        && array_keys($result) === ['success', 'message'],
    'generic_user_message' => ($result['message'] ?? null)
        === 'تعذر إتمام عملية التراجع. يرجى المحاولة مرة أخرى.',
    'sensitive_exception_not_returned' => is_string($encodedResult)
        && strpos($encodedResult, $sensitiveError) === false
        && strpos($encodedResult, 'SQLSTATE') === false
        && strpos($encodedResult, 'password=') === false,
    'sensitive_exception_logged_server_side' => strpos($logged, $sensitiveError) !== false,
    'source_does_not_append_exception_to_message' => strpos(
        $source,
        "'message' => 'حدث خطأ أثناء التراجع: ' . \$e->getMessage()"
    ) === false,
    'pre_connection_failure_is_generic' => isGenericUndoFailure($preConnectionResult)
        && strpos(json_encode($preConnectionResult), $preConnectionError) === false,
    'in_transaction_failure_is_generic' => isGenericUndoFailure($transactionStateResult)
        && strpos(json_encode($transactionStateResult), $transactionStateError) === false,
    'rollback_failure_is_generic' => isGenericUndoFailure($rollbackResult)
        && strpos(json_encode($rollbackResult), $rollbackError) === false,
    'secondary_failures_logged_server_side' => strpos($logged, $preConnectionError) !== false
        && strpos($logged, $transactionStateError) !== false
        && strpos($logged, $rollbackError) !== false,
    'api_initialization_and_dispatch_are_guarded' => strpos($apiSource, 'try {') !== false
        && strpos($apiSource, '$database = new Database();') > strpos($apiSource, 'try {')
        && strpos($apiSource, '} catch (Throwable $e) {') > strpos($apiSource, '$database = new Database();'),
    'api_failure_is_generic_json' => strpos($apiSource, "http_response_code(500);") !== false
        && strpos($apiSource, "'message' => 'تعذر إتمام عملية التراجع. يرجى المحاولة مرة أخرى.'") !== false
        && strpos($apiSource, "error_log('api/undo.php error: ' . \$e->getMessage())") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
