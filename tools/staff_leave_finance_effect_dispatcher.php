<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Contracts\PayrollImpactGateway;
use EduCore\Modules\Staff\Infrastructure\PdoLeaveFinanceEffectRepository;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', [
    'apply',
    'database:',
    'actor-id:',
    'limit:',
    'gateway-bootstrap:',
    'json',
    'help',
]);

if (array_key_exists('help', $options)) {
    echo "Usage: php tools/staff_leave_finance_effect_dispatcher.php [--apply --database=<exact_database> --actor-id=<service_actor_id> --gateway-bootstrap=<project_relative_php_file>] [--limit=50] [--json]\n";
    echo "Without --apply the command only lists due Staff leave Finance effect IDs and never contacts Finance.\n";
    exit(0);
}

$apply = array_key_exists('apply', $options);
$json = array_key_exists('json', $options);
$limitText = trim((string) ($options['limit'] ?? '50'));
if (preg_match('/^\d+$/', $limitText) !== 1 || (int) $limitText <= 0 || (int) $limitText > 200) {
    fwrite(STDERR, "DISPATCH_INPUT_ERROR=limit must be an integer from 1 to 200.\n");
    exit(2);
}
$limit = (int) $limitText;

if ($apply) {
    $databaseArgument = trim((string) ($options['database'] ?? ''));
    $actorText = trim((string) ($options['actor-id'] ?? ''));
    $gatewayBootstrap = trim((string) ($options['gateway-bootstrap'] ?? ''));
    if ($databaseArgument === '' || preg_match('/^[A-Za-z0-9_]+$/', $databaseArgument) !== 1) {
        fwrite(STDERR, "DISPATCH_INPUT_ERROR=--apply requires --database=<exact connected database>.\n");
        exit(2);
    }
    if (preg_match('/^\d+$/', $actorText) !== 1 || (int) $actorText <= 0) {
        fwrite(STDERR, "DISPATCH_INPUT_ERROR=--apply requires a positive --actor-id for audit traceability.\n");
        exit(2);
    }
    if ($gatewayBootstrap === '') {
        fwrite(STDERR, "DISPATCH_INPUT_ERROR=--apply requires a Finance-owned --gateway-bootstrap.\n");
        exit(2);
    }
}

require_once $root . '/config/database.php';
require_once $root . '/src/Modules/Operations/Audit/AuditService.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "DISPATCH_UNAVAILABLE=database connection failed.\n");
    exit(1);
}

try {
    $connectedDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $repository = new PdoLeaveFinanceEffectRepository($db);
    $dueIds = $repository->dueEffectIdsForDispatch($limit, gmdate('Y-m-d H:i:s.u'));

    if (!$apply) {
        staffLeaveFinanceDispatchOutput([
            'mode' => 'dry-run',
            'connected_database' => $connectedDatabase,
            'limit' => $limit,
            'due_count' => count($dueIds),
            'due_effect_ids' => $dueIds,
        ], $json);
        exit(0);
    }

    if ($databaseArgument !== $connectedDatabase) {
        fwrite(STDERR, "DISPATCH_SAFETY_ERROR=--database does not match the connected database.\n");
        exit(2);
    }

    $gateway = staffLeaveFinanceGateway($root, $gatewayBootstrap, $db);
    $factory = new StaffModuleFactory($db, new AuditService($db));
    $result = $factory->leaveFinanceEffects($gateway)->dispatchDueEffects(
        $limit,
        (int) $actorText
    );
    staffLeaveFinanceDispatchOutput([
        'mode' => 'apply',
        'connected_database' => $connectedDatabase,
        'limit' => $limit,
        'selected_effect_ids' => $result['selected_effect_ids'],
        'accepted_count' => $result['accepted_count'],
        'retry_count' => $result['retry_count'],
        'skipped_count' => $result['skipped_count'],
    ], $json);
} catch (Throwable $exception) {
    error_log('staff leave Finance effect dispatcher failed: ' . $exception->getMessage());
    fwrite(STDERR, "DISPATCH_FAILED=the operation was not completed; inspect the protected server log.\n");
    exit(1);
}

/**
 * The Finance owner supplies a project-relative bootstrap that returns either
 * a PayrollImpactGateway or a callable accepting PDO and returning one.
 */
function staffLeaveFinanceGateway(string $root, string $bootstrap, PDO $db): PayrollImpactGateway
{
    if (preg_match('/^(?:[A-Za-z]:|[\\\\\/])/', $bootstrap) === 1) {
        throw new InvalidArgumentException('LEAVE_FINANCE_GATEWAY_BOOTSTRAP_INVALID');
    }

    $rootPath = realpath($root);
    $bootstrapPath = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', chr(92)], DIRECTORY_SEPARATOR, $bootstrap));
    if ($rootPath === false || $bootstrapPath === false || !is_file($bootstrapPath)) {
        throw new InvalidArgumentException('LEAVE_FINANCE_GATEWAY_BOOTSTRAP_INVALID');
    }
    $rootPrefix = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncasecmp($bootstrapPath, $rootPrefix, strlen($rootPrefix)) !== 0) {
        throw new InvalidArgumentException('LEAVE_FINANCE_GATEWAY_BOOTSTRAP_INVALID');
    }

    $provided = require $bootstrapPath;
    $gateway = is_callable($provided) ? $provided($db) : $provided;
    if (!$gateway instanceof PayrollImpactGateway) {
        throw new InvalidArgumentException('LEAVE_FINANCE_GATEWAY_CONTRACT_INVALID');
    }

    return $gateway;
}

/** @param array<string,mixed> $report */
function staffLeaveFinanceDispatchOutput(array $report, bool $json): void
{
    if ($json) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        return;
    }

    foreach ($report as $key => $value) {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        echo strtoupper($key) . '=' . (string) $value . PHP_EOL;
    }
}
