<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/env_loader.php';

$key = 'EDUCORE_ENV_PRECEDENCE_TEST';
$file = tempnam(sys_get_temp_dir(), 'educore-env-');
file_put_contents($file, $key . "=file-value\n");

putenv($key . '=process-value');
unset($_ENV[$key], $_SERVER[$key]);
$loaded = loadEnvFile($file);
$preserved = getenv($key) === 'process-value';

@unlink($file);
putenv($key);
unset($_ENV[$key], $_SERVER[$key]);

echo 'custom_env_loaded:' . ($loaded ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'process_environment_has_precedence:' . ($preserved ? 'PASS' : 'FAIL') . PHP_EOL;
exit($loaded && $preserved ? 0 : 1);
