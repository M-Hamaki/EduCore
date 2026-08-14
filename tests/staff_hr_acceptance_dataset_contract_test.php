<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$first = require $root . '/tests/fixtures/staff_hr_acceptance_dataset.php';
$second = StaffHrAcceptanceDataset::build();
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};

$assert($first === $second, 'dataset_builder_is_deterministic');
$assert(StaffHrAcceptanceDataset::verifyChecksum($first), 'dataset_checksum_is_self_verifying');
$assert(
    ($first['meta']['required_marker'] ?? null) === 'integrated-staff-hr'
    && ($first['meta']['database_suffix'] ?? null) === '_test'
    && ($first['meta']['synthetic'] ?? null) === true,
    'dataset_requires_explicit_isolated_target'
);
$assert(
    array_keys($first['scenarios']) === array_map(
        static fn (int $number): string => sprintf('Q%02d', $number),
        range(1, 33)
    ),
    'dataset_covers_Q01_through_Q33'
);

$personaKeys = array_column($first['personas'], 'key');
$emails = array_column($first['personas'], 'email');
$employeeCodes = array_column($first['personas'], 'employee_code');
$biometricIds = array_column($first['personas'], 'biometric_id');
$assert(count($personaKeys) === count(array_unique($personaKeys)), 'persona_keys_are_unique');
$assert(count($emails) === count(array_unique($emails)), 'persona_emails_are_unique');
$assert(count($employeeCodes) === count(array_unique($employeeCodes)), 'employee_codes_are_unique_and_separate');
$assert(count($biometricIds) === count(array_unique($biometricIds)), 'biometric_ids_are_unique_and_separate');
$assert(
    count(array_filter($emails, static fn (string $email): bool => str_ends_with($email, '@example.test')))
        === count($emails),
    'all_persona_addresses_use_reserved_example_domain'
);
$assert(
    array_intersect($employeeCodes, $biometricIds) === [],
    'employee_codes_never_mirror_biometric_ids'
);

$encoded = json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$assert(!preg_match('/(?:\+?20)?01[0125]\d{8}/', $encoded), 'dataset_contains_no_realistic_phone_number');
$assert(!preg_match('/\b\d{14}\b/', $encoded), 'dataset_contains_no_national_id_shape');
$assert(!preg_match('/(?:password|secret|token|BEGIN PRIVATE KEY|sk-)/i', $encoded), 'dataset_contains_no_secret_or_raw_password');
$assert(!preg_match('/(?:localhost|file:\/\/|[A-Z]:\\\\|\/home\/)/i', $encoded), 'dataset_contains_no_machine_specific_path_or_url');

$ownership = (array) ($first['ownership']['resource_keys'] ?? []);
$assert(count($ownership) === count(array_unique($ownership)), 'ownership_manifest_has_no_duplicate_key');
$assert(
    count(array_filter($ownership, static fn (string $key): bool => str_starts_with($key, 'scenario:'))) === 33,
    'ownership_manifest_covers_every_scenario'
);
$assert(
    isset($first['scenarios']['Q16'])
    && in_array('worker_teacher', $first['scenarios']['Q16']['personas'], true)
    && in_array('worker_specialist', $first['scenarios']['Q16']['personas'], true),
    'multi_role_worker_personas_are_explicit'
);
$assert(
    ($first['resources']['schedule_policies'][0]['start'] ?? null) === '07:30'
    && ($first['resources']['schedule_policies'][0]['end'] ?? null) === '14:30'
    && in_array('demo_late_arrival', $first['scenarios']['Q04']['resource_keys'], true),
    'core_late_arrival_acceptance_fixture_is_explicit'
);
$assert(
    count($first['resources']['biometric_events'] ?? []) === 3
    && count($first['resources']['attendance_versions'] ?? []) === 1,
    'dataset_declares_representative_raw_and_projected_attendance'
);
$assert(
    count($first['resources']['permission_requests'] ?? []) === 2
    && count($first['resources']['permission_ledgers'] ?? []) === 1
    && count($first['resources']['leave_requests'] ?? []) === 4
    && count($first['resources']['leave_ledgers'] ?? []) === 3,
    'dataset_declares_permission_and_leave_requests_with_ledgers'
);
$assert(
    count($first['resources']['discipline_cases'] ?? []) === 2
    && count($first['resources']['discipline_appeals'] ?? []) === 1,
    'dataset_declares_a_discipline_case_and_appeal'
);
$ertaq = (array) ($first['resources']['ertaq_tickets'] ?? []);
$assert(
    count($ertaq) === 3
    && array_column($ertaq, 'confidentiality_level') === ['normal', 'restricted', 'highly_restricted']
    && array_column($ertaq, 'risk_level') === ['none', 'none', 'immediate'],
    'dataset_declares_normal_confidential_and_urgent_ertaq_tickets'
);

exit($failures > 0 ? 1 : 0);
