<?php

declare(strict_types=1);

/** Isolated PDO proof for immutable Staff credential persistence and current expiry projection. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Infrastructure\Timeline\PdoStaffCredentialRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, role_key TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE staff_profiles (user_id INTEGER PRIMARY KEY)');
    $db->exec(
        'CREATE TABLE staff_credential_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            staff_user_id INTEGER NOT NULL,
            credential_kind TEXT NOT NULL,
            credential_key TEXT NOT NULL,
            title TEXT NOT NULL,
            issuer TEXT NULL,
            effective_on TEXT NOT NULL,
            issued_on TEXT NULL,
            expires_on TEXT NULL,
            attachment_id INTEGER NULL,
            verification_status TEXT NOT NULL,
            lifecycle_status TEXT NOT NULL,
            supersedes_id INTEGER NULL,
            version INTEGER NOT NULL,
            source TEXT NOT NULL,
            payload_hash TEXT NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE,
            created_by_user_id INTEGER NOT NULL
        )'
    );
    $db->exec("INSERT INTO users (id, role, status) VALUES
        (1, 'admin', 'active'),
        (2, 'teacher', 'active'),
        (3, 'admin', 'inactive'),
        (4, 'teacher', 'inactive'),
        (5, 'teacher', 'active')");
    $db->exec("INSERT INTO user_role_assignments (id, user_id, role_key, status) VALUES
        (1, 5, 'admin', 'active'),
        (2, 2, 'admin', 'inactive')");
    $db->exec('INSERT INTO staff_profiles (user_id) VALUES (2), (4)');

    $repository = new PdoStaffCredentialRepository($db);
    $assert(
        $repository->actorCanManageCredentials(1)
        && $repository->actorCanManageCredentials(5)
        && !$repository->actorCanManageCredentials(2)
        && !$repository->actorCanManageCredentials(3),
        'credential administration resolves only active legacy or assigned admin authority'
    );
    $assert(
        $repository->isStaffUser(2) && !$repository->isStaffUser(5),
        'credential persistence permits a Staff profile, including a historically inactive account'
    );

    $payload = static fn (string $idempotencyKey, string $payloadHash, string $expiresOn): array => [
        'staff_user_id' => 2,
        'credential_kind' => 'document',
        'credential_key' => 'teaching-license',
        'title' => 'رخصة تدريس',
        'issuer' => null,
        'effective_on' => '2026-01-01',
        'issued_on' => '2026-01-01',
        'expires_on' => $expiresOn,
        'attachment_id' => null,
        'verification_status' => 'verified',
        'source' => 'manual',
        'payload_hash' => $payloadHash,
        'idempotency_key' => $idempotencyKey,
        'created_by_user_id' => 1,
    ];
    $first = $repository->transactional(static fn () => $repository->createCredential(
        $payload(str_repeat('a', 64), str_repeat('1', 64), '2026-08-14')
    ));
    $firstId = (int) $first['record']['id'];
    $replay = $repository->transactional(static fn () => $repository->createCredential(
        $payload(str_repeat('a', 64), str_repeat('1', 64), '2026-08-14')
    ));
    $contentReplay = $repository->transactional(static fn () => $repository->createCredential(
        $payload(str_repeat('b', 64), str_repeat('1', 64), '2026-08-14')
    ));
    $assert(
        ($first['replayed'] ?? true) === false
        && ($first['record']['version'] ?? null) === 1
        && ($replay['replayed'] ?? false) === true
        && ($contentReplay['replayed'] ?? false) === true
        && (int) ($contentReplay['record']['id'] ?? 0) === $firstId,
        'the adapter is idempotent for both retried tokens and an identical latest evidence payload'
    );
    $conflictRejected = false;
    try {
        $repository->transactional(static fn () => $repository->createCredential(
            $payload(str_repeat('a', 64), str_repeat('2', 64), '2026-08-15')
        ));
    } catch (DomainException) {
        $conflictRejected = true;
    }
    $assert($conflictRejected, 'an existing idempotency token cannot be remapped to new evidence');

    $replacement = $repository->transactional(static fn () => $repository->createCredential(
        $payload(str_repeat('c', 64), str_repeat('3', 64), '2026-08-20')
    ));
    $replacementId = (int) $replacement['record']['id'];
    $assert(
        ($replacement['record']['version'] ?? null) === 2
        && (int) ($replacement['record']['supersedes_id'] ?? 0) === $firstId,
        'a changed credential appends an immutable successor instead of altering the original evidence'
    );

    $insert = $db->prepare(
        'INSERT INTO staff_credential_records
            (staff_user_id, credential_kind, credential_key, title, issuer, effective_on, issued_on, expires_on,
             attachment_id, verification_status, lifecycle_status, supersedes_id, version, source, payload_hash,
             idempotency_key, created_by_user_id)
         VALUES (2, :kind, :key, :title, NULL, :effective, NULL, :expires, NULL, :verification, :lifecycle,
             NULL, 1, \'fixture\', :hash, :idempotency, 1)'
    );
    foreach ([
        ['training', 'first-aid', 'تدريب إسعافات', '2026-08-12', 'verified', 'active', '4', 'd'],
        ['document', 'rejected-document', 'وثيقة مرفوضة', '2026-08-10', 'rejected', 'active', '5', 'e'],
        ['document', 'revoked-document', 'وثيقة ملغاة', '2026-08-11', 'verified', 'revoked', '6', 'f'],
    ] as [$kind, $key, $title, $expires, $verification, $lifecycle, $hashSeed, $idempotencySeed]) {
        $insert->execute([
            ':kind' => $kind,
            ':key' => $key,
            ':title' => $title,
            ':effective' => '2026-01-01',
            ':expires' => $expires,
            ':verification' => $verification,
            ':lifecycle' => $lifecycle,
            ':hash' => str_repeat($hashSeed, 64),
            ':idempotency' => str_repeat($idempotencySeed, 64),
        ]);
    }
    $db->exec("INSERT INTO staff_credential_records
        (staff_user_id, credential_kind, credential_key, title, issuer, effective_on, issued_on, expires_on,
         attachment_id, verification_status, lifecycle_status, supersedes_id, version, source, payload_hash,
         idempotency_key, created_by_user_id)
        VALUES (4, 'document', 'inactive-worker', 'عامل غير نشط', NULL, '2026-01-01', NULL, '2026-08-09', NULL,
        'verified', 'active', NULL, 1, 'fixture', '" . str_repeat('7', 64) . "', '" . str_repeat('8', 64) . "', 1)");

    $expiring = $repository->expiringCredentials(
        new DateTimeImmutable('2026-08-09 00:00:00+03:00'),
        new DateTimeImmutable('2026-08-31 00:00:00+03:00'),
        10
    );
    $expiringIds = array_column($expiring, 'id');
    $assert(
        $expiringIds === [$firstId + 2, $replacementId]
        && !in_array($firstId, $expiringIds, true),
        'current expiry projection keeps only the newest active evidence and excludes rejected, revoked, or inactive-worker rows'
    );
    $assert(
        !array_key_exists('title', $expiring[0] ?? [])
        && !array_key_exists('attachment_id', $expiring[0] ?? []),
        'the persistence projection returns only fields required for safe expiry alerts'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR credential repository: PASS\n";
